<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tableau de bord de pilotage (recteur, doyens, chefs de département) pour
 * une année académique, limité au périmètre (faculté, département) :
 *  - réussite par filière, niveau et semestre (résultat de rattrapage s'il
 *    existe, sinon de session normale) ;
 *  - notes non finalisées par matière (examen, TD), avec les enseignants
 *    affectés et le retard depuis la date de l'examen planifié ;
 *  - retards de saisie regroupés par enseignant ;
 *  - présence aux séances par filière et niveau.
 */
class Pilotage
{
    public function donnees(int $anneeId, ?int $faculteId = null, ?int $departementId = null): array
    {
        $perimetre = fn ($q) => $q->when($faculteId, fn ($w) => $w->where('f.faculte_id', $faculteId))
            ->when($departementId, fn ($w) => $w->where('f.departement_id', $departementId));
        $reussite = $this->reussite($anneeId, $perimetre);
        $nonFinalisees = $this->nonFinalisees($anneeId, $perimetre);
        $presence = $this->presence($anneeId, $perimetre);
        $effectif = $reussite->sum('effectif');
        $totalPresence = $presence->sum('total');

        return [
            'resume' => [
                'taux_reussite' => $effectif > 0 ? round($reussite->sum('valides') / $effectif * 100, 1) : null,
                'effectif' => $effectif,
                'non_finalisees' => $nonFinalisees->count(),
                'en_retard' => $nonFinalisees->whereNotNull('retard')->count(),
                'enseignants_en_retard' => $this->retards($nonFinalisees)->count(),
                'taux_presence' => $totalPresence > 0 ? round($presence->sum('presents') / $totalPresence * 100, 1) : null,
            ],
            'reussite' => $reussite,
            'non_finalisees' => $nonFinalisees,
            'retards' => $this->retards($nonFinalisees),
            'presence' => $presence,
        ];
    }

    /** Réussite par filière, niveau et semestre. */
    private function reussite(int $anneeId, callable $perimetre): Collection
    {
        $lignes = $perimetre(DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->where('p.annee_id', $anneeId)->whereIn('ps.type_session', ['normale', 'rattrapage']))
            ->get(['rs.etudiant_id', 'rs.valide', 'rs.moyenne_generale', 'ps.type_session', 'p.id as promotion_id', 's.id as semestre_id',
                'f.libelle as filiere', 'n.code as niveau', 's.libelle as semestre']);

        // Un résultat par étudiant et semestre : le rattrapage remplace la session normale
        return $lignes->groupBy(fn ($l) => $l->promotion_id.'-'.$l->semestre_id.'-'.$l->etudiant_id)
            ->map(fn ($g) => $g->firstWhere('type_session', 'rattrapage') ?? $g->first())
            ->groupBy(fn ($l) => $l->filiere.'|'.$l->niveau.'|'.$l->semestre)
            ->map(function ($g) {
                $l = $g->first();
                $valides = $g->where('valide', 1)->count();

                return (object) ['filiere' => $l->filiere, 'niveau' => $l->niveau, 'semestre' => $l->semestre, 'effectif' => $g->count(),
                    'valides' => $valides, 'taux' => round($valides / $g->count() * 100, 1),
                    'moyenne' => $g->whereNotNull('moyenne_generale')->avg('moyenne_generale'),
                    'rattrapage' => $g->contains('type_session', 'rattrapage')];
            })->sortBy(fn ($r) => $r->filiere.'|'.$r->niveau.'|'.$r->semestre)->values();
    }

