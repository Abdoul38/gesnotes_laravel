<?php

namespace App\Http\Controllers\Rapports;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Tableau de bord statistiques — portage de pages/admin/stats.php :
 * inscrits, admis (décisions de passage), taux de réussite, décisions et
 * mentions de la session normale, évolution pluriannuelle, résultats par
 * filière et niveau ; limité à la faculté de l'utilisateur.
 *
 * Différences voulues : graphiques réellement affichés (Chart.js local ; le
 * CDN était bloqué par la politique de sécurité et deux graphiques visaient
 * des éléments inexistants) ; filtre faculté passé en paramètre lié.
 */
class StatistiquesController extends Controller
{
    public function __invoke(Request $request, Perimetre $perimetre): View
    {
        $fac = $perimetre->faculte($request->user());
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeId = (int) $request->query('annee_id') ?: (int) ($annees->firstWhere('active', 1)->id ?? $annees->first()->id ?? 0);
        $filiereId = (int) $request->query('filiere_id');
        $filtres = function ($q, string $annee = 'a.id', string $filiere = 'f.id') use ($anneeId, $filiereId, $fac) {
            return $q->when($anneeId, fn ($w) => $w->where($annee, $anneeId))->when($filiereId, fn ($w) => $w->where($filiere, $filiereId))
                ->when($fac, fn ($w) => $w->where('f.faculte_id', $fac));
        };
        $resultats = fn () => $filtres(DB::table('resultats_semestre as rs')->join('pv_sessions as ps', fn ($j) => $j->on('ps.id', '=', 'rs.pv_id')->where('ps.type_session', 'normale'))
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id'));

        $inscrits = (int) $filtres(DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->where('i.statut', 'inscrit'))->distinct()->count('i.etudiant_id');
        $passages = $filtres(DB::table('passages_niveau as pn')->join('filieres as f', 'f.id', '=', 'pn.filiere_id'), 'pn.annee_depart_id', 'pn.filiere_id')
            ->groupBy('pn.decision')->get(['pn.decision', DB::raw('COUNT(*) AS nb')])->pluck('nb', 'decision');
        $admis = (int) ($passages['passage'] ?? 0) + (int) ($passages['licence_validee'] ?? 0);
        $decisions = $resultats()->groupBy('rs.decision')->get(['rs.decision', DB::raw('COUNT(*) AS nb')])->pluck('nb', 'decision');

        $base = $filtres(DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->leftJoin('pv_sessions as ps', fn ($j) => $j->on('ps.promotion_id', '=', 'p.id')->where('ps.type_session', 'normale'))
            ->leftJoin('resultats_semestre as rs', fn ($j) => $j->on('rs.pv_id', '=', 'ps.id')->on('rs.etudiant_id', '=', 'i.etudiant_id'))
            ->where('i.statut', 'inscrit'))->groupBy('f.id', 'f.libelle', 'n.id', 'n.code')->orderBy('f.libelle')->orderBy('n.code')
            ->get(['f.libelle as filiere', 'f.id as filiere_id', 'n.code as niveau', 'n.id as niveau_id', DB::raw('COUNT(DISTINCT i.etudiant_id) AS inscrits')]);
        $parFiliere = $filtres(DB::table('passages_niveau as pn')->join('filieres as f', 'f.id', '=', 'pn.filiere_id'), 'pn.annee_depart_id', 'pn.filiere_id')
            ->groupBy('pn.filiere_id', 'pn.niveau_actuel_id')
            ->get(['pn.filiere_id', 'pn.niveau_actuel_id as niveau_id', DB::raw("SUM(pn.decision IN ('passage','licence_validee')) AS passages"), DB::raw("SUM(pn.decision = 'redoublement') AS redoublants")])
            ->keyBy(fn ($r) => $r->filiere_id.'-'.$r->niveau_id);
        foreach ($base as $l) {
            $p = $parFiliere[$l->filiere_id.'-'.$l->niveau_id] ?? null;
            $l->passages = (int) ($p->passages ?? 0);
            $l->redoublants = (int) ($p->redoublants ?? 0);
            $l->taux = $l->passages + $l->redoublants > 0 ? round($l->passages / ($l->passages + $l->redoublants) * 100, 1) : 0;
        }

        $evolution = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->leftJoin('passages_niveau as pn', fn ($j) => $j->on('pn.etudiant_id', '=', 'i.etudiant_id')->on('pn.filiere_id', '=', 'f.id')->on('pn.annee_depart_id', '=', 'a.id'))
            ->where('i.statut', 'inscrit')->when($filiereId, fn ($w) => $w->where('f.id', $filiereId))->when($fac, fn ($w) => $w->where('f.faculte_id', $fac))
            ->groupBy('a.id', 'a.libelle')->orderBy('a.libelle')
            ->get(['a.libelle as annee', DB::raw('COUNT(DISTINCT i.etudiant_id) AS inscrits'), DB::raw("COALESCE(SUM(pn.decision IN ('passage','licence_validee')), 0) AS passages"),
                DB::raw("COALESCE(SUM(pn.decision = 'redoublement'), 0) AS redoublants"),
                DB::raw("COALESCE(ROUND(SUM(pn.decision IN ('passage','licence_validee')) * 100.0 / NULLIF(COUNT(DISTINCT CASE WHEN pn.decision IS NOT NULL THEN i.etudiant_id END), 0), 1), 0) AS taux")]);
        $mentions = $resultats()->where('rs.valide', 1)->groupBy('rs.mention')->orderByRaw("FIELD(rs.mention, 'Très Bien', 'Bien', 'Assez Bien', 'Passable')")
            ->get(['rs.mention', DB::raw('COUNT(*) AS nb')]);

        return view('rapports.statistiques', [
            'annees' => $annees, 'anneeId' => $anneeId, 'filiereId' => $filiereId,
            'filieres' => DB::table('filieres as f')->when($fac, fn ($w) => $w->where('f.faculte_id', $fac))->orderBy('libelle')->get(['id', 'libelle']),
            'inscrits' => $inscrits, 'admis' => $admis, 'taux' => $inscrits > 0 ? round($admis / $inscrits * 100, 1) : 0,
            'lignes' => $base, 'evolution' => $evolution,
            'graphiques' => ['decisions' => [$admis, (int) ($decisions['Ajourné'] ?? 0), (int) ($decisions['Défaillant'] ?? 0)],
                'mentions' => ['libelles' => $mentions->pluck('mention'), 'valeurs' => $mentions->pluck('nb')->map(fn ($v) => (int) $v)],
                'annees' => $evolution->pluck('annee'), 'evInscrits' => $evolution->pluck('inscrits')->map(fn ($v) => (int) $v),
                'evTaux' => $evolution->pluck('taux')->map(fn ($v) => (float) $v)],
        ]);
    }
}
