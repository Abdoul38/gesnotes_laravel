<?php

namespace App\Console\Commands;

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

    protected $description = 'Constate (ou corrige avec --appliquer) les décisions « Défaillant » dues à une note éliminatoire, qui deviennent « Elim »';

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
                    $this->line((count($ids) ? '<fg=yellow>!</>' : '<fg=green>✔</>')." {$u->sigle} ($base) : ".count($ids).' résultat(s) « Défaillant » à passer en « Elim »');
                    if ($appliquer && $ids) {
                        foreach (array_chunk($ids, 500) as $paquet) {
                            DB::table('resultats_semestre')->whereIn('id', $paquet)->update(['decision' => 'Elim', 'mention' => 'Elim']);
                        }
                        $this->line('    '.count($ids).' décision(s) corrigée(s)');
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
