<?php

namespace App\Console\Commands;

use App\Services\Resultats;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Résultats de semestre enregistrés avant la distinction « Défaillant » /
 * « Elim » : un étudiant défaillant sans aucune case vide dans le PV
 * (examen, ou TD s'il est prévu, comme Resultats::caseVide()) l'est pour
 * une note ou une moyenne d'UE éliminatoire → décision et mention « Elim », comme le moteur les écrit
 * désormais. Rien d'autre n'est recalculé (moyennes, crédits, PV verrouillés
 * ou publiés inchangés).
 *
 * Sans --appliquer : simple constat, rien n'est modifié.
 */
class DistinguerElimines extends Command
{
    protected $signature = 'gesnotes:distinguer-elimines
        {--universite=* : code(s) d\'université (toutes les actives par défaut)}
        {--appliquer : applique la correction (après une sauvegarde)}';

    protected $description = 'Constate (ou corrige avec --appliquer) les décisions « Défaillant » et les matières « NV » dues à une note éliminatoire, qui deviennent « Elim »';

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $codes = $this->option('universite');
        $universites = $codes ? array_filter(array_map(fn ($c) => $registre->trouver($c), $codes)) : $registre->actives();
        if (! $universites) {
            $this->error('Aucune université trouvée.');

            return self::FAILURE;
        }
        $appliquer = (bool) $this->option('appliquer');
        $echecs = 0;
        foreach ($universites as $u) {
            try {
                $courante->pour($u, function () use ($u, $appliquer) {
                    $base = DB::connection()->getDatabaseName();
                    $ids = self::aCorriger();
                    $notes = self::matieresACorriger();
                    $this->line((count($ids) || count($notes) ? '<fg=yellow>!</>' : '<fg=green>✔</>')." {$u->sigle} ($base) : ".count($ids)
                        .' résultat(s) « Défaillant » et '.count($notes).' matière(s) « NV » à passer en « Elim »');
                    if ($appliquer && ($ids || $notes)) {
                        foreach (array_chunk($ids, 500) as $paquet) {
                            DB::table('resultats_semestre')->whereIn('id', $paquet)->update(['decision' => 'Elim', 'mention' => 'Elim']);
                        }
                        foreach (array_chunk($notes, 500) as $paquet) {
                            DB::table('notes')->whereIn('id', $paquet)->where('observation', 'NV')->update(['observation' => 'Elim']);
                        }
                        $this->line('    '.count($ids).' décision(s) et '.count($notes).' observation(s) de matière corrigée(s)');
                    }
                });
            } catch (Throwable $e) {
                $echecs++;
                $this->line("<fg=red>✘</> {$u->sigle} : ".$e->getMessage());
            }
        }
        if (! $appliquer) {
            $this->newLine();
            $this->line('Rien n\'a été modifié. Relancer avec --appliquer pour corriger (après une sauvegarde).');
        }

        return $echecs ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Notes observées « NV » sous la note éliminatoire de l'année de leur PV
     * (rapportée au barème de la matière), toutes cases saisies : « Elim »,
     * comme le moteur l'écrit désormais. Aucune autre valeur ne change.
     */
    public static function matieresACorriger(): array
    {
        $resultats = app(Resultats::class);
        $ids = [];
        $annees = DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->distinct()->pluck('p.annee_id');
        foreach ($annees as $annee) {
            $elim = (float) $resultats->regle('note_eliminatoire', (int) $annee, '5');
            if ($elim <= 0) {
                continue;
            }
            $ids = [...$ids, ...DB::table('notes as n')->join('matieres as m', 'm.id', '=', 'n.matiere_id')
                ->join('pv_sessions as ps', 'ps.id', '=', 'n.pv_id')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
                ->where('p.annee_id', $annee)->where('n.observation', 'NV')->where('n.reportee', 0)->where('m.note_max_totale', '>', 0)
                ->whereNotNull('n.note_finale')->whereNotNull('n.note_examen')
                ->where(fn ($t) => $t->where('m.note_max_cc', '<=', 0)->orWhereNotNull('n.note_cc'))
                ->whereRaw('n.note_finale < m.note_max_totale * ? / 20', [$elim])
                ->pluck('n.id')->map(fn ($id) => (int) $id)->all()];
        }

        return $ids;
    }

    /** Résultats « Défaillant » sans aucune case vide (matière active non acquise ni reportée) dans leur PV. */
    public static function aCorriger(): array
    {
        return DB::table('resultats_semestre as rs')
            ->where('rs.defaillant', 1)->where('rs.decision', 'Défaillant')
            ->whereNotExists(fn ($q) => $q->from('notes as n')->join('matieres as m', 'm.id', '=', 'n.matiere_id')
                ->whereColumn('n.pv_id', 'rs.pv_id')->whereColumn('n.etudiant_id', 'rs.etudiant_id')
                ->where('m.actif', 1)->where('n.reportee', 0)
                ->where(fn ($o) => $o->whereNull('n.observation')->orWhereNotIn('n.observation', ['AV', 'AC']))
                ->where(fn ($v) => $v->whereNull('n.note_examen')->orWhere(fn ($t) => $t->where('m.note_max_cc', '>', 0)->whereNull('n.note_cc'))))
            ->pluck('rs.id')->map(fn ($id) => (int) $id)->all();
    }
}
