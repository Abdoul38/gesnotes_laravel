<?php

namespace App\Console\Commands;

use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Répare les décisions de passage (table passages_niveau) :
 *  1. colonne decision sans la valeur « licence_validee » (bases antérieures
 *     à son ajout) : MySQL non strict y enregistrait une chaîne VIDE à la
 *     place de « licence_validee », sans erreur → ajout de la valeur ;
 *  2. décisions vides → « licence_validee » (c'est la seule valeur que
 *     l'application écrivait et que la colonne refusait) ;
 *  3. doublons (même étudiant, même année d'arrivée, filières différentes,
 *     créés quand la décision était reprise vers une spécialisation) : seule
 *     la dernière décision est gardée.
 *
 * Sans --appliquer : simple constat, rien n'est modifié.
 */
class ReparerPassages extends Command
{
    public const VALEURS = "enum('passage','redoublement','licence_validee')";

    protected $signature = 'gesnotes:reparer-passages
        {--universite=* : code(s) d\'université (toutes les actives par défaut)}
        {--appliquer : applique les réparations (après une sauvegarde)}';

    protected $description = 'Constate (ou répare avec --appliquer) les décisions de passage vides ou en double';

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
                    $type = self::typeColonne();
                    $enumIncomplet = ! str_contains($type, 'licence_validee');
                    $vides = DB::table('passages_niveau')->where('decision', '')->count();
                    $doublons = self::doublons();
                    $this->line(($enumIncomplet || $vides || $doublons ? '<fg=yellow>!</>' : '<fg=green>✔</>')." {$u->sigle} ($base) : "
                        .($enumIncomplet ? 'colonne decision sans « licence_validee », ' : '')."$vides décision(s) vide(s), ".count($doublons).' doublon(s)');
                    if (! $appliquer || (! $enumIncomplet && ! $vides && ! $doublons)) {
                        return;
                    }
                    if ($enumIncomplet) {
                        DB::statement('ALTER TABLE passages_niveau MODIFY decision '.self::VALEURS." NOT NULL DEFAULT 'passage'");
                        $this->line('    colonne decision : « licence_validee » ajoutée');
                    }
                    DB::transaction(function () use ($vides, $doublons) {
                        if ($vides) {
                            DB::table('passages_niveau')->where('decision', '')->update(['decision' => 'licence_validee']);
                            $this->line("    $vides décision(s) vide(s) → licence_validee");
                        }
                        if ($doublons) {
                            DB::table('passages_niveau')->whereIn('id', $doublons)->delete();
                            $this->line('    '.count($doublons).' doublon(s) supprimé(s) (dernière décision gardée)');
                        }
                    });
                });
            } catch (Throwable $e) {
                $echecs++;
                $this->line("<fg=red>✘</> {$u->sigle} : ".$e->getMessage());
            }
        }
        if (! $appliquer) {
            $this->newLine();
            $this->line('Rien n\'a été modifié. Relancer avec --appliquer pour réparer (après une sauvegarde).');
        }

        return $echecs ? self::FAILURE : self::SUCCESS;
    }

    /** Type de la colonne passages_niveau.decision dans la base courante. */
    public static function typeColonne(): string
    {
        return (string) DB::scalar("SELECT COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'passages_niveau' AND column_name = 'decision'");
    }

    /** Identifiants des décisions remplacées : pour chaque étudiant et année d'arrivée, toutes sauf la plus récente. */
    public static function doublons(): array
    {
        return DB::table('passages_niveau as pn')
            ->whereExists(fn ($q) => $q->from('passages_niveau as plus_recent')->whereColumn('plus_recent.etudiant_id', 'pn.etudiant_id')
                ->whereColumn('plus_recent.annee_arrivee_id', 'pn.annee_arrivee_id')->whereColumn('plus_recent.id', '>', 'pn.id'))
            ->pluck('pn.id')->map(fn ($id) => (int) $id)->all();
    }
}
