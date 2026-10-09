<?php

namespace App\Http\Controllers\Candidature;

use App\Http\Controllers\Controller;
use App\Models\Candidat;
use App\Services\Candidatures;
use App\Services\Fichiers;
use App\Services\Identite;
use App\Services\Journal;
use App\Services\Parametres;
use App\Support\Classeur;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Administration des candidatures (admin, secrétaire, sc_centrale) —
 * portage de pages/admin/portail_candidats_admin.php, dossier_candidat.php
 * et portail_export.php ; remplace aussi la page d'administration du
 * portail lui-même (candidature/pages/admin.php et admin_export.php),
 * qui s'ouvrait avec un mot de passe unique partagé.
 *
 * Différences voulues :
 *  - statut contrôlé (n'importe quelle valeur était envoyée en base),
 *    actions journalisées ;
 *  - « Créer l'étudiant » : une seule fiche par dossier (un double clic en
 *    créait deux), matricule laissé vide et non « 0 » (le second échouait
 *    sur l'index unique), statut, année du bac et n° de dépôt valides — la
 *    création échouait (statut « nouveau » hors liste, année « 2024-2025 »
 *    dans une colonne année) ;
 *  - dépôt par l'administration : mot de passe provisoire affiché une seule
 *    fois, plus enregistré en clair dans la note du dossier ; pièces
 *    vérifiées par leur contenu ; filières actives ;
 *  - pièces et photo servies par l'application au personnel (dossier
 *    public avant) ; quitus ouvert depuis GesNotes sans jeton dans l'adresse ;
 *  - configuration : réinitialisation des restrictions en POST, valeurs
 *    contrôlées ; export : nom de l'université (UDH écrit en dur),
 *    cellules en texte (pas de formule).
 */
class CandidaturesAdminController extends Controller
{
    public const ONGLETS = ['stats' => '📊 Statistiques', 'dossiers' => '📁 Dossiers', 'avancees' => '📈 Analyses avancées',
        'import_export' => '📤 Export', 'config' => '⚙️ Configuration'];

    private const STATUTS_ADMIN = ['soumis', 'en_cours', 'accepte', 'refuse', 'incomplet'];

    private const CONFIG = ['ouvert', 'inscription_ouverte', 'message_ferme', 'date_limite', 'message_inscription_fermee',
        'ins_filiere_id', 'ins_niveau_id', 'ins_faculte', 'ins_type_candidat'];

    public function __construct(private readonly Candidatures $candidatures, private readonly Journal $journal, private readonly UniversiteCourante $universite) {}

