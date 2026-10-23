<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Identite;
use App\Services\Parametres;
use App\Services\PresidentsJury;
use App\Services\Pv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Liste des admis — portage de pages/inscriptions/liste_valides.php
 * (admin, secrétaire, sc_centrale, sc_entite, doyen, chef de département) :
 * choix du PV puis document A4 « Ont définitivement validé… ».
 *
 * Différences voulues :
 *  - admis = résultats enregistrés validés et non défaillants (l'ancienne
 *    requête refaisait son propre test de défaillance, différent du moteur) ;
 *  - le chef de département ne voit que les PV de son département ;
 *  - le bouton « En masse », identique à « Générer », est retiré, ainsi que
 *    le doublon du bouton Imprimer et le « Page 1 de N » figé (faux dès la
 *    deuxième page imprimée).
 */
class ListeValidesController extends Controller
{
    public function __invoke(Request $request, Pv $pvs, Identite $identite, Parametres $parametres, PresidentsJury $presidents): View|RedirectResponse
    {
        $u = $request->user();
        if (! $request->filled('pv_id')) {
            return view('notes.liste_valides.choix', [
                'pvs' => DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                    ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
                    ->when($u->faculteId(), fn ($q, $f) => $q->where('f.faculte_id', $f))
                    ->when($u->departementId(), fn ($q, $d) => $q->where('f.departement_id', $d))
                    ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('s.code')->orderBy('ps.type_session')
                    ->get(['ps.id', 'ps.type_session', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee', 's.code as semestre'])
                    ->groupBy('annee'),
            ]);
        }

        $pv = $pvs->trouver((int) $request->query('pv_id'));
        // Président du jury du semestre (depuis « Mes jurys ») ; les autres : leur faculté, leur département
        if (! $pv || (! $presidents->preside($u, $pv) && (($u->faculteId() && (int) $pv->faculte_id !== $u->faculteId()) || ($u->departementId() && (int) $pv->departement_id !== $u->departementId())))) {
            return redirect()->route('liste_valides')->with('erreur', 'Accès non autorisé — ce PV appartient à une autre faculté.');
        }
        $libelles = DB::table('filieres as f')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')->leftJoin('departements as d', 'd.id', '=', 'f.departement_id')
            ->where('f.id', $pv->filiere_id)->first([DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'), DB::raw('COALESCE(d.libelle, f.departement) AS departement')]);

        return view('notes.liste_valides.document', [
            'pv' => $pv,
            'president' => PresidentsJury::nom($presidents->pour($pv)),
            'valides' => DB::table('resultats_semestre as rs')->join('etudiants as e', 'e.id', '=', 'rs.etudiant_id')
                ->where('rs.pv_id', $pv->id)->where('rs.valide', 1)->where('rs.defaillant', 0)
                ->orderBy('e.nom')->orderBy('e.prenoms')
                ->get(['e.nom', 'e.prenoms', 'e.date_naissance', 'e.lieu_naissance', 'rs.mention']),
            'faculte' => $libelles->faculte ?? '',
            'departement' => $libelles->departement ?? '',
            'logos' => $identite->logos($pv->faculte_id ? (int) $pv->faculte_id : null),
            'republique' => $parametres->valeur('institution_republique', 'REPUBLIQUE DU NIGER'),
            'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
            'bp' => $parametres->valeur('institution_bp', 'BP: 255 TAHOUA'),
            'ville' => $parametres->valeur('institution_ville', 'Tahoua'),
        ]);
    }
}