    /**
     * Composantes (examen, TD) non finalisées dans les PV de session de
     * l'année : une ligne par PV et matière, avec les enseignants affectés et
     * le retard (jours depuis l'examen planifié, s'il est passé).
     */
    private function nonFinalisees(int $anneeId, callable $perimetre): Collection
    {
        if (! Schema::hasTable('notes_finalisations')) {
            return collect();
        }
        $lignes = $perimetre(DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->join('unites_enseignement as ue', fn ($j) => $j->on('ue.promotion_id', '=', 'ps.promotion_id')->on('ue.semestre_id', '=', 'ps.semestre_id'))
            ->join('matieres as m', fn ($j) => $j->on('m.ue_id', '=', 'ue.id')->where('m.actif', 1))
            ->where('p.annee_id', $anneeId)->whereIn('ps.type_session', ['normale', 'rattrapage']))
            ->get(['ps.id as pv_id', 'ps.type_session', 'ps.verrouille', 'ps.promotion_id', 'ps.semestre_id', 'm.id as matiere_id', 'm.libelle as matiere',
                'm.note_max_cc', 'm.finalisee', 'f.libelle as filiere', 'n.code as niveau', 's.libelle as semestre']);
        $faites = DB::table('notes_finalisations')->whereIn('pv_id', $lignes->pluck('pv_id')->unique())->get()
            ->groupBy(fn ($f) => $f->pv_id.'-'.$f->matiere_id)->map->pluck('composante');
        $brouillons = DB::table('notes_enseignants')->whereIn('pv_id', $lignes->pluck('pv_id')->unique())
            ->groupBy('pv_id', 'matiere_id')->get(['pv_id', 'matiere_id', DB::raw('COUNT(*) AS nb')])->keyBy(fn ($b) => $b->pv_id.'-'.$b->matiere_id);
        $enseignants = DB::table('affectations as a')->join('enseignants as e', 'e.id', '=', 'a.enseignant_id')
            ->where('a.annee_id', $anneeId)->whereIn('a.matiere_id', $lignes->pluck('matiere_id')->unique())
            ->get(['a.matiere_id', 'a.type_charge', 'e.id', 'e.nom', 'e.prenoms'])->groupBy('matiere_id');
        $examens = Schema::hasTable('planning_examens')
            ? DB::table('planning_examens')->whereIn('matiere_id', $lignes->pluck('matiere_id')->unique())->get()
                ->keyBy(fn ($x) => $x->promotion_id.'-'.$x->semestre_id.'-'.$x->session.'-'.$x->matiere_id)
            : collect();
        $aujourdhui = Carbon::today();

        return $lignes->map(function ($l) use ($faites, $brouillons, $enseignants, $examens, $aujourdhui) {
            $cle = $l->pv_id.'-'.$l->matiere_id;
            $requises = (float) $l->note_max_cc > 0 && $l->type_session !== 'rattrapage' ? ['exam', 'cc'] : ['exam'];
            $finies = $faites->get($cle, collect())->all();
            // Finalisée avant la finalisation par composante (session normale)
            if (! $finies && $l->type_session === 'normale' && $l->finalisee && ! $brouillons->has($cle)) {
                $finies = $requises;
            }
            $manquantes = array_values(array_diff($requises, $finies));
            if (! $manquantes) {
                return null;
            }
            $examen = $examens->get($l->promotion_id.'-'.$l->semestre_id.'-'.$l->type_session.'-'.$l->matiere_id);
            $date = $examen ? Carbon::parse($examen->date_examen) : null;
            $profs = $enseignants->get($l->matiere_id, collect())
                ->filter(fn ($a) => in_array('exam', $manquantes, true) ? $a->type_charge !== 'td' : $a->type_charge !== 'cours');

            return (object) [
                'pv_id' => $l->pv_id, 'matiere' => $l->matiere, 'filiere' => $l->filiere, 'niveau' => $l->niveau, 'semestre' => $l->semestre,
                'session' => $l->type_session, 'verrouille' => (bool) $l->verrouille,
                'manquantes' => array_map(fn ($c) => $c === 'cc' ? 'TD' : 'Examen', $manquantes),
                'en_attente' => (int) ($brouillons->get($cle)->nb ?? 0),
                'enseignants' => $profs->map(fn ($a) => ['id' => $a->id, 'nom' => trim($a->nom.' '.$a->prenoms)])->unique('id')->values()->all(),
                'date_examen' => $date?->toDateString(),
                'retard' => $date && $date->lt($aujourdhui) ? (int) $date->diffInDays($aujourdhui) : null,
            ];
        })->filter()->sortByDesc(fn ($r) => $r->retard ?? -1)->values();
    }

    /** Matières en retard (examen passé, notes non finalisées) regroupées par enseignant. */
    private function retards(Collection $nonFinalisees): Collection
    {
        $parEnseignant = [];
        foreach ($nonFinalisees->whereNotNull('retard') as $r) {
            foreach ($r->enseignants as $e) {
                $parEnseignant[$e['id']] ??= (object) ['nom' => $e['nom'], 'matieres' => [], 'retard_max' => 0];
                $parEnseignant[$e['id']]->matieres[] = $r->matiere.' ('.$r->filiere.' '.$r->niveau.($r->session === 'rattrapage' ? ', rattrapage' : '').')';
                $parEnseignant[$e['id']]->retard_max = max($parEnseignant[$e['id']]->retard_max, $r->retard);
            }
        }

        return collect($parEnseignant)->sortByDesc('retard_max')->values();
    }

    /** Présence aux séances par filière et niveau. */
    private function presence(int $anneeId, callable $perimetre): Collection
    {
        return $perimetre(DB::table('presences as pr')->join('seances as se', 'se.id', '=', 'pr.seance_id')
            ->join('promotions as p', 'p.id', '=', 'se.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->where('p.annee_id', $anneeId))
            ->groupBy('f.libelle', 'n.code')
            ->orderBy('f.libelle')->orderBy('n.code')
            ->get(['f.libelle as filiere', 'n.code as niveau', DB::raw('COUNT(*) AS total'), DB::raw("SUM(pr.statut IN ('present','retard')) AS presents"),
                DB::raw("SUM(pr.statut = 'absent') AS absents"), DB::raw('COUNT(DISTINCT se.id) AS seances')])
            ->map(function ($r) {
                $r->presents = (int) $r->presents;
                $r->absents = (int) $r->absents;
                $r->taux = $r->total > 0 ? round($r->presents / $r->total * 100, 1) : null;

                return $r;
            });
    }
}
