<?php

namespace App\Services;

use App\Http\Controllers\Structure\EmploiTempsController as EDT;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Données de suivi d'un étudiant, communes à l'application mobile de
 * l'étudiant et à celle de ses parents : notes et résultats (PV finalisés ou
 * publiés seulement), inscription de l'année, paiements, présences, emploi
 * du temps, planning des examens, annonces.
 */
class SuiviEtudiant
{
    /**
     * Notes par matière (rattrapage s'il existe, sinon session normale ; notes
     * de 1ʳᵉ session gardées pour comparaison) et résultats par semestre.
     */
    public function notes(int $id, int $anneeId = 0): array
    {
        $lignes = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('niveaux as nv', 'nv.id', '=', 'p.niveau_id')->join('unites_enseignement as ue', 'ue.promotion_id', '=', 'p.id')
            ->join('semestres as s', 's.id', '=', 'ue.semestre_id')->join('matieres as m', fn ($j) => $j->on('m.ue_id', '=', 'ue.id')->where('m.actif', 1))
            ->leftJoin('pv_sessions as ps1', fn ($j) => $j->on('ps1.promotion_id', '=', 'p.id')->on('ps1.semestre_id', '=', 'ue.semestre_id')
                ->where('ps1.type_session', 'normale')->whereIn('ps1.statut', Pv::CONSULTABLES))
            ->leftJoin('notes as n1', fn ($j) => $j->on('n1.pv_id', '=', 'ps1.id')->on('n1.matiere_id', '=', 'm.id')->where('n1.etudiant_id', $id))
            ->leftJoin('pv_sessions as ps2', fn ($j) => $j->on('ps2.pv_normale_id', '=', 'ps1.id')->where('ps2.type_session', 'rattrapage')->whereIn('ps2.statut', Pv::CONSULTABLES))
            ->leftJoin('notes as n2', fn ($j) => $j->on('n2.pv_id', '=', 'ps2.id')->on('n2.matiere_id', '=', 'm.id')->where('n2.etudiant_id', $id))
            ->where('i.etudiant_id', $id)->where('i.statut', 'inscrit')->when($anneeId, fn ($q) => $q->where('a.id', $anneeId))
            ->orderByDesc('a.libelle')->orderBy('s.code')->orderBy('ue.ordre')->orderBy('m.ordre')
            ->get(['m.libelle as matiere', 'm.coefficient', 'm.note_max_totale', 'm.note_max_cc', 'm.note_max_examen', 'ue.code as ue_code', 'ue.libelle as ue_lib',
                'ue.credits_total', 's.code as sem_code', 's.libelle as semestre', 'a.libelle as annee', 'a.id as annee_id', 'nv.code as niveau',
                'n1.note_cc as note_cc_s1', 'n1.note_examen as note_examen_s1', 'n1.note_finale as note_finale_s1', 'n1.observation as obs_s1', 'n1.defaillant as defaillant_s1',
                'n2.note_cc as note_cc_s2', 'n2.note_examen as note_examen_s2', 'n2.note_finale as note_finale_s2', 'n2.observation as obs_s2', 'n2.defaillant as defaillant_s2',
                DB::raw('COALESCE(ps2.type_session, ps1.type_session) AS session_active'), 'ps1.id as pv_normale_id', 'ps2.id as pv_ratt_id']);
        $notes = $lignes->map(function ($n) {
            $ratt = $n->note_finale_s2 !== null || $n->obs_s2 !== null;
            foreach (['note_cc', 'note_examen', 'note_finale', 'defaillant'] as $c) {
                $n->$c = $ratt ? $n->{$c.'_s2'} : $n->{$c.'_s1'};
            }
            $n->observation = $ratt ? $n->obs_s2 : $n->obs_s1;
            $n->has_rattrapage = $ratt ? 1 : 0;

            return $n;
        });
        $resultats = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('niveaux as nv', 'nv.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->where('rs.etudiant_id', $id)->whereIn('ps.statut', Pv::CONSULTABLES)->orderByDesc('a.libelle')->orderBy('s.code')
            ->get(['rs.valide', 'rs.mention', 'rs.moyenne_generale', 'rs.rang', 'rs.credits_obtenus', 'rs.decision', 'a.libelle as annee', 'a.id as annee_id',
                'nv.code as niveau', 's.libelle as semestre', 'ps.type_session']);

        return ['notes' => $notes, 'resultats' => $resultats];
    }

    /** Inscription de l'année active (filière, niveau, année, promotion), ou la plus récente. */
    public function inscription(int $id): ?object
    {
        return DB::table('inscriptions as i')->join('annees_academiques as a', 'a.id', '=', 'i.annee_id')
            ->leftJoin('promotions as p', 'p.id', '=', 'i.promotion_id')->leftJoin('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->leftJoin('niveaux as n', 'n.id', '=', DB::raw('COALESCE(i.niveau_id, p.niveau_id)'))
            ->where(['i.etudiant_id' => $id, 'i.statut' => 'inscrit'])->orderByDesc('a.active')->orderByDesc('a.libelle')
            ->first(['i.id', 'i.promotion_id', 'i.annee_id', 'a.libelle as annee', 'a.active', 'f.libelle as filiere', 'f.faculte',
                'n.code as niveau', 'n.libelle as niveau_lib']);
    }

    /** Paiements récents et totaux. */
    public function paiements(int $id): array
    {
        $paiements = DB::table('paiements')->where('etudiant_id', $id)->orderByDesc('date_paiement')->limit(20)
            ->get(['id', 'type_paiement', 'montant_du', 'montant_paye', 'date_paiement', 'mode_paiement', 'reference', 'statut']);
        $du = (float) $paiements->sum('montant_du');
        $paye = (float) $paiements->sum('montant_paye');

        return ['paiements' => $paiements, 'total_du' => $du, 'total_paye' => $paye, 'reste' => max(0, $du - $paye)];
    }

    /** 30 dernières présences et taux de présence. */
    public function presences(int $id): array
    {
        $presences = DB::table('presences as pr')->join('seances as se', 'se.id', '=', 'pr.seance_id')->join('matieres as m', 'm.id', '=', 'se.matiere_id')
            ->where('pr.etudiant_id', $id)->orderByDesc('se.date_seance')->limit(30)
            ->get(['pr.statut', 'pr.motif', 'se.date_seance', 'se.heure_debut', 'se.type_seance', 'm.libelle as matiere']);

        return ['presences' => $presences, 'absences' => $presences->where('statut', 'absent')->count(),
            'taux' => $presences->isEmpty() ? null : (int) round($presences->whereIn('statut', ['present', 'retard'])->count() / $presences->count() * 100)];
    }

    /** Séances de la promotion de l'année (semestres où l'étudiant est inscrit, sinon tous), par jour. */
    public function emploiDuTemps(int $id): array
    {
        $ins = $this->inscription($id);
        if (! $ins || ! $ins->promotion_id) {
            return [];
        }
        $semestres = DB::table('inscriptions_pedagogiques')->where(['etudiant_id' => $id, 'annee_id' => $ins->annee_id, 'statut' => 'actif'])->pluck('semestre_id');
        $seances = DB::table('emploi_temps as et')->join('semestres as s', 's.id', '=', 'et.semestre_id')
            ->leftJoin('matieres as m', 'm.id', '=', 'et.matiere_id')->leftJoin('enseignants as e', 'e.id', '=', 'et.enseignant_id')
            ->where(['et.promotion_id' => $ins->promotion_id, 'et.annee_id' => $ins->annee_id])
            ->when($semestres->isNotEmpty(), fn ($q) => $q->whereIn('et.semestre_id', $semestres))
            ->orderByRaw("FIELD(et.jour, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi')")->orderBy('et.heure_debut')
            ->get(['et.jour', 'et.heure_debut', 'et.heure_fin', 'et.salle', 'et.type_seance', 's.code as semestre', 'm.libelle as matiere',
                DB::raw("TRIM(CONCAT(COALESCE(e.prenoms, ''), ' ', COALESCE(e.nom, ''))) AS enseignant")]);

        return collect(EDT::JOURS)->map(fn ($j) => ['jour' => $j, 'seances' => $seances->where('jour', $j)->values()])->all();
    }

    /** Examens planifiés de la promotion de l'année (normale et rattrapage). */
    public function examens(int $id): array
    {
        $ins = $this->inscription($id);
        if (! $ins || ! $ins->promotion_id || ! Schema::hasTable('planning_examens')) {
            return [];
        }

        return DB::table('planning_examens as pe')->join('matieres as m', 'm.id', '=', 'pe.matiere_id')->join('semestres as s', 's.id', '=', 'pe.semestre_id')
            ->where('pe.promotion_id', $ins->promotion_id)->orderBy('pe.date_examen')->orderBy('pe.heure_debut')
            ->get(['pe.date_examen', 'pe.heure_debut', 'pe.heure_fin', 'pe.salle', 'pe.session', 's.code as semestre', 'm.libelle as matiere'])
            ->each(fn ($x) => $x->passe = $x->date_examen < now()->toDateString())->values()->all();
    }

    /** Annonces publiées et en cours pour l'étudiant (tous, étudiants, sa filière, son niveau actuel). */
    public function annonces(object $etudiant): Builder
    {
        $niveau = DB::table('inscriptions as i')->join('annees_academiques as a', 'a.id', '=', 'i.annee_id')
            ->where(['i.etudiant_id' => $etudiant->id, 'i.statut' => 'inscrit', 'a.active' => 1])->value('i.niveau_id');

        return DB::table('annonces')->where('publiee', 1)->whereDate('date_debut', '<=', now())
            ->where(fn ($w) => $w->whereNull('date_fin')->orWhereDate('date_fin', '>=', now()))
            ->where(fn ($w) => $w->whereIn('cible', ['tous', 'etudiants'])
                ->orWhere(fn ($f) => $f->where('cible', 'filiere')->where('filiere_id', (int) ($etudiant->filiere_id ?? 0)))
                ->orWhere(fn ($n) => $n->where('cible', 'niveau')->where('niveau_id', (int) ($niveau ?? 0))));
    }
}
