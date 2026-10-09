<?php

namespace App\Http\Controllers\Rapports;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Tableau de bord statistiques — portage de pages/admin/stats.php :
 * inscrits, admis (décisions de passage), taux de réussite, répartition des
 * décisions et des mentions, évolution pluriannuelle, résultats par filière
 * et niveau ; limité à la faculté de l'utilisateur.
 *
 * Différences voulues : graphiques réellement affichés (Chart.js local ; le
 * CDN était bloqué par la politique de sécurité et deux graphiques visaient
 * des éléments inexistants) ; filtre faculté passé en paramètre lié ;
 * répartition des décisions par étudiant inscrit, total = inscrits (avant :
 * « Admis » tiré des passages de niveau, ajournés et défaillants comptés par
 * résultat de semestre de session normale — les chiffres ne correspondaient
 * pas aux compteurs) : admis (passage ou licence validée) / redoublants
 * (tous les autres) ; mentions des semestres
 * validés après rattrapage, regroupées quelle que soit la casse. Compteurs,
 * répartition, tableau par filière et évolution reposent sur ce même
 * classement (une décision par étudiant et par année : les doublons de
 * passages_niveau étaient additionnés).
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

        // Un classement par étudiant inscrit et par année, commun aux compteurs, à la
        // répartition, au tableau par filière et à l'évolution : tous concordent
        $classes = $this->classer($filiereId, $fac);
        $annee = $classes->where('annee_id', $anneeId);
        $compter = fn ($lignes) => ['admis' => $lignes->where('categorie', 'admis')->count(), 'redoublants' => $lignes->where('categorie', 'redoublants')->count()];
        $tauxPassage = fn (array $c) => $c['admis'] + $c['redoublants'] > 0 ? round($c['admis'] / ($c['admis'] + $c['redoublants']) * 100, 1) : 0;
        $repartition = $compter($annee);
        $inscrits = $annee->count();
        $admis = $repartition['admis'];

        $base = $annee->groupBy(fn ($l) => $l->filiere_id.'-'.$l->niveau_id)
            ->map(function ($lignes) use ($compter, $tauxPassage) {
                $c = $compter($lignes);
                $l = $lignes->first();

                return (object) ['filiere' => $l->filiere, 'niveau' => $l->niveau, 'inscrits' => $lignes->count(),
                    'passages' => $c['admis'], 'redoublants' => $c['redoublants'], 'taux' => $tauxPassage($c)];
            })->sortBy(fn ($l) => $l->filiere.'|'.$l->niveau)->values();

        $evolution = $classes->groupBy('annee_id')->map(function ($lignes) use ($compter, $tauxPassage) {
            $c = $compter($lignes);

            return (object) ['annee' => $lignes->first()->annee, 'inscrits' => $lignes->count(), 'passages' => $c['admis'],
                'redoublants' => $c['redoublants'], 'taux' => $tauxPassage($c)];
        })->sortBy('annee')->values();

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
            'graphiques' => ['decisions' => array_values($repartition),
                'mentions' => ['libelles' => $mentions->pluck('mention'), 'valeurs' => $mentions->pluck('nb')->map(fn ($v) => (int) $v)],
                'annees' => $evolution->pluck('annee'), 'evInscrits' => $evolution->pluck('inscrits')->map(fn ($v) => (int) $v),
                'evTaux' => $evolution->pluck('taux')->map(fn ($v) => (float) $v)],
        ]);
    }

    /**
     * Étudiants inscrits, un par année (dernière inscription s'il en a deux), avec
     * leur catégorie (décision de l'université) : admis s'ils passent au niveau
     * suivant ou valident la licence (dernière décision non vide de l'année : les
     * bases contiennent des doublons, que l'ancien calcul additionnait ; une
     * décision vide est une licence validée mal enregistrée) ;
     * redoublants tous les autres, y compris sans décision enregistrée.
     */
    private function classer(int $filiereId, int $fac): Collection
    {
        $perimetre = fn ($q) => $q->when($filiereId, fn ($w) => $w->where('f.id', $filiereId))->when($fac, fn ($w) => $w->where('f.faculte_id', $fac));
        $inscrits = $perimetre(DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->where('i.statut', 'inscrit'))->orderBy('i.id')
            ->get(['i.etudiant_id', 'p.annee_id', 'a.libelle as annee', 'f.id as filiere_id', 'f.libelle as filiere', 'n.id as niveau_id', 'n.code as niveau'])
            ->keyBy(fn ($l) => $l->annee_id.'-'.$l->etudiant_id);
        // Décision vide = « licence_validee » refusée par une colonne decision trop ancienne
        // (MySQL non strict l'enregistrait vide) — réparé par gesnotes:reparer-passages
        $decisions = DB::table('passages_niveau')->whereIn('decision', ['passage', 'licence_validee', 'redoublement', ''])->orderBy('id')
            ->get(['etudiant_id', 'annee_depart_id', 'decision'])
            ->mapWithKeys(fn ($d) => [$d->annee_depart_id.'-'.$d->etudiant_id => $d->decision === '' ? 'licence_validee' : $d->decision]);

        return $inscrits->map(function ($l, $cle) use ($decisions) {
            $l->categorie = in_array($decisions[$cle] ?? null, ['passage', 'licence_validee'], true) ? 'admis' : 'redoublants';

            return $l;
        })->values();
    }
}
