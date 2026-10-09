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
 * des éléments inexistants) ; filtre faculté passé en paramètre lié ;
 * décisions et mentions calculées sur le résultat FINAL de chaque semestre
 * (rattrapage s'il a eu lieu, sinon session normale) et d'une seule source
 * (avant : « Admis » tiré des passages de niveau, ajournés et défaillants
 * des résultats de session normale — un admis au rattrapage restait compté
 * ajourné) ; mentions regroupées quelle que soit la casse.
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
        // Résultat final de chaque semestre : celui du rattrapage s'il existe pour
        // l'étudiant (même promotion, même semestre), sinon celui de la session normale
        $resultats = fn () => $filtres(DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id'))
            ->whereIn('ps.type_session', ['normale', 'rattrapage'])
            ->where(fn ($q) => $q->where('ps.type_session', 'rattrapage')->orWhereNotExists(fn ($s) => $s->from('resultats_semestre as rr')
                ->join('pv_sessions as pr', 'pr.id', '=', 'rr.pv_id')->where('pr.type_session', 'rattrapage')
                ->whereColumn('rr.etudiant_id', 'rs.etudiant_id')->whereColumn('pr.promotion_id', 'ps.promotion_id')->whereColumn('pr.semestre_id', 'ps.semestre_id')));

        $inscrits = (int) $filtres(DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->where('i.statut', 'inscrit'))->distinct()->count('i.etudiant_id');
        $passages = $filtres(DB::table('passages_niveau as pn')->join('filieres as f', 'f.id', '=', 'pn.filiere_id'), 'pn.annee_depart_id', 'pn.filiere_id')
            ->groupBy('pn.decision')->get(['pn.decision', DB::raw('COUNT(*) AS nb')])->pluck('nb', 'decision');
        $admis = (int) ($passages['passage'] ?? 0) + (int) ($passages['licence_validee'] ?? 0);
        $decisions = $resultats()->selectRaw("SUM(rs.valide = 1) AS admis,
                SUM(rs.valide = 0 AND (rs.defaillant = 1 OR rs.decision LIKE 'D%faillant%')) AS defaillants, COUNT(*) AS total")->first();

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
        // Mentions des semestres validés, regroupées sans tenir compte de la casse (« Assez Bien » / « Assez bien »)
        $parMention = $resultats()->where('rs.valide', 1)->groupBy(DB::raw('LOWER(TRIM(rs.mention))'))
            ->get([DB::raw('LOWER(TRIM(rs.mention)) AS mention'), DB::raw('COUNT(*) AS nb')])->pluck('nb', 'mention');
        $mentions = collect(['très bien' => 'Très bien', 'bien' => 'Bien', 'assez bien' => 'Assez bien', 'passable' => 'Passable'])
            ->map(fn ($libelle, $cle) => (object) ['mention' => $libelle, 'nb' => (int) ($parMention[$cle] ?? 0)])->values();

        return view('rapports.statistiques', [
            'annees' => $annees, 'anneeId' => $anneeId, 'filiereId' => $filiereId,
            'filieres' => DB::table('filieres as f')->when($fac, fn ($w) => $w->where('f.faculte_id', $fac))->orderBy('libelle')->get(['id', 'libelle']),
            'inscrits' => $inscrits, 'admis' => $admis, 'taux' => $inscrits > 0 ? round($admis / $inscrits * 100, 1) : 0,
            'lignes' => $base, 'evolution' => $evolution,
            'graphiques' => ['decisions' => [(int) $decisions->admis, (int) $decisions->total - (int) $decisions->admis - (int) $decisions->defaillants, (int) $decisions->defaillants],
                'mentions' => ['libelles' => $mentions->pluck('mention'), 'valeurs' => $mentions->pluck('nb')->map(fn ($v) => (int) $v)],
                'annees' => $evolution->pluck('annee'), 'evInscrits' => $evolution->pluck('inscrits')->map(fn ($v) => (int) $v),
                'evTaux' => $evolution->pluck('taux')->map(fn ($v) => (float) $v)],
        ]);
    }
}
