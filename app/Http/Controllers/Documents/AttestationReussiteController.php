<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Navigation\Menu;
use App\Services\Identite;
use App\Services\Journal;
use App\Services\Parametres;
use App\Services\Pv;
use App\Services\RegistreDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Attestation de réussite d'un semestre (par PV) — portage de
 * pages/documents/attestation.php : choix du PV, puis une page A4 par admis
 * (ou pour un étudiant).
 *
 * Accès (repris) : admin, secrétaire, sc_centrale, sc_entite avec la
 * permission « inscription_ped » ; l'étudiant pour lui-même (actif avec son
 * portail).
 *
 * Différences voulues :
 *  - attestation individuelle refusée si l'étudiant n'est pas admis dans ce
 *    PV : l'ancienne page imprimait « Admis » pour n'importe quel étudiant
 *    dont on mettait l'identifiant dans l'adresse (fausse attestation) ;
 *  - PV limité au périmètre de l'agent (aucun contrôle de faculté avant) ;
 *    l'étudiant : PV publié seulement ;
 *  - en rattrapage, un étudiant déjà admis en session normale reçoit un
 *    message au lieu d'une page vide ;
 *  - numéro enregistré (registre attestations_emises, type « reussite_<pv> ») :
 *    le même à chaque réimpression, vérifiable sur la page publique (avant :
 *    rang dans la liste imprimée, donc le même numéro pour des étudiants
 *    différents d'un PV à l'autre) ;
 *  - bouton « Générer » sans étudiant retiré (il menait à « Étudiant non
 *    spécifié ») ; ville tirée des paramètres (« Tahoua » en dur).
 */
class AttestationReussiteController extends Controller
{
    private const ROLES = ['admin', 'secretaire', 'sc_centrale', 'sc_entite'];

    private const NIVEAUX = ['L1' => 'Licence 1ère Année', 'L2' => 'Licence 2ème Année', 'L3' => 'Licence 3ème Année',
        'M1' => 'Master 1', 'M2' => 'Master 2', 'DUT1' => 'DUT 1ère Année', 'DUT2' => 'DUT 2ème Année'];

    public function __invoke(Request $request, Pv $pvs, Identite $identite, Parametres $parametres, Menu $menu,
        RegistreDocuments $registre, Journal $journal): View|RedirectResponse
    {
        $u = $request->user();
        $estEtudiant = $u->role === 'etudiant';
        if (! $estEtudiant && ! (in_array($u->role, self::ROLES, true) && $u->autorise('inscription_ped'))) {
            return redirect()->route('accueil')->with('erreur', 'Accès non autorisé.');
        }
        $retour = $estEtudiant ? redirect($menu->lien('portail_etudiant')) : redirect()->route('attestation');

        if (! $request->filled('pv_id')) {
            if ($estEtudiant) {
                return $retour->with('erreur', 'PV non spécifié.');
            }

            return view('documents.attestation_choix', [
                'pvs' => DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                    ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
                    ->when($u->role === 'sc_entite', fn ($q) => $q->where('f.faculte_id', $u->faculteId()))
                    ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('s.code')
                    ->get(['ps.id', 'ps.type_session', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee', 's.code as semestre'])
                    ->groupBy('annee'),
            ]);
        }

        $pv = $pvs->trouver((int) $request->query('pv_id'));
        if (! $pv || ($estEtudiant ? $pv->statut !== 'publie' : ! $pvs->accessible($u, $pv))) {
            return $retour->with('erreur', $estEtudiant ? 'Attestation non disponible : les résultats de ce PV ne sont pas encore publiés.'
                : 'Accès non autorisé — ce PV appartient à une autre faculté.');
        }

        $masse = ! $estEtudiant && $request->has('masse');
        $etId = $estEtudiant ? (int) $u->etudiant_id : (int) $request->query('et_id');
        $valideEnNormale = fn ($q) => $q->whereNotExists(fn ($s) => $s->from('resultats_semestre as rn')->whereColumn('rn.etudiant_id', 'rs.etudiant_id')
            ->where('rn.pv_id', (int) $pv->pv_normale_id)->where('rn.valide', 1));
        $etudiants = DB::table('resultats_semestre as rs')->join('etudiants as e', 'e.id', '=', 'rs.etudiant_id')
            ->where('rs.pv_id', $pv->id)->where('rs.valide', 1)->where('rs.defaillant', 0)
            ->when($pv->type_session === 'rattrapage' && $pv->pv_normale_id, $valideEnNormale)
            ->when(! $masse, fn ($q) => $q->where('e.id', $etId))
            ->orderBy('e.nom')->orderBy('e.prenoms')
            ->get(['e.*', 'rs.moyenne_generale', 'rs.mention', 'rs.credits_obtenus']);
        if (! $masse && $etudiants->isEmpty()) {
            $dejaNormale = $pv->pv_normale_id && DB::table('resultats_semestre')->where(['pv_id' => $pv->pv_normale_id, 'etudiant_id' => $etId, 'valide' => 1])->exists();

            return $retour->with('erreur', $dejaNormale
                ? "Étudiant admis en session normale : l'attestation se tire depuis le PV de 1ère session."
                : "Pas d'attestation de réussite : l'étudiant n'est pas admis dans ce PV.");
        }

        $libelles = DB::table('filieres as f')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')
            ->where('f.id', $pv->filiere_id)->first([DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte')]);
        $faculte = $pv->faculte_id ? (int) $pv->faculte_id : null;
        $numeros = $etudiants->mapWithKeys(fn ($e) => [$e->id => $registre->enregistrer('attestations_emises', 'AR'.date('y').'-',
            ['etudiant_id' => $e->id, 'type' => 'reussite_'.$pv->id],
            ['nom' => $e->nom, 'prenoms' => $e->prenoms, 'filiere' => $pv->filiere ?: null, 'mention_grade' => $e->mention ?: null,
                'moyenne' => $e->moyenne_generale, 'annee_academique' => $pv->annee ?: null])]);
        $journal->action('creer', 'attestation', 'Attestation de réussite générée — '.$pv->libelle.' : '
            .($masse ? $numeros->count().' étudiant(s)' : $numeros->first()), $masse ? (int) $pv->id : $etId);

        return view('documents.attestation_reussite', [
            'numeros' => $numeros,
            'pv' => $pv,
            'masse' => $masse,
            'etudiants' => $etudiants,
            'niveauLib' => self::NIVEAUX[$pv->niveau] ?? $pv->niveau_libelle,
            'totalCredits' => (int) $pvs->ues($pv)->sum('credits_total'),
            'faculte' => $libelles->faculte ?? '',
            'signataire' => $identite->signataire($faculte),
            'logos' => $identite->logos($faculte),
            'republique' => $parametres->valeur('institution_republique', 'REPUBLIQUE DU NIGER'),
            'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
            'bp' => $parametres->valeur('institution_bp', 'BP : 255'),
            'tel' => $parametres->valeur('institution_tel', '(+227) 20 610 650'),
            'ville' => $parametres->valeur('institution_ville', 'Tahoua'),
        ]);
    }
}
