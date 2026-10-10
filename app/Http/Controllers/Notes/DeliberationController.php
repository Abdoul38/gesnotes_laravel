<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Identite;
use App\Services\Parametres;
use App\Services\Pv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * PV de délibération — portage de pages/notes_pv/deliberation.php (admin,
 * secrétaire, sc_centrale, sc_entite, doyen, chef de département) : choix
 * du PV, puis document A4 (statistiques, liste des admis, signature).
 *
 * Différences voulues :
 *  - statistiques et liste des admis tirées des résultats enregistrés par
 *    le moteur. L'ancienne page re-jugeait les défaillances sans tenir compte
 *    des matières optionnelles : un étudiant qui n'avait pas pris une option
 *    était compté « défaillant » ;
 *  - le chef de département ne voit que les PV de son département ;
 *  - nom de l'université et ville tirés des paramètres.
 */
class DeliberationController extends Controller
{
    public function __invoke(Request $request, Pv $pvs, Identite $identite, Parametres $parametres): View|RedirectResponse
    {
        $u = $request->user();
        if (! $request->filled('pv_id')) {
            return view('notes.deliberation.choix', [
                'pvs' => DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                    ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                    ->when($u->faculteId(), fn ($q, $f) => $q->where('f.faculte_id', $f))
                    ->when($u->departementId(), fn ($q, $d) => $q->where('f.departement_id', $d))
                    ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('ps.type_session')
                    ->get(['ps.id', 'ps.libelle', 'ps.type_session', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']),
            ]);
        }

        $pv = $pvs->trouver((int) $request->query('pv_id'));
        if (! $pv || ($u->faculteId() && (int) $pv->faculte_id !== $u->faculteId()) || ($u->departementId() && (int) $pv->departement_id !== $u->departementId())) {
            return redirect()->route('deliberation')->with('erreur', 'Accès non autorisé — ce PV appartient à une autre faculté.');
        }
        $libelles = DB::table('filieres as f')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')->leftJoin('departements as d', 'd.id', '=', 'f.departement_id')
            ->where('f.id', $pv->filiere_id)->first([DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'), DB::raw('COALESCE(d.libelle, f.departement) AS departement')]);
        $resultats = DB::table('resultats_semestre')->where('pv_id', $pv->id)->get();
        $admis = $resultats->where('valide', 1)->where('defaillant', 0);

        return view('notes.deliberation.document', [
            'pv' => $pv,
            'faculte' => $libelles->faculte ?? '',
            'departement' => $libelles->departement ?? '',
            'stats' => [
                'total' => $resultats->count(),
                'admis' => $admis->count(),
                // Défaillant : une note manque ; Elim : note ou moyenne d'UE éliminatoire
                'defaillants' => $resultats->where('defaillant', 1)->where('decision', '!=', 'Elim')->count(),
                'elimines' => $resultats->where('decision', 'Elim')->count(),
                'ajournes' => $resultats->where('valide', 0)->where('defaillant', 0)->count(),
            ],
            'admisListe' => DB::table('resultats_semestre as rs')->join('etudiants as e', 'e.id', '=', 'rs.etudiant_id')
                ->where('rs.pv_id', $pv->id)->where('rs.valide', 1)->where('rs.defaillant', 0)->orderBy('rs.rang')->orderBy('e.nom')
                ->get(['e.nom', 'e.prenoms', 'e.numero', 'e.date_naissance', 'e.lieu_naissance', 'rs.moyenne_generale', 'rs.mention', 'rs.rang', 'rs.credits_obtenus']),
            'logos' => $identite->logos($pv->faculte_id ? (int) $pv->faculte_id : null),
            'republique' => $parametres->valeur('institution_republique', 'République du Niger'),
            'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
            'ville' => $parametres->valeur('institution_ville', 'Tahoua'),
        ]);
    }
}
