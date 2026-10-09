<?php

namespace App\Console\Commands;

use App\Services\ClassementPassages;
use App\Services\IndicateursRectorat;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Répétition générale de la bascule (BASCULE.md, point 7) sur des COPIES
 * des bases : les bases d'origine ne sont que lues.
 *
 *  1. copie de chaque base d'université active (et de la base des espaces)
 *     dans <base><suffixe>, sur le serveur MySQL (tables, données, vues) ;
 *  2. l'application est rebranchée sur les copies le temps de la commande ;
 *  3. procédure : completer-schema, reparer-passages, separer-espaces
 *     (--appliquer, sur les copies), copier-fichiers (constat seulement : il
 *     écrirait le .env), verifier-bascule ;
 *  4. contrôles : indicateurs de l'année active calculés pour chaque
 *     université, admis + redoublants = inscrits ;
 *  5. suppression des copies (sauf --garder).
 */
class RepetitionBascule extends Command
{
    protected $signature = 'gesnotes:repetition-bascule
        {--suffixe=_repetition : suffixe des bases copiées}
        {--garder : conserver les copies après la répétition}';

    protected $description = 'Répète la procédure de bascule sur des copies des bases (originales seulement lues)';

    /** @var array<string, string> base d'origine => copie */
    private array $copies = [];

    private array $bilan = [];

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $suffixe = (string) $this->option('suffixe');
        if (! preg_match('/^_[a-z0-9_]{2,20}$/', $suffixe)) {
            $this->error('Suffixe refusé (ex. _repetition).');

            return self::FAILURE;
        }
        // Indicateurs calculés sur les copies : jamais dans le cache réel de l'application
        config(['cache.default' => 'array']);

        $bases = [];
        foreach ($registre->actives() as $code => $u) {
            $courante->pour($u, function () use (&$bases, $code) {
                $bases[$code] = DB::connection()->getDatabaseName();
            });
        }
        $espaces = (string) config('gesnotes.espaces_base');
        $configOrigine = ['universites' => config('universites'), 'gesnotes.espaces_base' => config('gesnotes.espaces_base'),
            'database.connections.espaces.database' => config('database.connections.espaces.database')];

        try {
            $this->info('1. Copie des bases');
            $admin = DB::connection('universite');
            foreach ($bases as $code => $base) {
                $this->copier($admin, $base, $base.$suffixe);
                config(["universites.liste.$code.base" => $base.$suffixe]);
            }
            $sourceEspaces = $espaces !== '' && $this->existe($admin, $espaces) ? $espaces : null;
            $copieEspaces = ($espaces !== '' ? $espaces : 'gesnotes_espaces').$suffixe;
            if ($sourceEspaces) {
                $this->copier($admin, $sourceEspaces, $copieEspaces);
            } else {
                $this->supprimerSiCopie($admin, $copieEspaces, $suffixe);
            }
            $this->copies[$copieEspaces] ??= $copieEspaces; // créée au besoin par separer-espaces : supprimée à la fin

            // 2. Application rebranchée sur les copies
            config(['gesnotes.espaces_base' => $copieEspaces, 'database.connections.espaces.database' => $copieEspaces]);
            DB::purge('espaces');
            DB::purge('universite');
            app()->instance(RegistreUniversites::class, new RegistreUniversites(config('universites')));
            app()->forgetInstance(UniversiteCourante::class);

            $this->newLine();
            $this->info('2. Procédure de bascule sur les copies');
            foreach ([['gesnotes:completer-schema', ['--appliquer' => true]], ['gesnotes:reparer-passages', ['--appliquer' => true]],
                ['gesnotes:separer-espaces', ['--appliquer' => true]], ['gesnotes:copier-fichiers', []], ['gesnotes:verifier-bascule', []]] as [$commande, $options]) {
                $this->line("<fg=cyan>── $commande</>");
                $resultat = $this->call($commande, $options);
                $this->bilan[] = [$commande, $resultat === self::SUCCESS ? 'réussi' : 'ÉCHEC / erreurs signalées'];
            }

            $this->newLine();
            $this->info('3. Contrôles des chiffres (année active)');
            $this->controler();
        } catch (Throwable $e) {
            $this->error('Répétition interrompue : '.$e->getMessage());
            $this->bilan[] = ['interruption', $e->getMessage()];
        } finally {
            // Application rebranchée sur les bases d'origine
            config($configOrigine);
            DB::purge('espaces');
            DB::purge('universite');
            app()->instance(RegistreUniversites::class, new RegistreUniversites(config('universites')));
            app()->forgetInstance(UniversiteCourante::class);
            if (! $this->option('garder')) {
                $admin = DB::connection('universite');
                foreach (array_keys($this->copies) as $copie) {
                    $this->supprimerSiCopie($admin, $copie, $suffixe);
                }
                $this->line('Copies supprimées.');
            } else {
                $this->line('Copies conservées : '.implode(', ', array_keys($this->copies)));
            }
        }

