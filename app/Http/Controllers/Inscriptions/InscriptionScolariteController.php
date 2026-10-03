<?php

namespace App\Http\Controllers\Inscriptions;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Fichiers;
use App\Services\Inscriptions;
use App\Services\Journal;
use App\Services\Matricules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Inscription scolarité — portage de pages/inscriptions/inscription_scolarite.php.
 * Page réservée à admin, secrétaire, sc_centrale, sc_entite ; la création
 * d'une inscription (et le reçu) à admin et sc_centrale.
 *
 * Un étudiant déjà matriculé ne peut être inscrit que s'il a une décision
 * de passage pour l'année ; un nouveau bachelier (sans matricule) reçoit
 * son matricule à l'inscription.
 *
 * Différences voulues :
 *  - matricule attribué par le service commun, sous verrou ; inscription,
 *    matricule et paiement enregistrés ensemble (transaction) ;
 *  - modes « MyNITA » et « Amana » (absents de la base : l'inscription
 *    échouait) enregistrés comme Mobile Money ;
 *  - modification : refusée si l'étudiant a déjà une autre inscription au
 *    même niveau la même année ; limitée à la faculté de l'agent ; la
 *    promotion créée au besoin reçoit sa maquette (comme à l'inscription) ;
 *    la filière de l'étudiant n'est mise à jour que si l'inscription modifiée
 *    est la plus récente ;
 *  - année, niveau et filière doivent exister ; l'agent est enregistré.
 */
class InscriptionScolariteController extends Controller
{
    public function __construct(
        private readonly Inscriptions $inscriptions,
        private readonly Matricules $matricules,
        private readonly Perimetre $perimetre,
        private readonly Journal $journal,
        private readonly Fichiers $fichiers,
    ) {}

    public function index(Request $request): View
    {
        $peutInscrire = in_array($request->user()->role, ['admin', 'sc_centrale'], true);
        $anneeActive = (int) DB::table('annees_academiques')->where('active', 1)->value('id');
        $communs = [
            'peutInscrire' => $peutInscrire,
            'anneeActive' => $anneeActive,
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(),
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
        ];

        if ($id = (int) $request->query('id')) {
            $etudiant = $this->etudiant('e.id', $id);
            if ($etudiant) {
                $passage = $this->inscriptions->passage($etudiant->id, $anneeActive);
                $changeFiliere = $passage && $passage->filiere_id && (int) $passage->filiere_id !== (int) $etudiant->filiere_id;

                return view('inscriptions.scolarite.etudiant', $communs + [
                    'etudiant' => $etudiant,
                    'photo' => $this->photo($etudiant),
                    'passage' => $passage,
                    'nouveau' => self::sansMatricule($etudiant->numero),
                    'filiereAffichee' => $changeFiliere ? $passage->filiere_lib : $etudiant->filiere_lib,
                    'faculteAffichee' => $changeFiliere ? $passage->filiere_faculte : $etudiant->faculte,
                    'anneeActiveLibelle' => $communs['annees']->firstWhere('id', $anneeActive)->libelle ?? '',
                    'inscriptions' => DB::table('inscriptions as i')->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')
                        ->leftJoin('annees_academiques as a', 'a.id', '=', 'i.annee_id')->leftJoin('promotions as p', 'p.id', '=', 'i.promotion_id')
                        ->leftJoin('filieres as f', 'f.id', '=', 'p.filiere_id')->where('i.etudiant_id', $id)->orderByDesc('a.libelle')
                        ->get(['i.*', 'n.code as niveau_code', 'n.libelle as niveau_lib', 'a.libelle as annee_lib', 'p.filiere_id as ins_filiere_id', 'f.libelle as ins_filiere_lib']),
                    'filieres' => DB::table('filieres')->where('actif', 1)->orderBy('faculte')->orderBy('libelle')->get(['id', 'libelle', 'faculte']),
                ]);
            }
        }

        $recherche = trim((string) $request->query('matricule'));
        $trouve = $recherche !== '' ? $this->etudiant(null, mb_strtoupper($recherche)) : null;

        return view('inscriptions.scolarite.recherche', $communs + [
            'recherche' => $recherche,
            'etudiant' => $trouve,
            'photo' => $trouve ? $this->photo($trouve) : null,
            'inscriptions' => $trouve ? DB::table('inscriptions as i')->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')
                ->leftJoin('annees_academiques as a', 'a.id', '=', 'i.annee_id')->where('i.etudiant_id', $trouve->id)->orderByDesc('a.libelle')
                ->get(['i.*', 'n.code as niveau_code', 'n.libelle as niveau_lib', 'a.libelle as annee_lib', 'a.active']) : collect(),
        ]);
    }

    public function inscrire(Request $request): RedirectResponse
    {
        $u = $request->user();
        $etId = (int) $request->input('etudiant_id');
        $retour = route('inscription_scolarite', ['id' => $etId]);
        if (! in_array($u->role, ['admin', 'sc_centrale'], true)) {
            return redirect($retour)->with('erreur', 'Accès réservé à la scolarité centrale.');
        }
        $niveauId = (int) $request->input('niveau_id');
        $anneeId = (int) $request->input('annee_id');
        $montant = max(0, (float) $request->input('montant', Inscriptions::FRAIS));
        $mode = Inscriptions::mode($request->input('mode_paiement')) ?? 'especes';

        $et = DB::table('etudiants')->where('id', $etId)->first();
        $erreurs = [];
        if (! $et) {
            $erreurs[] = 'Étudiant introuvable.';
        }
        if (! $niveauId || ! DB::table('niveaux')->where('id', $niveauId)->exists()) {
            $erreurs[] = 'Le niveau est obligatoire.';
        }
        if (! $anneeId || ! DB::table('annees_academiques')->where('id', $anneeId)->exists()) {
            $erreurs[] = "L'année académique est obligatoire.";
        }
        if ($et && $anneeId && ! self::sansMatricule($et->numero) && ! $this->inscriptions->passage($et->id, $anneeId)) {
            $erreurs[] = "Cet étudiant n'a pas de décision de passage enregistrée. Inscription non autorisée.";
        }
        if ($et && ! $et->filiere_id) {
            $erreurs[] = "Cet étudiant n'a pas de filière assignée. Veuillez d'abord lui assigner une filière.";
        }
        if (! $erreurs && DB::table('inscriptions')->where(['etudiant_id' => $etId, 'niveau_id' => $niveauId, 'annee_id' => $anneeId])->exists()) {
            $erreurs[] = 'Cet étudiant est déjà inscrit pour ce niveau et cette année.';
        }
        if ($erreurs) {
            return redirect($retour)->with('erreur', implode(' ', $erreurs));
        }

        $matricule = $this->matricules->sousVerrou(fn () => DB::transaction(function () use ($et, $niveauId, $anneeId, $montant, $mode, $u) {
            $matricule = $this->matricules->attribuer($et->id);

            // Passage vers une autre filière (ex. Droit → Droit privé en L3)
            $passage = $this->inscriptions->passage($et->id, $anneeId);
            $filiere = ($passage && $passage->filiere_id) ? (int) $passage->filiere_id : (int) $et->filiere_id;
            if ($filiere !== (int) $et->filiere_id) {
                DB::table('etudiants')->where('id', $et->id)->update(['filiere_id' => $filiere]);
            }

            $promo = $this->inscriptions->promotion($filiere, $niveauId, $anneeId);
            $insId = DB::table('inscriptions')->insertGetId([
                'etudiant_id' => $et->id, 'promotion_id' => $promo, 'niveau_id' => $niveauId, 'annee_id' => $anneeId,
                'date_inscription' => now()->toDateString(), 'statut' => 'inscrit', 'montant_paye' => $montant,
                'mode_paiement' => $mode, 'saisie_par' => $u->id,
            ]);

            // Changement de filière : les inscriptions pédagogiques de l'ancienne
            // inscription de la même année suivent la nouvelle
            if ($filiere !== (int) $et->filiere_id) {
                $ancienne = DB::table('inscriptions')->where('etudiant_id', $et->id)->where('annee_id', $anneeId)
                    ->where('id', '!=', $insId)->orderByDesc('id')->value('id');
                if ($ancienne) {
                    DB::table('inscriptions_pedagogiques')->where(['etudiant_id' => $et->id, 'inscription_id' => $ancienne, 'annee_id' => $anneeId])
                        ->update(['inscription_id' => $insId]);
                }
            }
            if ($montant > 0) {
                DB::table('paiements')->insert([
                    'etudiant_id' => $et->id, 'inscription_id' => $insId, 'type_paiement' => 'inscription',
                    'montant_du' => Inscriptions::FRAIS, 'montant_paye' => $montant, 'date_paiement' => now()->toDateString(),
                    'mode_paiement' => $mode, 'statut' => 'complet', 'saisi_par' => $u->id, 'annee_id' => $anneeId,
                ]);
            }
            $this->journal->action('creer', 'inscription', "Inscription académique — étudiant #{$et->id}", $insId);

            return $matricule;
        }));

        return redirect()->route('dossier_etudiant', ['id' => $et->id])
            ->with('succes', "✅ {$et->nom} {$et->prenoms} inscrit. Matricule : $matricule.");
    }

    public function modifier(Request $request): RedirectResponse
    {
        $u = $request->user();
        $ins = DB::table('inscriptions')->where('id', (int) $request->input('inscription_id'))->first();
        $retour = route('inscription_scolarite', ['id' => $ins->etudiant_id ?? (int) $request->input('etudiant_id_redirect')]);
        $filiere = (int) $request->input('filiere_id');
        $niveau = (int) $request->input('niveau_id');
        $annee = (int) $request->input('annee_id');

        if (! $ins) {
            return redirect($retour)->with('erreur', 'Inscription introuvable.');
        }
        if (! DB::table('filieres')->where('id', $filiere)->exists() || ! DB::table('niveaux')->where('id', $niveau)->exists()
            || ! DB::table('annees_academiques')->where('id', $annee)->exists()) {
            return redirect($retour)->with('erreur', 'Tous les champs sont obligatoires pour modifier une inscription.');
        }
        if (! $this->perimetre->promotion($u, (int) $ins->promotion_id) || ! $this->perimetre->filiere($u, $filiere)) {
            return redirect($retour)->with('erreur', "Cette inscription ou cette filière n'appartient pas à votre faculté.");
        }
        if (DB::table('inscriptions')->where(['etudiant_id' => $ins->etudiant_id, 'niveau_id' => $niveau, 'annee_id' => $annee])->where('id', '!=', $ins->id)->exists()) {
            return redirect($retour)->with('erreur', 'Cet étudiant a déjà une autre inscription à ce niveau pour cette année.');
        }

        DB::transaction(function () use ($ins, $filiere, $niveau, $annee, $request) {
            DB::table('inscriptions')->where('id', $ins->id)->update([
                'promotion_id' => $this->inscriptions->promotion($filiere, $niveau, $annee),
                'niveau_id' => $niveau, 'annee_id' => $annee,
                'montant_paye' => max(0, (float) $request->input('montant_paye')),
                'mode_paiement' => Inscriptions::mode($request->input('mode_paiement')) ?? $ins->mode_paiement,
            ]);
            // La filière de l'étudiant suit sa DERNIÈRE inscription
            $derniere = DB::table('inscriptions as i')->join('annees_academiques as a', 'a.id', '=', 'i.annee_id')
                ->where('i.etudiant_id', $ins->etudiant_id)->orderByDesc('a.libelle')->orderByDesc('i.id')->value('i.id');
            if ((int) $derniere === (int) $ins->id) {
                DB::table('etudiants')->where('id', $ins->etudiant_id)->update(['filiere_id' => $filiere]);
            }
        });
        $this->journal->action('modifier', 'inscription', "Modification inscription académique (étudiant #{$ins->etudiant_id})", $ins->id);

        return redirect($retour)->with('succes', '✅ Inscription modifiée.');
    }

    private function etudiant(?string $colonne, int|string $valeur): ?object
    {
        return DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->when($colonne, fn ($q) => $q->where($colonne, $valeur), fn ($q) => $q->where(fn ($q) => $q->where('e.numero', $valeur)->orWhere('e.numero_dossier', $valeur)))
            ->first(['e.*', 'f.libelle as filiere_lib', 'f.faculte']);
    }

    private function photo(object $et): ?string
    {
        return $et->photo && is_file($this->fichiers->dossier('photos').DIRECTORY_SEPARATOR.basename($et->photo))
            ? route('fichiers.photo', basename($et->photo)) : null;
    }

    /** Pas encore de matricule : vide, NULL ou « 0 » (nouveau bachelier). */
    public static function sansMatricule(?string $numero): bool
    {
        return $numero === null || trim($numero) === '' || $numero === '0';
    }
}