    public function index(Request $request): View
    {
        $onglet = isset(self::ONGLETS[$request->query('tab')]) ? $request->query('tab') : 'stats';
        $anneeBac = (string) $request->query('annee_bac', '');
        $base = fn () => DB::table('candidatures as c')->where('c.statut', '!=', 'brouillon')->when($anneeBac !== '', fn ($q) => $q->where('c.bac_annee', $anneeBac));
        $compte = fn (?string $statut = null, ?string $sexe = null) => $base()->when($statut, fn ($q) => $q->where('c.statut', $statut))->when($sexe, fn ($q) => $q->where('c.sexe', $sexe))->count();
        $donnees = [
            'onglet' => $onglet, 'anneeBac' => $anneeBac,
            'anneesBac' => DB::table('candidatures')->whereNotNull('bac_annee')->where('bac_annee', '!=', '')->distinct()->orderByDesc('bac_annee')->pluck('bac_annee'),
            'totaux' => ['total' => $compte(), 'accepte' => $compte('accepte'), 'refuse' => $compte('refuse'), 'soumis' => $compte('soumis'),
                'en_cours' => $compte('en_cours'), 'hommes' => $compte(null, 'M'), 'femmes' => $compte(null, 'F')],
        ];
        if (in_array($onglet, ['stats', 'avancees'], true)) {
            $donnees['graphiques'] = $this->graphiques($base);
        }
        if ($onglet === 'dossiers') {
            $q = trim((string) $request->query('q', ''));
            $statut = in_array($request->query('statut'), self::STATUTS_ADMIN, true) ? $request->query('statut') : '';
            $donnees += ['q' => $q, 'statut' => $statut, 'filieres' => $this->candidatures->filieres(),
                'dossiers' => DB::table('candidatures as c')->leftJoin('filieres as f1', 'f1.id', '=', 'c.choix1_id')
                    ->where('c.statut', '!=', 'brouillon')->when($statut !== '', fn ($w) => $w->where('c.statut', $statut))
                    ->when($anneeBac !== '', fn ($w) => $w->where('c.bac_annee', $anneeBac))
                    ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('c.nom', 'like', "%$q%")->orWhere('c.prenom', 'like', "%$q%")
                        ->orWhere('c.numero_dossier', 'like', "%$q%")->orWhere('c.numero_depot', 'like', "%$q%")))
                    ->orderByDesc('c.created_at')->limit(1000)
                    ->get(['c.id', 'c.numero_dossier', 'c.numero_depot', 'c.nom', 'c.prenom', 'c.sexe', 'c.bac_type', 'c.bac_annee', 'c.bac_mention',
                        'c.statut', 'c.etudiant_id', 'c.created_at', 'c.doc_photo', 'f1.libelle as ch1_lib'])];
        }
        if (in_array($onglet, ['config', 'import_export'], true)) {
            $donnees += ['config' => DB::table('portail_candidats_config')->pluck('valeur', 'cle'), 'annee' => $this->candidatures->annee(),
                'filieresListe' => DB::table('filieres')->where('actif', 1)->orderBy('faculte')->orderBy('libelle')->get(['id', 'libelle', 'faculte']),
                'niveaux' => DB::table('niveaux')->orderBy('code')->get(['id', 'code', 'libelle']),
                'facultes' => DB::table('filieres')->whereNotNull('faculte')->where('faculte', '!=', '')->distinct()->orderBy('faculte')->pluck('faculte')];
        }

        return view('candidature.admin.index', $donnees);
    }

    /** Dépôt d'un dossier par l'administration pour un candidat venu en personne. */
    public function creerDossier(Request $request, Fichiers $fichiers): RedirectResponse
    {
        $retour = redirect()->route('portail_candidats_admin', ['tab' => 'dossiers']);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $v = Validator::make($request->all(), [
            'nom' => 'required|string|max:100', 'prenom' => 'required|string|max:150', 'email' => 'required|email|max:150',
            'telephone' => 'nullable|string|max:30', 'date_naissance' => 'nullable|date|before:today', 'lieu_naissance' => 'nullable|string|max:200',
            'nationalite' => 'nullable|string|max:100', 'sexe' => 'required|in:M,F', 'adresse' => 'nullable|string|max:1000',
            'bac_type' => 'nullable|string|max:20', 'bac_lieu' => 'nullable|string|max:100', 'bac_annee' => 'nullable|string|max:20',
            'bac_mention' => 'nullable|string|max:50', 'bac_etablissement' => 'nullable|string|max:200',
            'choix1' => ['required', Rule::exists('filieres', 'id')->where('actif', 1)],
            'choix2' => ['nullable', Rule::exists('filieres', 'id')->where('actif', 1)], 'choix3' => ['nullable', Rule::exists('filieres', 'id')->where('actif', 1)],
        ], ['required' => 'Nom, prénom, email, genre et premier choix sont obligatoires.', 'email.email' => 'Email invalide.']);
        if ($v->fails()) {
            return $retour->with('erreur', $v->errors()->first());
        }
        if (Candidat::where('email', $request->input('email'))->exists()) {
            return $retour->with('erreur', 'Un compte candidat existe déjà avec cet email.');
        }
        $d = $v->validated();
        $motDePasse = Str::password(10, symbols: false);
        $u = $request->user();
        $numero = $this->candidatures->numeroDossier();
        $id = DB::transaction(function () use ($d, $motDePasse, $u, $numero) {
            $candidat = Candidat::create(['nom' => mb_strtoupper(trim($d['nom'])), 'prenom' => trim($d['prenom']), 'email' => $d['email'],
                'telephone' => trim($d['telephone'] ?? ''), 'mot_de_passe' => $motDePasse, 'actif' => 1]);

            return DB::table('candidatures')->insertGetId([
                'candidat_id' => $candidat->id, 'numero_dossier' => $numero, 'nom' => $candidat->nom, 'prenom' => $candidat->prenom,
                'email' => $d['email'], 'telephone' => $candidat->telephone, 'date_naissance' => $d['date_naissance'] ?? null,
                'lieu_naissance' => trim($d['lieu_naissance'] ?? ''), 'nationalite' => trim($d['nationalite'] ?? '') ?: 'Nigérienne', 'sexe' => $d['sexe'],
                'adresse' => trim($d['adresse'] ?? ''), 'bac_type' => trim($d['bac_type'] ?? ''), 'bac_lieu' => trim($d['bac_lieu'] ?? ''),
                'bac_annee' => trim($d['bac_annee'] ?? ''), 'bac_mention' => trim($d['bac_mention'] ?? ''), 'bac_etablissement' => trim($d['bac_etablissement'] ?? ''),
                'choix1_id' => (int) $d['choix1'], 'choix2_id' => ($d['choix2'] ?? null) ?: null, 'choix3_id' => ($d['choix3'] ?? null) ?: null,
                'statut' => 'soumis', 'etape_actuelle' => 3, 'date_soumission' => now(), 'created_at' => now(), 'updated_at' => now(),
                'notes_admin' => "Dossier créé par l'administration ({$u->nomComplet()}) le ".now()->format('d/m/Y à H:i').'.',
            ]);
        });
        $pieces = [];
        foreach (Candidatures::PIECES as $champ => [$colonne]) {
            if ($fichier = $request->file($champ)) {
                $nom = $fichiers->enregistrerPiece($fichier, $champ, Candidatures::TAILLE_MAX, $champ === 'photo');
                if ($nom) {
                    $pieces[$colonne] = $nom;
                }
            }
        }
        if ($pieces) {
            DB::table('candidatures')->where('id', $id)->update($pieces);
        }
        $this->journal->action('creer', 'candidature', "Dossier #$id créé par l'admin pour {$d['nom']} {$d['prenom']} ($numero)", $id);

        return $retour->with('succes', "Dossier $numero créé avec succès. Mot de passe provisoire du candidat (affiché une seule fois) : $motDePasse");
    }

    public function statut(Request $request): RedirectResponse
    {
        $dossier = $this->candidatures->dossier((int) $request->input('dos_id'));
        $statut = (string) $request->input('statut');
        $retour = $this->retour($request);
        if (! $dossier || ! in_array($statut, self::STATUTS_ADMIN, true)) {
            return $retour->with('erreur', 'Dossier ou statut invalide.');
        }
        $avant = $dossier->numero_depot;
        $depot = $this->candidatures->changerStatut($dossier, $statut, (int) $request->user()->id, $this->sigle());
        $this->journal->action('modifier', 'candidature', "Statut du dossier #{$dossier->id} → $statut".($depot && ! $avant ? " (N° dépôt attribué : $depot)" : ''), (int) $dossier->id);

        return $retour->with('succes', match (true) {
            $statut === 'accepte' && ! $avant => "Dossier accepté. N° dépôt attribué automatiquement : $depot",
            $statut === 'accepte' => "Statut mis à jour. N° dépôt existant : $depot",
            default => 'Statut mis à jour.',
        });
    }

    public function note(Request $request): RedirectResponse
    {
        $dossier = $this->candidatures->dossier((int) $request->input('dos_id'));
        if (! $dossier) {
            return $this->retour($request)->with('erreur', 'Dossier introuvable.');
        }
        DB::table('candidatures')->where('id', $dossier->id)->update(['notes_admin' => mb_substr((string) $request->input('note', ''), 0, 5000)]);

        return $this->retour($request)->with('succes', 'Note enregistrée.');
    }

    public function creerEtudiant(Request $request): RedirectResponse
    {
        $dossier = $this->candidatures->dossier((int) $request->input('dos_id'));
        if (! $dossier) {
            return $this->retour($request)->with('erreur', 'Dossier introuvable.');
        }
        $id = $this->candidatures->creerEtudiant($dossier, (int) $request->user()->id, $this->sigle());
        if (! $id) {
            return $this->retour($request)->with('erreur', 'Une fiche étudiant est déjà liée à ce dossier.');
        }
        $this->journal->action('creer', 'candidature', "Étudiant créé depuis le dossier #{$dossier->id}", $id);

        return $this->retour($request)->with('succes', 'Étudiant créé avec matricule à assigner. Dossier accepté.');
    }

    public function config(Request $request): RedirectResponse
    {
        $retour = redirect()->route('portail_candidats_admin', ['tab' => 'config']);
        $v = Validator::make($request->all(), [
            'ouvert' => 'sometimes|in:0,1', 'inscription_ouverte' => 'sometimes|in:0,1',
            'message_ferme' => 'sometimes|nullable|string|max:1000', 'message_inscription_fermee' => 'sometimes|nullable|string|max:1000',
            'date_limite' => 'sometimes|nullable|string|max:255', 'ins_type_candidat' => 'sometimes|nullable|in:nouveau,ancien',
            'ins_filiere_id' => ['sometimes', 'nullable', Rule::exists('filieres', 'id')], 'ins_niveau_id' => ['sometimes', 'nullable', Rule::exists('niveaux', 'id')],
            'ins_faculte' => 'sometimes|nullable|string|max:150',
        ]);
        if ($v->fails()) {
            return $retour->with('erreur', $v->errors()->first());
        }
        foreach (self::CONFIG as $cle) {
            if ($request->has($cle)) {
                DB::table('portail_candidats_config')->updateOrInsert(['cle' => $cle], ['valeur' => (string) $request->input($cle, '')]);
            }
        }
        $this->journal->action('modifier', 'portail_candidats', 'Configuration du portail candidats mise à jour');

        return $retour->with('succes', 'Configuration mise à jour.');
    }

    public function reinitialiserRestrictions(): RedirectResponse
    {
        foreach (['ins_filiere_id', 'ins_niveau_id', 'ins_faculte', 'ins_type_candidat'] as $cle) {
            DB::table('portail_candidats_config')->updateOrInsert(['cle' => $cle], ['valeur' => '']);
        }
        $this->journal->action('modifier', 'portail_candidats', "Restrictions d'inscription supprimées");

        return redirect()->route('portail_candidats_admin', ['tab' => 'config'])->with('succes', 'Restrictions supprimées.');
    }

    /** Fiche complète d'un dossier (ancien dossier_candidat). */
    public function dossier(int $id): View|RedirectResponse
    {
        $dossier = DB::table('candidatures as c')->leftJoin('filieres as f1', 'f1.id', '=', 'c.choix1_id')->leftJoin('filieres as f2', 'f2.id', '=', 'c.choix2_id')
            ->leftJoin('filieres as f3', 'f3.id', '=', 'c.choix3_id')->leftJoin('candidats as cand', 'cand.id', '=', 'c.candidat_id')
            ->where('c.id', $id)->first(['c.*', 'f1.libelle as ch1_lib', 'f2.libelle as ch2_lib', 'f3.libelle as ch3_lib', 'cand.email as cand_email', 'cand.telephone as cand_tel']);
        if (! $dossier) {
            return redirect()->route('portail_candidats_admin', ['tab' => 'dossiers'])->with('erreur', 'Dossier introuvable.');
        }

        return view('candidature.admin.dossier', ['d' => $dossier, 'filieres' => DB::table('filieres')->orderBy('libelle')->get(['id', 'libelle', 'actif'])]);
    }

    public function modifierDossier(Request $request, int $id): RedirectResponse
    {
        $retour = redirect()->route('dossier_candidat', ['id' => $id]);
        if (! DB::table('candidatures')->where('id', $id)->exists()) {
            return redirect()->route('portail_candidats_admin', ['tab' => 'dossiers'])->with('erreur', 'Dossier introuvable.');
        }
        $v = Validator::make($request->all(), [
            'nom' => 'required|string|max:100', 'prenom' => 'required|string|max:150', 'sexe' => 'required|in:M,F',
            'date_naissance' => 'nullable|date', 'lieu_naissance' => 'nullable|string|max:200', 'nationalite' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:1000', 'telephone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:150',
            'bac_type' => 'nullable|string|max:20', 'bac_mention' => 'nullable|string|max:50', 'bac_lieu' => 'nullable|string|max:100', 'bac_annee' => 'nullable|string|max:20',
            'choix1_id' => ['nullable', Rule::exists('filieres', 'id')], 'choix2_id' => ['nullable', Rule::exists('filieres', 'id')], 'choix3_id' => ['nullable', Rule::exists('filieres', 'id')],
        ], ['required' => 'Nom, prénom et genre sont obligatoires.']);
        if ($v->fails()) {
            // Formulaire rouvert avec la saisie (la fiche s'affiche en lecture par défaut)
            return $retour->withInput()->with(['erreur' => $v->errors()->first(), 'edition' => true]);
        }
        $d = $v->validated();
        DB::table('candidatures')->where('id', $id)->update(array_map(fn ($x) => is_string($x) ? trim($x) : $x, [
            'nom' => $d['nom'], 'prenom' => $d['prenom'], 'sexe' => $d['sexe'], 'date_naissance' => $d['date_naissance'] ?? null,
            'lieu_naissance' => $d['lieu_naissance'] ?? '', 'nationalite' => $d['nationalite'] ?? '', 'adresse' => $d['adresse'] ?? '',
            'telephone' => $d['telephone'] ?? '', 'email' => $d['email'] ?? '', 'bac_type' => $d['bac_type'] ?? '', 'bac_mention' => $d['bac_mention'] ?? '',
            'bac_lieu' => $d['bac_lieu'] ?? '', 'bac_annee' => $d['bac_annee'] ?? '',
            'choix1_id' => ($d['choix1_id'] ?? null) ?: null, 'choix2_id' => ($d['choix2_id'] ?? null) ?: null, 'choix3_id' => ($d['choix3_id'] ?? null) ?: null,
        ]));
        $this->journal->action('modifier', 'candidature', "Dossier #$id modifié par l'administration", $id);

        return $retour->with('succes', 'Dossier mis à jour.');
    }

    /** Pièce d'un dossier, pour le personnel. */
    public function piece(int $id, string $type): BinaryFileResponse
    {
        $dossier = DB::table('candidatures')->find($id);
        abort_unless($dossier && isset(Candidatures::PIECES[$type]), 404);

        return EspaceCandidatController::servirPiece((string) $dossier->{Candidatures::PIECES[$type][0]});
    }

    /** Quitus d'un dossier, pour le personnel (sans jeton dans l'adresse). */
    public function quitus(Identite $identite, Parametres $parametres, int $id): View|RedirectResponse
    {
        $dossier = $this->candidatures->dossier($id);
        if (! $dossier) {
            return redirect()->route('portail_candidats_admin', ['tab' => 'dossiers'])->with('erreur', 'Dossier introuvable.');
        }

        return view('candidature.quitus', DepotController::donneesQuitus($dossier, $identite, $parametres, $this->universite, $this->candidatures)
            + ['photo' => $dossier->doc_photo && strtolower(pathinfo($dossier->doc_photo, PATHINFO_EXTENSION)) !== 'pdf'
                ? route('portail_candidats_admin.piece', ['id' => $dossier->id, 'type' => 'photo']) : null]);
    }

    /** Export Excel des dossiers (ancien portail_export) : statut, période, faculté du 1er choix. */
    public function export(Request $request): BinaryFileResponse
    {
        $type = (string) $request->query('type', 'complet');
        $statut = in_array($type, self::STATUTS_ADMIN, true) ? $type : null;
        $debut = (string) $request->query('date_debut', '');
        $fin = (string) $request->query('date_fin', '');
        $faculte = trim((string) $request->query('faculte', ''));
        $dossiers = DB::table('candidatures as c')
            ->leftJoin('filieres as f1', 'f1.id', '=', 'c.choix1_id')->leftJoin('filieres as f2', 'f2.id', '=', 'c.choix2_id')->leftJoin('filieres as f3', 'f3.id', '=', 'c.choix3_id')
            ->when($statut, fn ($q) => $q->where('c.statut', $statut), fn ($q) => $q->where('c.statut', '!=', 'brouillon'))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut), fn ($q) => $q->whereDate('c.created_at', '>=', $debut))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin), fn ($q) => $q->whereDate('c.created_at', '<=', $fin))
            ->when($faculte !== '', fn ($q) => $q->where('f1.faculte', $faculte))
            ->orderByDesc('c.created_at')
            ->get(['c.*', 'f1.libelle as ch1_lib', 'f1.faculte as ch1_fac', 'f2.libelle as ch2_lib', 'f2.faculte as ch2_fac', 'f3.libelle as ch3_lib', 'f3.faculte as ch3_fac']);
        $this->journal->action('exporter', 'candidature', 'Export des dossiers de candidature ('.$dossiers->count().')');

        $titres = ['N° Dossier', 'N° Dépôt', 'Nom', 'Prénom', 'Date Naissance', 'Lieu Naissance', 'Nationalité', 'Sexe', 'Téléphone', 'Email', 'Adresse',
            'Nom Père', 'Nom Mère', 'Tél. Urgence', 'Type BAC', 'Lieu BAC', 'Année BAC', 'Mention BAC', 'Établissement BAC',
            '1er Choix', 'Faculté (Choix 1)', '2ème Choix', 'Faculté (Choix 2)', '3ème Choix', 'Faculté (Choix 3)',
            'Photo', 'Extrait Naissance', 'Certificat Nationalité', 'Relevé Notes', 'Attestation', 'Bulletin', 'Pièce ID', 'Autre Doc',
            'Statut', 'Notes Admin', 'Date Soumission', 'Date Dépôt'];
        $texte = fn ($v) => ['v' => trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string) $v))];
        $date = fn ($v, $f) => $v ? date($f, strtotime((string) $v)) : '';
        $lignes = [
            ['height' => 24, 'cells' => [['v' => (self::NOMS_EXPORT[$type] ?? 'Tous les Dossiers').' — '.$this->universite->get()->nom, 'style' => 1]]],
            ['cells' => [['v' => 'Généré le '.now()->format('d/m/Y à H:i').' — '.$dossiers->count().' dossier(s)']]],
            ['height' => 30, 'cells' => array_map(fn ($t) => ['v' => $t, 'style' => 5], $titres)],
        ];
        foreach ($dossiers as $d) {
            $lignes[] = ['cells' => array_map($texte, [
                $d->numero_dossier, $d->numero_depot, mb_strtoupper((string) $d->nom), $d->prenom, $date($d->date_naissance, 'd/m/Y'), $d->lieu_naissance, $d->nationalite,
                ['M' => 'Masculin', 'F' => 'Féminin'][$d->sexe] ?? '', $d->telephone, $d->email, $d->adresse, $d->nom_pere, $d->nom_mere, $d->tel_urgence,
                $d->bac_type, $d->bac_lieu, $d->bac_annee, $d->bac_mention, $d->bac_etablissement,
                $d->ch1_lib, $d->ch1_fac, $d->ch2_lib, $d->ch2_fac, $d->ch3_lib, $d->ch3_fac,
                ...array_map(fn ($c) => $d->$c ? 'Oui' : 'Non', ['doc_photo', 'doc_extrait', 'doc_nationalite', 'doc_releve', 'doc_attestation', 'doc_bulletin', 'doc_cni', 'doc_autre']),
                ucfirst((string) $d->statut), $d->notes_admin, $date($d->date_soumission, 'd/m/Y H:i'), $date($d->created_at, 'd/m/Y H:i'),
            ])];
        }
        $classeur = new Classeur;
        $classeur->feuille(self::NOMS_EXPORT[$type] ?? 'Tous les Dossiers', $lignes,
            [14, 14, 20, 16, 12, 16, 14, 10, 14, 26, 24, 22, 22, 14, 10, 14, 10, 14, 24, 26, 16, 22, 16, 22, 16, 8, 8, 8, 8, 8, 8, 8, 8, 12, 30, 16, 16], 3);

        return response()->download($classeur->enregistrer(), 'portail_'.strtolower($this->sigle()).'_'.($statut ?? 'export_complet').'_'.date('Ymd').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private const NOMS_EXPORT = ['accepte' => 'Dossiers Approuvés', 'refuse' => 'Dossiers Refusés', 'soumis' => 'Dossiers Soumis',
        'en_cours' => 'Dossiers En Cours', 'incomplet' => 'Dossiers Incomplets'];

    /** Données des graphiques (statistiques et analyses avancées). */
    private function graphiques(\Closure $base): array
    {
        $groupe = fn (string $col, string $alias) => $base()->whereNotNull("c.$col")->where("c.$col", '!=', '')
            ->groupBy("c.$col")->orderByDesc('nb')->get([DB::raw("c.$col AS $alias"), DB::raw('COUNT(*) AS nb')]);
        $parFaculte = $base()->join('filieres as f', 'f.id', '=', 'c.choix1_id')->leftJoin('filieres as f2', 'f2.id', '=', 'c.choix2_id')
            ->leftJoin('filieres as f3', 'f3.id', '=', 'c.choix3_id')->whereNotNull('f.faculte')->groupBy('f.faculte')->orderByDesc('ch1')
            ->get(['f.faculte', DB::raw('COUNT(c.id) AS ch1'), DB::raw('SUM(f2.faculte = f.faculte) AS ch2'), DB::raw('SUM(f3.faculte = f.faculte) AS ch3')]);
        $topFilieres = $base()->join('filieres as f', fn ($j) => $j->on('f.id', '=', 'c.choix1_id')->orOn('f.id', '=', 'c.choix2_id')->orOn('f.id', '=', 'c.choix3_id'))
            ->groupBy('f.id', 'f.libelle')->orderByDesc('ch1')->limit(10)
            ->get(['f.libelle', DB::raw('SUM(c.choix1_id = f.id) AS ch1'), DB::raw('SUM(c.choix2_id = f.id) AS ch2'), DB::raw('SUM(c.choix3_id = f.id) AS ch3')]);
        $genreBac = $base()->whereNotNull('c.bac_type')->groupBy('c.bac_type', 'c.sexe')->get(['c.bac_type', 'c.sexe', DB::raw('COUNT(*) AS nb')]);
        $bacs = $genreBac->pluck('bac_type')->unique()->values();
        $mpf = $base()->join('filieres as f', 'f.id', '=', 'c.choix1_id')->whereNotNull('c.bac_mention')->groupBy('f.id', 'f.libelle', 'c.bac_mention')
            ->get(['f.libelle as filiere', 'c.bac_mention as mention', DB::raw('COUNT(*) AS nb')]);
        $filieresMpf = $mpf->pluck('filiere')->unique()->values();

        return [
            'bac' => $groupe('bac_type', 'libelle'), 'mention' => $groupe('bac_mention', 'libelle'), 'region' => $groupe('bac_lieu', 'libelle'),
            'evolution' => $base()->groupByRaw('DATE(c.created_at)')->orderByRaw('DATE(c.created_at)')->get([DB::raw('DATE(c.created_at) AS jour'), DB::raw('COUNT(*) AS nb')]),
            'facultes' => $parFaculte, 'filieres' => $topFilieres,
            'genreBac' => ['bacs' => $bacs, 'hommes' => $bacs->map(fn ($b) => (int) $genreBac->where('bac_type', $b)->where('sexe', 'M')->sum('nb')),
                'femmes' => $bacs->map(fn ($b) => (int) $genreBac->where('bac_type', $b)->where('sexe', 'F')->sum('nb'))],
            'mentionsFilieres' => ['filieres' => $filieresMpf, 'series' => collect(Candidatures::MENTIONS)->mapWithKeys(fn ($m) => [$m => $filieresMpf->map(
                fn ($f) => (int) $mpf->where('filiere', $f)->where('mention', $m)->sum('nb'))])],
        ];
    }

    private function retour(Request $request): RedirectResponse
    {
        return (int) $request->input('depuis_dossier')
            ? redirect()->route('dossier_candidat', ['id' => (int) $request->input('dos_id')])
            : redirect()->route('portail_candidats_admin', ['tab' => 'dossiers']);
    }

    private function sigle(): string
    {
        $u = $this->universite->get();

        return $u->sigle ?: $u->code;
    }
}
