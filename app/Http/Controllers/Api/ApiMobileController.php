<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Candidatures;
use App\Services\Communication\Courriel;
use App\Services\Communication\Sms;
use App\Services\Identite;
use App\Services\InscriptionEnLigne;
use App\Services\Licence;
use App\Services\Pv;
use App\Universites\UniversiteCourante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * API de l'application mobile (Flutter) — portage de api_mobile.php :
 * mêmes actions (?action=…), même format de réponse
 * {success, message, data}, jeton « Bearer » de 30 jours (mobile_tokens).
 *
 * Version reproduite : celle de pages/api/api_mobile.php, que l'application
 * du dépôt (MOBILE/) appelle — réinitialisation du mot de passe par code
 * envoyé par SMS ou e-mail (reset_password_demande puis reset_password).
 * La version à la racine (matricule + date de naissance suffisaient à
 * changer le mot de passe de n'importe qui) répond « mettez à jour
 * l'application » (HTTP 426), comme la version récente.
 *
 * Différences voulues :
 *  - jetons enregistrés sous forme d'empreinte (SHA-256) : une copie de la
 *    base ne donne plus des sessions utilisables ; jeton dans l'en-tête
 *    seulement (plus de ?token= dans l'adresse) ; anciens jetons acceptés
 *    jusqu'à leur expiration ;
 *  - résultats : PV finalisés ou publiés (Pv::CONSULTABLES, décision de
 *    l'université), jamais en brouillon (leurs résultats étaient visibles) ;
 *    réclamations : PV publiés seulement (le délai part de la publication) ;
 *  - photo servie par une adresse signée valable une heure (dossier public
 *    avant) ; cachet de l'université plus fourni (n'importe qui pouvait le
 *    télécharger pour fabriquer un faux) ;
 *  - « marquer lu » limité aux messages adressés à l'étudiant ; reçu réparé
 *    (la requête lisait une colonne inexistante et échouait) ; annonces
 *    ciblées par niveau prises en compte ; nouveaux mots de passe : 8
 *    caractères, lettres et chiffres ; messages en UTF-8 (la version racine,
 *    en Latin-1, produisait un JSON vide) ; licence vérifiée.
 *
 * Ajouts : inscription académique en ligne depuis l'application
 * (inscription_academique*), mêmes règles et même paiement KomiPay que le
 * portail (App\Services\InscriptionEnLigne), l'étudiant étant identifié par
 * son jeton (plus de matricule à saisir) ; reçu complété (matricule,
 * faculté, montant, moyen et référence du paiement en ligne).
 */
class ApiMobileController extends Controller
{
    private const ACTIONS = ['login', 'profil', 'inscriptions', 'resultats', 'annonces', 'messages', 'envoyer_message', 'marquer_lu',
        'statistiques', 'recu', 'check_inscription', 'carte', 'reset_password_demande', 'reset_password', 'reclamations_data',
        'matieres_pv', 'soumettre_reclamation',
        'inscription_academique', 'inscription_academique_demarrer', 'inscription_academique_payer', 'inscription_academique_statut'];

    private const PUBLIQUES = ['login', 'reset_password_demande', 'reset_password'];

    private ?object $etudiant = null;

    public function __construct(private readonly UniversiteCourante $universite) {}

    public function __invoke(Request $request, Licence $licence): JsonResponse
    {
        if ($request->isMethod('options')) {
            return $this->cors(response()->json(null));
        }
        $action = (string) ($request->query('action') ?? $request->input('action', ''));
        if (! in_array($action, self::ACTIONS, true)) {
            return $this->erreur('Action inconnue', 404);
        }
        if (! $licence->verifierInstallation()['valide']) {
            return $this->erreur("Service indisponible : licence de l'établissement invalide. Contactez la scolarité.", 503);
        }
        if (! in_array($action, self::PUBLIQUES, true)) {
            $this->etudiant = $this->authentifier($request);
            if (! $this->etudiant) {
                return $this->erreur($request->bearerToken() ? 'Session expirée, veuillez vous reconnecter' : 'Token manquant', 401);
            }
        }
        try {
            return $this->cors($this->{lcfirst(str_replace('_', '', ucwords($action, '_')))}($request));
        } catch (Throwable $e) {
            Log::error('api_mobile', ['action' => $action, 'erreur' => $e->getMessage()]);

            return $this->erreur('Erreur interne. Réessayez plus tard.', 500);
        }
    }

    // ── Authentification ──────────────────────────────────────

    private function login(Request $request): JsonResponse
    {
        $identifiant = mb_strtoupper(trim((string) $request->input('identifiant')));
        $motDePasse = trim((string) $request->input('password'));
        if ($identifiant === '' || $motDePasse === '') {
            return $this->erreur('Identifiant et mot de passe requis');
        }
        if ($bloque = $this->bloque($request, 'login', $identifiant)) {
            return $bloque;
        }
        $et = $this->etudiantPar($identifiant);
        if (! $et) {
            return $this->echec($request, 'login', $identifiant, 'Identifiant introuvable. Vérifiez votre matricule ou N° dossier.');
        }
        if (! $et->password_hash) {
            // Premier accès : la date de naissance est exigée en plus du matricule
            $date = trim((string) $request->input('date_naissance'));
            if ($date === '') {
                return $this->erreur('Date de naissance requise pour votre première connexion.');
            }
            if (! $et->date_naissance) {
                return $this->erreur("Votre date de naissance n'est pas enregistrée. Contactez la scolarité.");
            }
            if (strtotime($date) === false || date('Y-m-d', strtotime($date)) !== date('Y-m-d', strtotime($et->date_naissance))) {
                return $this->echec($request, 'login', $identifiant, 'Date de naissance incorrecte.');
            }
            if ($e = $this->motDePasseFaible($motDePasse)) {
                return $this->erreur($e);
            }
            DB::table('etudiants')->where('id', $et->id)->update(['password_hash' => Hash::make($motDePasse)]);
        } elseif (! Hash::check($motDePasse, $et->password_hash)) {
            return $this->echec($request, 'login', $identifiant, 'Mot de passe incorrect');
        }
        $jeton = bin2hex(random_bytes(32));
        DB::table('mobile_tokens')->insert(['etudiant_id' => $et->id, 'token' => hash('sha256', $jeton),
            'device_info' => mb_substr(trim((string) $request->input('device', 'Flutter')), 0, 200), 'expires_at' => now()->addDays(30), 'created_at' => now()]);
        DB::table('etudiants')->where('id', $et->id)->update(['last_login' => now()]);
        RateLimiter::clear($this->cle($request, 'login', $identifiant));
        unset($et->password_hash);

        return $this->ok(['token' => $jeton, 'etudiant' => $et], 'Connexion réussie');
    }

    private function resetPasswordDemande(Request $request): JsonResponse
    {
        $identifiant = mb_strtoupper(trim((string) $request->input('identifiant')));
        if ($identifiant === '') {
            return $this->erreur('Matricule requis');
        }
        if ($bloque = $this->bloque($request, 'reset_demande', $identifiant)) {
            return $bloque;
        }
        RateLimiter::hit($this->cle($request, 'reset_demande', $identifiant), 300);
        // Même réponse que le matricule existe ou non
        $generique = "Si ce matricule existe et qu'un téléphone ou un email est enregistré, un code de vérification vient d'être envoyé. Il est valable 15 minutes.";
        $et = $this->etudiantPar($identifiant);
        if (! $et || DB::table('mobile_reset_codes')->where('etudiant_id', $et->id)->where('created_at', '>', now()->subMinute())->exists()) {
            return $this->ok([], $generique);
        }
        $code = (string) random_int(100000, 999999);
        DB::table('mobile_reset_codes')->where(['etudiant_id' => $et->id, 'used' => 0])->update(['used' => 1]);
        DB::table('mobile_reset_codes')->insert(['etudiant_id' => $et->id, 'code_hash' => $this->empreinteCode((int) $et->id, $code),
            'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
        $texte = "Votre code de réinitialisation {$this->sigle()} : $code (valable 15 min). Ne le communiquez à personne.";
        $envoye = false;
        try {
            if ($et->telephone) {
                $envoye = app(Sms::class)->envoyer($et->telephone, $texte, 'reset_mobile', (int) $et->id) || $envoye;
            }
            if ($et->email) {
                $envoye = app(Courriel::class)->envoyer($et->email, 'Code de réinitialisation', '<p>'.e($texte).'</p>', 'reset_mobile', (int) $et->id) || $envoye;
            }
        } catch (Throwable $e) {
            Log::warning('api_mobile : envoi du code de réinitialisation impossible', ['etudiant' => $et->id, 'erreur' => $e->getMessage()]);
        }
        if (! $envoye) {
            Log::warning('api_mobile : aucun canal pour le code de réinitialisation', ['etudiant' => $et->id]);
        }

        return $this->ok([], $generique.' Si vous ne recevez rien, contactez la scolarité.');
    }

    private function resetPassword(Request $request): JsonResponse
    {
        $identifiant = mb_strtoupper(trim((string) $request->input('identifiant')));
        $code = trim((string) $request->input('code'));
        $nouveau = trim((string) $request->input('nouveau_mdp'));
        if ($identifiant !== '' && $code === '' && $request->filled('date_naissance')) {
            return $this->erreur("Veuillez mettre à jour l'application : la réinitialisation se fait désormais par code SMS/email.", 426);
        }
        if ($identifiant === '' || $code === '' || $nouveau === '') {
            return $this->erreur('Tous les champs sont requis');
        }
        if ($bloque = $this->bloque($request, 'reset_password', $identifiant)) {
            return $bloque;
        }
        if ($e = $this->motDePasseFaible($nouveau)) {
            return $this->erreur($e);
        }
        $invalide = 'Code invalide ou expiré. Demandez un nouveau code.';
        $et = $this->etudiantPar($identifiant);
        $ligne = $et ? DB::table('mobile_reset_codes')->where(['etudiant_id' => $et->id, 'used' => 0])->where('expires_at', '>', now())->orderByDesc('id')->first() : null;
        if (! $ligne || $ligne->tentatives >= 5) {
            return $this->echec($request, 'reset_password', $identifiant, $invalide);
        }
        if (! hash_equals($ligne->code_hash, $this->empreinteCode((int) $et->id, $code))) {
            DB::table('mobile_reset_codes')->where('id', $ligne->id)->increment('tentatives');

            return $this->echec($request, 'reset_password', $identifiant, $invalide);
        }
        DB::transaction(function () use ($ligne, $et, $nouveau) {
            DB::table('mobile_reset_codes')->where('id', $ligne->id)->update(['used' => 1]);
            DB::table('etudiants')->where('id', $et->id)->update(['password_hash' => Hash::make($nouveau)]);
            DB::table('mobile_tokens')->where('etudiant_id', $et->id)->delete();
        });
        RateLimiter::clear($this->cle($request, 'reset_password', $identifiant));

        return $this->ok([], 'Mot de passe réinitialisé avec succès. Connectez-vous avec votre nouveau mot de passe.');
    }

    // ── Données de l'étudiant ─────────────────────────────────

    private function profil(): JsonResponse
    {
        $et = clone $this->etudiant;
        $et->photo_url = $this->urlPhoto($et);

        return $this->ok($et);
    }

    private function inscriptions(): JsonResponse
    {
        return $this->ok(DB::table('inscriptions as i')->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')->leftJoin('annees_academiques as a', 'a.id', '=', 'i.annee_id')
            ->where('i.etudiant_id', $this->etudiant->id)->orderByDesc('a.libelle')
            ->get(['i.*', 'n.code as niveau_code', 'n.libelle as niveau_lib', 'a.libelle as annee', 'a.active']));
    }

    private function resultats(Request $request): JsonResponse
    {
        $id = $this->etudiant->id;
        $anneeId = (int) $request->query('annee_id');
        $lignes = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('niveaux as nv', 'nv.id', '=', 'p.niveau_id')->join('unites_enseignement as ue', 'ue.promotion_id', '=', 'p.id')
            ->join('semestres as s', 's.id', '=', 'ue.semestre_id')->join('matieres as m', fn ($j) => $j->on('m.ue_id', '=', 'ue.id')->where('m.actif', 1))
            ->leftJoin('pv_sessions as ps1', fn ($j) => $j->on('ps1.promotion_id', '=', 'p.id')->on('ps1.semestre_id', '=', 'ue.semestre_id')
                ->where('ps1.type_session', 'normale')->whereIn('ps1.statut', Pv::CONSULTABLES))
            ->leftJoin('notes as n1', fn ($j) => $j->on('n1.pv_id', '=', 'ps1.id')->on('n1.matiere_id', '=', 'm.id')->where('n1.etudiant_id', $id))
            ->leftJoin('pv_sessions as ps2', fn ($j) => $j->on('ps2.pv_normale_id', '=', 'ps1.id')->where('ps2.type_session', 'rattrapage')->whereIn('ps2.statut', Pv::CONSULTABLES))
            ->leftJoin('notes as n2', fn ($j) => $j->on('n2.pv_id', '=', 'ps2.id')->on('n2.matiere_id', '=', 'm.id')->where('n2.etudiant_id', $id))
            ->where('i.etudiant_id', $id)->where('i.statut', 'inscrit')->when($anneeId, fn ($q) => $q->where('a.id', $anneeId))
            ->orderByDesc('a.libelle')->orderBy('s.code')->orderBy('ue.ordre')->orderBy('m.ordre')
            ->get(['m.libelle as matiere', 'm.coefficient', 'm.note_max_totale', 'm.note_max_cc', 'm.note_max_examen', 'ue.code as ue_code', 'ue.libelle as ue_lib',
                'ue.credits_total', 's.code as sem_code', 's.libelle as semestre', 'a.libelle as annee', 'a.id as annee_id', 'nv.code as niveau',
                'n1.note_cc as note_cc_s1', 'n1.note_examen as note_examen_s1', 'n1.note_finale as note_finale_s1', 'n1.observation as obs_s1', 'n1.defaillant as defaillant_s1',
                'n2.note_cc as note_cc_s2', 'n2.note_examen as note_examen_s2', 'n2.note_finale as note_finale_s2', 'n2.observation as obs_s2', 'n2.defaillant as defaillant_s2',
                DB::raw('COALESCE(ps2.type_session, ps1.type_session) AS session_active'), 'ps1.id as pv_normale_id', 'ps2.id as pv_ratt_id']);
        // Note retenue : rattrapage s'il existe, sinon session normale (notes de 1ʳᵉ session gardées pour comparaison)
        $notes = $lignes->map(function ($n) {
            $ratt = $n->note_finale_s2 !== null || $n->obs_s2 !== null;
            foreach (['note_cc', 'note_examen', 'note_finale', 'defaillant'] as $c) {
                $n->$c = $ratt ? $n->{$c.'_s2'} : $n->{$c.'_s1'};
            }
            $n->observation = $ratt ? $n->obs_s2 : $n->obs_s1;
            $n->has_rattrapage = $ratt ? 1 : 0;

            return $n;
        });
        $resultats = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('niveaux as nv', 'nv.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->where('rs.etudiant_id', $id)->whereIn('ps.statut', Pv::CONSULTABLES)->orderByDesc('a.libelle')->orderBy('s.code')
            ->get(['rs.valide', 'rs.mention', 'rs.moyenne_generale', 'rs.rang', 'a.libelle as annee', 'a.id as annee_id', 'nv.code as niveau', 's.libelle as semestre', 'ps.type_session']);

        return $this->ok(['notes' => $notes, 'resultats' => $resultats]);
    }

    private function annonces(): JsonResponse
    {
        $annonces = $this->requeteAnnonces()->orderByDesc('epinglee')->orderByDesc('created_at')->limit(50)
            ->get(['id', 'titre', 'contenu', 'type', 'epinglee', 'date_debut', 'date_fin', 'vues', 'created_at']);
        if ($annonces->isNotEmpty()) {
            DB::table('annonces')->whereIn('id', $annonces->pluck('id'))->increment('vues');
        }

        return $this->ok($annonces);
    }

    private function messages(): JsonResponse
    {
        $id = $this->etudiant->id;

        return $this->ok(DB::table('messages as m')
            ->leftJoin('etudiants as e', fn ($j) => $j->on('e.id', '=', 'm.expediteur_id')->where('m.expediteur_type', 'etudiant'))
            ->where(fn ($w) => $w->where(['m.destinataire_type' => 'etudiant', 'm.destinataire_id' => $id])->orWhere(fn ($x) => $x->where(['m.expediteur_type' => 'etudiant', 'm.expediteur_id' => $id])))
            ->orderByDesc('m.created_at')->limit(30)
            ->get(['m.id', 'm.sujet', 'm.corps', 'm.lu', 'm.created_at', 'm.expediteur_type',
                DB::raw("CASE WHEN m.expediteur_type = 'admin' THEN ".DB::getPdo()->quote('Scolarité '.$this->sigle())." ELSE CONCAT(e.prenoms, ' ', e.nom) END AS expediteur")]));
    }

    private function envoyerMessage(Request $request): JsonResponse
    {
        $sujet = trim((string) $request->input('sujet'));
        $corps = trim((string) $request->input('corps'));
        if ($sujet === '' || $corps === '') {
            return $this->erreur('Sujet et message requis');
        }
        $cle = 'api_mobile_message|'.$this->universite->get()->code.'|'.$this->etudiant->id;
        if (RateLimiter::tooManyAttempts($cle, 10)) {
            return $this->erreur('Trop de messages envoyés. Réessayez plus tard.', 429);
        }
        $admin = DB::table('utilisateurs')->whereIn('role', ['admin', 'secretaire'])->where('actif', 1)->orderByRaw("role = 'admin' DESC")->orderBy('id')->value('id');
        if (! $admin) {
            return $this->erreur('Aucun administrateur disponible');
        }
        RateLimiter::hit($cle, 3600);
        DB::table('messages')->insert(['expediteur_type' => 'etudiant', 'expediteur_id' => $this->etudiant->id, 'destinataire_type' => 'admin',
            'destinataire_id' => $admin, 'sujet' => mb_substr($sujet, 0, 200), 'corps' => mb_substr($corps, 0, 5000), 'created_at' => now()]);

        return $this->ok([], 'Message envoyé à la scolarité');
    }

    private function marquerLu(Request $request): JsonResponse
    {
        DB::table('messages')->where(['id' => (int) $request->input('message_id'), 'destinataire_type' => 'etudiant', 'destinataire_id' => $this->etudiant->id])
            ->update(['lu' => 1, 'lu_le' => now()]);

        return $this->ok([], 'Message marqué comme lu');
    }

    private function statistiques(): JsonResponse
    {
        return $this->ok([
            'messages_non_lus' => DB::table('messages')->where(['destinataire_type' => 'etudiant', 'destinataire_id' => $this->etudiant->id, 'lu' => 0])->count(),
            'nb_annonces' => $this->requeteAnnonces()->count(),
        ]);
    }

    private function recu(Request $request): JsonResponse
    {
        // Champs attendus par l'écran du reçu (montant, matricule, faculté, paiement) ; paiement en ligne s'il y en a eu un
        $recu = DB::table('inscriptions as i')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')
            ->leftJoin('promotions as p', 'p.id', '=', 'i.promotion_id')->leftJoin('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')->leftJoin('annees_academiques as a', 'a.id', '=', 'i.annee_id')
            ->leftJoin('inscriptions_en_ligne as il', fn ($j) => $j->on('il.etudiant_id', '=', 'i.etudiant_id')->on('il.annee_id', '=', 'i.annee_id')->where('il.statut', 'complete'))
            ->where(['i.id' => (int) $request->query('ins_id'), 'i.etudiant_id' => $this->etudiant->id])
            ->first(['i.*', 'f.libelle as filiere', 'f.faculte', 'n.code as niveau', 'a.libelle as annee', 'e.numero as matricule',
                'i.montant_paye as montant', DB::raw('COALESCE(il.date_paiement, i.date_inscription) AS date_paiement'),
                DB::raw('COALESCE(il.moyen_paiement, i.mode_paiement) AS moyen_paiement'), 'il.reference_paiement', 'il.numero_dossier',
                DB::raw('COALESCE(i.recu_numero, il.recu_numero) AS recu_numero')]);

        return $recu ? $this->ok($recu) : $this->erreur('Reçu introuvable');
    }

    private function checkInscription(): JsonResponse
    {
        $annee = DB::table('annees_academiques')->where('active', 1)->value('id');

        return $this->ok([
            'annee_id' => $annee, 'filiere' => $this->etudiant->filiere ?? null, 'filiere_id' => $this->etudiant->filiere_id ?? null,
            'deja_inscrit' => DB::table('inscriptions')->where(['etudiant_id' => $this->etudiant->id, 'annee_id' => $annee, 'statut' => 'inscrit'])->exists(),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(),
        ]);
    }

    private function carte(): JsonResponse
    {
        $inscription = DB::table('inscriptions as i')->leftJoin('niveaux as nv', 'nv.id', '=', 'i.niveau_id')->leftJoin('annees_academiques as a', 'a.id', '=', 'i.annee_id')
            ->leftJoin('filieres as f', 'f.id', '=', DB::raw((int) ($this->etudiant->filiere_id ?? 0)))
            ->where(['i.etudiant_id' => $this->etudiant->id, 'i.statut' => 'inscrit'])->orderByDesc('a.libelle')
            ->first(['i.*', 'nv.code as niveau_code', 'nv.libelle as niveau_lib', 'a.libelle as annee', 'a.id as annee_id', 'f.libelle as filiere', 'f.faculte']);

        return $this->ok(['etudiant' => $this->etudiant, 'inscription' => $inscription, 'photo_url' => $this->urlPhoto($this->etudiant),
            'logo_url' => app(Identite::class)->logos()['gauche'], 'cachet_url' => null]);
    }

    // ── Inscription académique en ligne ───────────────────────

    /** État de l'inscription de l'année active : ouverte, déjà inscrit, inscription en ligne en cours, moyens de paiement. */
    private function inscriptionAcademique(): JsonResponse
    {
        return $this->ok($this->etatInscription());
    }

    /** Ouvre (ou reprend) l'inscription en ligne de l'étudiant connecté : mêmes règles que le portail. */
    private function inscriptionAcademiqueDemarrer(): JsonResponse
    {
        $service = app(InscriptionEnLigne::class);
        if (! $service->ouverte()) {
            return $this->erreur(app(Candidatures::class)->config('message_inscription_fermee', 'Les inscriptions académiques sont actuellement fermées.'));
        }
        $et = $this->etudiant;
        $identifiant = $et->numero && $et->numero !== '0' ? (string) $et->numero : (string) ($et->numero_dossier ?: $et->numero_depot);
        if ($identifiant === '') {
            return $this->erreur("Votre dossier n'a ni matricule ni numéro de dossier : contactez la scolarité.");
        }
        $r = $service->identifier($identifiant, $this->sigle(), null);
        if (isset($r['erreur'])) {
            return $this->erreur($r['erreur']);
        }
        $id = isset($r['deja']) ? (int) $r['deja']->id : (int) $r['inscription'];
        if ((int) ($service->trouver($id)->etudiant_id ?? 0) !== (int) $et->id) {
            return $this->erreur('Inscription introuvable. Contactez la scolarité.', 403);
        }

        return $this->ok($this->etatInscription(), 'Inscription ouverte');
    }

    private function inscriptionAcademiquePayer(Request $request): JsonResponse
    {
        $ins = $this->monInscriptionEnLigne((int) $request->input('id'));
        if (! $ins) {
            return $this->erreur('Inscription introuvable', 404);
        }
        $cle = 'api_mobile_paiement|'.$this->universite->get()->code.'|'.$this->etudiant->id;
        if (RateLimiter::tooManyAttempts($cle, 5)) {
            return $this->erreur('Trop de tentatives de paiement. Réessayez dans '.(int) ceil(RateLimiter::availableIn($cle) / 60).' minute(s).', 429);
        }
        RateLimiter::hit($cle, 600);
        $r = app(InscriptionEnLigne::class)->lancerPaiement($ins, (string) $request->input('moyen'), (string) $request->input('telephone'));

        return $r['ok'] ? $this->ok($this->etatInscription(), 'Paiement lancé : validez-le sur votre téléphone.') : $this->erreur($r['message']);
    }

    /** Suivi du paiement ; paiement validé (vérifié auprès de KomiPay) → inscription finalisée, reçu disponible. */
    private function inscriptionAcademiqueStatut(Request $request): JsonResponse
    {
        $ins = $this->monInscriptionEnLigne((int) $request->query('id'));
        if (! $ins) {
            return $this->erreur('Inscription introuvable', 404);
        }
        $cle = 'api_mobile_statut|'.$this->universite->get()->code.'|'.$this->etudiant->id;
        if (RateLimiter::tooManyAttempts($cle, 30)) {
            return $this->erreur('Trop de vérifications. Patientez une minute.', 429);
        }
        RateLimiter::hit($cle, 60);
        $service = app(InscriptionEnLigne::class);
        $statut = $ins->statut === 'complete' ? 'valide' : $service->suivre($ins);
        $message = null;
        if ($statut === 'valide' && $ins->statut !== 'complete') {
            $r = $service->confirmer($service->trouver((int) $ins->id));
            if (! $r['ok']) {
                $statut = ($r['recommencer'] ?? false) ? 'echec' : 'en_attente';
                $message = $r['message'];
            }
        }

        return $this->ok(['statut' => $statut, 'message' => $message ?? match ($statut) {
            'valide' => 'Paiement validé : vous êtes inscrit(e).',
            'expire' => 'Le délai de paiement ('.config('komipay.expiration_minutes').' minutes) a expiré. Recommencez le paiement.',
            'echec' => 'Le paiement a échoué ou a été refusé. Vous pouvez recommencer.',
            default => 'Paiement en attente de validation sur votre téléphone.',
        }] + $this->etatInscription());
    }

    private function etatInscription(): array
    {
        $service = app(InscriptionEnLigne::class);
        $annee = $service->anneeActive();
        $et = $this->etudiant;
        $inscription = $annee ? DB::table('inscriptions')->where(['etudiant_id' => $et->id, 'annee_id' => $annee->id, 'statut' => 'inscrit'])->value('id') : null;
        $enLigne = $annee ? DB::table('inscriptions_en_ligne')->where(['etudiant_id' => $et->id, 'annee_id' => $annee->id])
            ->orderByRaw("statut = 'complete' DESC")->orderByDesc('id')->value('id') : null;
        $ins = $enLigne ? $service->trouver((int) $enLigne) : null;

        return [
            'ouverte' => $service->ouverte(),
            'annee' => $annee?->libelle,
            'inscrit' => (bool) $inscription,
            'inscription_id' => $inscription ? (int) $inscription : null,
            // La carte bancaire passe par la page de sa banque (3-D Secure) : sur le portail web seulement
            'moyens' => array_diff_key($service->moyens(), ['visa_master' => true]),
            'carte_sur_le_web' => array_key_exists('visa_master', $service->moyens()),
            'expiration_minutes' => (int) config('komipay.expiration_minutes'),
            'telephone' => $et->telephone ?? null,
            'en_ligne' => $ins ? [
                'id' => (int) $ins->id, 'statut' => $ins->statut, 'statut_paiement' => $ins->statut_paiement, 'expire' => $service->expire($ins),
                'filiere' => $ins->filiere_lib, 'faculte' => $ins->faculte, 'niveau_code' => $ins->niveau_code, 'niveau' => $ins->niveau_lib, 'annee' => $ins->annee_lib,
                'montant' => $service->montant((float) $ins->montant), 'moyen_paiement' => $ins->reference_paiement ? $ins->moyen_paiement : null,
                'telephone_paiement' => $ins->telephone_paiement, 'code_achat' => $ins->code_achat_komipay, 'matricule' => $ins->matricule, 'recu_numero' => $ins->recu_numero,
            ] : null,
        ];
    }

    /** Inscription en ligne de l'étudiant connecté (jamais celle d'un autre). */
    private function monInscriptionEnLigne(int $id): ?object
    {
        $ins = $id ? app(InscriptionEnLigne::class)->trouver($id) : null;

        return $ins && (int) $ins->etudiant_id === (int) $this->etudiant->id ? $ins : null;
    }

    // ── Réclamations ──────────────────────────────────────────

    private function reclamationsData(): JsonResponse
    {
        $id = $this->etudiant->id;
        $pv = DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->leftJoin('pv_publications as pp', 'pp.pv_id', '=', 'ps.id')
            ->where('ps.statut', 'publie')->where('a.active', 1)
            ->whereExists(fn ($q) => $this->appartenance($q, $id))
            ->orderByDesc('a.libelle')->orderBy('s.code')
            ->get(['ps.id', 'ps.libelle', 'ps.type_session', 's.libelle as semestre', 'a.libelle as annee', DB::raw(Pv::PUBLIE_LE_SQL.' AS publie_le'),
                DB::raw('GREATEST(0, '.(Pv::DELAI_RECLAMATION_HEURES * 3600).' - TIMESTAMPDIFF(SECOND, '.Pv::PUBLIE_LE_SQL.', NOW())) AS secondes_restantes')])
            ->map(function ($pv) {
                $pv->reclamation_ouverte = $pv->secondes_restantes > 0 ? 1 : 0;
                $pv->heures_restantes = max(0, round($pv->secondes_restantes / 3600, 1));

                return $pv;
            });

        return $this->ok(['pv_list' => $pv, 'mes_reclamations' => DB::table('reclamations as r')->join('matieres as m', 'm.id', '=', 'r.matiere_id')
            ->join('pv_sessions as ps', 'ps.id', '=', 'r.pv_id')->where('r.etudiant_id', $id)->orderByDesc('r.created_at')
            ->get(['r.*', 'm.libelle as matiere', 'ps.libelle as pv_libelle'])]);
    }

    private function matieresPv(Request $request): JsonResponse
    {
        $pvId = (int) $request->query('pv_id');
        if (! $pvId) {
            return $this->erreur('pv_id requis');
        }
        $id = $this->etudiant->id;
        if (! $this->pvDeLEtudiant($pvId)) {
            return $this->erreur('PV introuvable ou non autorisé', 403);
        }

        return $this->ok(DB::table('pv_sessions as ps')->join('unites_enseignement as ue', fn ($j) => $j->on('ue.promotion_id', '=', 'ps.promotion_id')->on('ue.semestre_id', '=', 'ps.semestre_id'))
            ->join('matieres as m', fn ($j) => $j->on('m.ue_id', '=', 'ue.id')->where('m.actif', 1))
            ->leftJoin('notes as n', fn ($j) => $j->on('n.pv_id', '=', 'ps.id')->on('n.matiere_id', '=', 'm.id')->where('n.etudiant_id', $id))
            ->where('ps.id', $pvId)
            ->whereNotExists(fn ($q) => $q->from('reclamations as r')->whereColumn('r.matiere_id', 'm.id')->where(['r.etudiant_id' => $id, 'r.pv_id' => $pvId]))
            ->orderBy('ue.ordre')->orderBy('m.ordre')
            ->get(['m.id', 'm.libelle', 'm.coefficient', 'm.note_max_examen', 'm.note_max_cc', 'n.note_examen', 'n.note_cc', 'n.note_finale', DB::raw('0 AS deja_reclame')]));
    }

    private function soumettreReclamation(Request $request): JsonResponse
    {
        $pvId = (int) $request->input('pv_id');
        $matiere = (int) $request->input('matiere_id');
        $motif = trim((string) $request->input('motif'));
        if (! $pvId) {
            return $this->erreur('pv_id requis');
        }
        if (! $matiere) {
            return $this->erreur('matiere_id requis');
        }
        if (mb_strlen($motif) < 20) {
            return $this->erreur('Le motif doit contenir au moins 20 caractères');
        }
        $pv = DB::table('pv_sessions as ps')->leftJoin('pv_publications as pp', 'pp.pv_id', '=', 'ps.id')->where('ps.id', $pvId)
            ->first(['ps.statut', DB::raw('TIMESTAMPDIFF(SECOND, '.Pv::PUBLIE_LE_SQL.', NOW()) AS secondes')]);
        if (! $pv || $pv->statut !== 'publie') {
            return $this->erreur("Ce PV n'est pas disponible pour les réclamations");
        }
        if ((int) $pv->secondes > Pv::DELAI_RECLAMATION_HEURES * 3600) {
            return $this->erreur('Le délai de réclamation de '.Pv::DELAI_RECLAMATION_HEURES.' heures est expiré pour ce PV');
        }
        $matiereDuPv = DB::table('pv_sessions as ps')->join('unites_enseignement as ue', fn ($j) => $j->on('ue.promotion_id', '=', 'ps.promotion_id')->on('ue.semestre_id', '=', 'ps.semestre_id'))
            ->join('matieres as m', 'm.ue_id', '=', 'ue.id')->where(['ps.id' => $pvId, 'm.id' => $matiere, 'm.actif' => 1])->exists();
        if (! $matiereDuPv || ! $this->pvDeLEtudiant($pvId)) {
            return $this->erreur('Ce PV ou cette matière ne correspond pas à votre parcours', 403);
        }
        if (DB::table('reclamations')->where(['etudiant_id' => $this->etudiant->id, 'pv_id' => $pvId, 'matiere_id' => $matiere])->exists()) {
            return $this->erreur('Vous avez déjà déposé une réclamation pour cette matière dans ce PV');
        }
        $id = DB::table('reclamations')->insertGetId(['etudiant_id' => $this->etudiant->id, 'pv_id' => $pvId, 'matiere_id' => $matiere,
            'motif' => mb_substr($motif, 0, 5000), 'statut' => 'en_attente', 'source' => 'enligne',
            'note_originale' => DB::table('notes')->where(['pv_id' => $pvId, 'etudiant_id' => $this->etudiant->id, 'matiere_id' => $matiere])->value('note_finale')]);

        return $this->ok(['id' => $id], 'Réclamation soumise avec succès');
    }

    // ── Outils ────────────────────────────────────────────────

    /** Étudiant du jeton (empreinte, ou ancien jeton en clair jusqu'à son expiration). */
    private function authentifier(Request $request): ?object
    {
        $jeton = (string) $request->bearerToken();
        if ($jeton === '' || strlen($jeton) > 128) {
            return null;
        }
        $ligne = DB::table('mobile_tokens')->whereIn('token', [hash('sha256', $jeton), $jeton])->where('expires_at', '>', now())->first(['id']);
        if (! $ligne) {
            return null;
        }
        DB::table('mobile_tokens')->where('id', $ligne->id)->update(['last_used' => now()]);
        $et = DB::table('mobile_tokens as mt')->join('etudiants as e', 'e.id', '=', 'mt.etudiant_id')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->where('mt.id', $ligne->id)->first(['e.*', 'f.libelle as filiere', 'f.faculte', 'mt.expires_at']);
        if ($et) {
            unset($et->password_hash);
        }

        return $et;
    }

    private function etudiantPar(string $identifiant): ?object
    {
        return DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->where(fn ($w) => $w->where('e.numero', $identifiant)->orWhere('e.numero_dossier', $identifiant))
            ->first(['e.*', 'f.libelle as filiere', 'f.faculte']);
    }

    /** Annonces publiées et en cours pour l'étudiant (tous, étudiants, sa filière, son niveau actuel). */
    private function requeteAnnonces()
    {
        $niveau = DB::table('inscriptions as i')->join('annees_academiques as a', 'a.id', '=', 'i.annee_id')
            ->where(['i.etudiant_id' => $this->etudiant->id, 'i.statut' => 'inscrit', 'a.active' => 1])->value('i.niveau_id');

        return DB::table('annonces')->where('publiee', 1)->whereDate('date_debut', '<=', now())
            ->where(fn ($w) => $w->whereNull('date_fin')->orWhereDate('date_fin', '>=', now()))
            ->where(fn ($w) => $w->whereIn('cible', ['tous', 'etudiants'])
                ->orWhere(fn ($f) => $f->where('cible', 'filiere')->where('filiere_id', (int) ($this->etudiant->filiere_id ?? 0)))
                ->orWhere(fn ($n) => $n->where('cible', 'niveau')->where('niveau_id', (int) ($niveau ?? 0))));
    }

    /** L'étudiant est inscrit pédagogiquement au semestre du PV, dans la même filière et le même niveau. */
    private function appartenance($q, int $etudiant)
    {
        return $q->from('inscriptions_pedagogiques as ip')->join('inscriptions as i', 'i.id', '=', 'ip.inscription_id')->join('promotions as pe', 'pe.id', '=', 'i.promotion_id')
            ->whereColumn('ip.semestre_id', 'ps.semestre_id')->whereColumn('ip.annee_id', 'p.annee_id')->whereColumn('pe.filiere_id', 'p.filiere_id')
            ->whereColumn('pe.niveau_id', 'p.niveau_id')->where(['ip.etudiant_id' => $etudiant, 'ip.statut' => 'actif']);
    }

    private function pvDeLEtudiant(int $pvId): bool
    {
        return DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->where('ps.id', $pvId)->where('ps.statut', 'publie')
            ->whereExists(fn ($q) => $this->appartenance($q, (int) $this->etudiant->id))->exists();
    }

    /** Photo de l'étudiant : adresse signée valable une heure (les photos ne sont plus publiques). */
    private function urlPhoto(object $et): ?string
    {
        return $et->photo ? URL::temporarySignedRoute('api_mobile.photo', now()->addHour(), ['etudiant' => $et->id]) : null;
    }

    private function motDePasseFaible(string $mdp): ?string
    {
        $v = Validator::make(['mdp' => $mdp], ['mdp' => [Password::min(8)->letters()->numbers()]], [], ['mdp' => 'mot de passe']);

        return $v->fails() ? $v->errors()->first() : null;
    }

    private function empreinteCode(int $etudiant, string $code): string
    {
        return hash('sha256', $etudiant.'|'.$code);
    }

    private function cle(Request $request, string $action, string $identifiant): string
    {
        return 'api_mobile|'.$this->universite->get()->code.'|'.$action.'|'.$request->ip().'|'.$identifiant;
    }

    /** 5 essais puis 5 minutes d'attente, par adresse IP, action et identifiant (comme avant). */
    private function bloque(Request $request, string $action, string $identifiant): ?JsonResponse
    {
        $cle = $this->cle($request, $action, $identifiant);
        if (RateLimiter::tooManyAttempts($cle, 5)) {
            return $this->erreur('Trop de tentatives. Réessayez dans '.(int) ceil(RateLimiter::availableIn($cle) / 60).' minute(s).', 429);
        }

        return null;
    }

    private function echec(Request $request, string $action, string $identifiant, string $message): JsonResponse
    {
        RateLimiter::hit($this->cle($request, $action, $identifiant), 300);

        return $this->erreur($message);
    }

    private function sigle(): string
    {
        $u = $this->universite->get();

        return $u->sigle ?: strtoupper($u->code);
    }

    private function ok(mixed $data = [], string $message = 'OK'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function erreur(string $message, int $code = 400): JsonResponse
    {
        return $this->cors(response()->json(['success' => false, 'message' => $message, 'data' => null], $code, [], JSON_UNESCAPED_UNICODE));
    }

    private function cors(JsonResponse $r): JsonResponse
    {
        $r->headers->set('Access-Control-Allow-Origin', '*');
        $r->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $r->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');

        return $r;
    }
}
