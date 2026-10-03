<?php

namespace App\Http\Controllers\Rapports;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Historique académique d'un étudiant — portage de
 * pages/admin/historique.php (admin, secrétaire, sc_centrale, sc_entite,
 * doyen, chef de département).
 *
 * Différences voulues :
 *  - recherche et consultation limitées aux étudiants du périmètre de
 *    l'utilisateur (faculté, département) : n'importe quel historique
 *    était lisible ;
 *  - résultats enregistrés, sans revérification « en direct » des
 *    défaillances (même choix que les relevés, étape 5) ;
 *  - l'étudiant consulte ses résultats dans son portail (il est cantonné
 *    à celui-ci).
 */
class HistoriqueController extends Controller
{
    public function __invoke(Request $request, Perimetre $perimetre, Journal $journal): View
    {
        $u = $request->user();
        $q = trim((string) $request->query('q', ''));
        $etudiants = $q !== '' ? $perimetre->limiterEtudiants(DB::table('etudiants as e'), $u)
            ->where(fn ($w) => $w->where('e.nom', 'like', "%$q%")->orWhere('e.prenoms', 'like', "%$q%")->orWhere('e.numero', 'like', "%$q%"))
            ->orderBy('e.nom')->orderBy('e.prenoms')->limit(20)->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero']) : collect();
        $etId = (int) $request->query('et_id');
        $et = $etId ? $perimetre->limiterEtudiants(DB::table('etudiants as e'), $u)->where('e.id', $etId)->first(['e.*']) : null;
        $historique = collect();
        if ($et) {
            $journal->action('consulter', 'etudiant', "Consultation historique académique : {$et->nom} {$et->prenoms}", (int) $et->id);
            $historique = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
                ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
                ->where('rs.etudiant_id', $et->id)->orderByDesc('a.libelle')->orderBy('s.code')->orderBy('ps.type_session')
                ->get(['ps.id as pv_id', 'ps.type_session', 'a.libelle as annee', 's.libelle as sem_label', 'f.libelle as filiere', 'n.libelle as niv_label',
                    'rs.moyenne_generale', 'rs.mention', 'rs.decision', 'rs.valide', 'rs.credits_obtenus',
                    DB::raw('(SELECT COALESCE(SUM(ue.credits_total), 0) FROM unites_enseignement ue WHERE ue.promotion_id = ps.promotion_id AND ue.semestre_id = ps.semestre_id) AS credits_total')])
                ->groupBy('annee');
        }

        return view('rapports.historique', ['q' => $q, 'etudiants' => $etudiants, 'et' => $et, 'historique' => $historique, 'etId' => $etId]);
    }
}