        $this->newLine();
        $this->table(['Étape', 'Résultat'], $this->bilan);
        $echec = collect($this->bilan)->contains(fn ($l) => ! in_array($l[1], ['réussi', 'cohérent'], true));
        $this->line($echec ? '<fg=yellow>Points à regarder ci-dessus avant le jour J.</>' : '<fg=green>Répétition sans anomalie.</>');

        return $echec ? self::FAILURE : self::SUCCESS;
    }

    private function controler(): void
    {
        $registre = app(RegistreUniversites::class);
        $courante = app(UniversiteCourante::class);
        foreach ($registre->actives() as $u) {
            try {
                $courante->pour($u, function () use ($u) {
                    $annee = (int) DB::table('annees_academiques')->where('active', 1)->value('id');
                    if (! $annee) {
                        $this->bilan[] = ["{$u->sigle} : chiffres", 'pas d\'année active'];

                        return;
                    }
                    $s = app(IndicateursRectorat::class)->pour($annee);
                    $classes = app(ClassementPassages::class)->inscrits($annee);
                    $ok = $s['passages'] + $s['redoublants'] === $s['inscrits'] && $classes->count() === $s['inscrits'];
                    $this->line(sprintf('  %s (%s) : %d inscrits, %d admis, %d redoublants', $u->sigle, DB::connection()->getDatabaseName(), $s['inscrits'], $s['passages'], $s['redoublants']));
                    $this->bilan[] = ["{$u->sigle} : chiffres", $ok ? 'cohérent' : 'INCOHÉRENT'];
                });
            } catch (Throwable $e) {
                $this->bilan[] = ["{$u->sigle} : chiffres", 'erreur : '.$e->getMessage()];
            }
        }
    }

    /** Copie côté serveur : tables (structure + données) puis vues, rebranchées sur la copie. */
    private function copier($admin, string $source, string $copie): void
    {
        foreach ([$source, $copie] as $nom) {
            if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $nom)) {
                throw new \RuntimeException("Nom de base refusé : $nom");
            }
        }
        $this->supprimerSiCopie($admin, $copie, (string) $this->option('suffixe'));
        $admin->statement("CREATE DATABASE `$copie` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->copies[$copie] = $copie;
        $objets = $admin->select('SELECT table_name AS t, table_type AS type FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name', [$source]);
        $admin->statement('SET FOREIGN_KEY_CHECKS=0');
        $lignes = 0;
        foreach ($objets as $o) {
            if ($o->type !== 'BASE TABLE') {
                continue;
            }
            $admin->statement("CREATE TABLE `$copie`.`{$o->t}` LIKE `$source`.`{$o->t}`");
            $lignes += $admin->affectingStatement("INSERT INTO `$copie`.`{$o->t}` SELECT * FROM `$source`.`{$o->t}`");
        }
        foreach ($objets as $o) {
            if ($o->type === 'VIEW') {
                $def = (string) $admin->selectOne("SELECT view_definition AS d FROM information_schema.views WHERE table_schema = ? AND table_name = ?", [$source, $o->t])->d;
                $admin->statement("CREATE VIEW `$copie`.`{$o->t}` AS ".str_replace("`$source`.", "`$copie`.", $def));
            }
        }
        $admin->statement('SET FOREIGN_KEY_CHECKS=1');
        $this->line("  $source → $copie : ".count($objets)." table(s)/vue(s), $lignes ligne(s)");
    }

    private function existe($admin, string $base): bool
    {
        return (bool) $admin->scalar('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?', [$base]);
    }

    /** Ne supprime qu'une base portant le suffixe de répétition (jamais une base d'origine). */
    private function supprimerSiCopie($admin, string $base, string $suffixe): void
    {
        if (str_ends_with($base, $suffixe) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $base) && $this->existe($admin, $base)) {
            $admin->statement("DROP DATABASE `$base`");
        }
    }
}
