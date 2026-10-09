<?php

namespace App\Console\Commands;

use App\Espaces\Espaces;
use App\Support\SchemaReference;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Met en place la base propre aux espaces Ministère et ANAB (ESPACES_BASE) :
 * crée la base et ses tables (mesr_comptes, anab_comptes,
 * mesr_logs_consultation) puis y copie les comptes et le journal de la base
 * de l'université ESPACES_UNIVERSITE_COMPTES (UDH).
 *
 * Non destructif : rien n'est retiré de la base de l'université (l'ancienne
 * application s'en sert encore) ; une ligne déjà copiée n'est pas recopiée,
 * la commande peut donc être relancée. Sans --appliquer : simple constat.
 */
class SeparerEspaces extends Command
{
    protected $signature = 'gesnotes:separer-espaces {--appliquer : crée la base, ses tables et copie les données (après une sauvegarde)}';

    protected $description = 'Crée la base propre aux comptes Ministère / ANAB (ESPACES_BASE) et y copie comptes et journal';

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $base = (string) config('gesnotes.espaces_base');
        if ($base === '') {
            $this->error('ESPACES_BASE n\'est pas renseignée dans le .env (ex. ESPACES_BASE=gesnotes_espaces).');

            return self::FAILURE;
        }
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $base)) {
            $this->error("Nom de base refusé : « $base » (lettres, chiffres et _ seulement).");

            return self::FAILURE;
        }
        $source = $registre->trouver((string) config('gesnotes.espaces_comptes'));
        if (! $source) {
            $this->error('Université des comptes introuvable (ESPACES_UNIVERSITE_COMPTES).');

            return self::FAILURE;
        }
        $appliquer = (bool) $this->option('appliquer');
        $creations = SchemaReference::creations();

        try {
            return $courante->pour($source, function () use ($base, $source, $appliquer, $creations) {
                $origine = DB::connection();
                if ($origine->getDatabaseName() === $base) {
                    $this->error('ESPACES_BASE doit être une base différente de celle de l\'université des comptes.');

                    return self::FAILURE;
                }
                $existe = (bool) $origine->scalar('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?', [$base]);
                $this->line("Source : {$source->sigle} ({$origine->getDatabaseName()}) → base des espaces : $base".($existe ? '' : ' (à créer)'));

                if ($appliquer && ! $existe) {
                    $origine->statement("CREATE DATABASE `$base` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $this->line("  base $base créée");
                }
                $cible = ($appliquer || $existe) ? DB::connection('espaces') : null;
                foreach (Espaces::TABLES as $table) {
                    $lignes = $origine->table($table)->orderBy('id')->get()->map(fn ($l) => (array) $l);
                    if ($appliquer) {
                        $cible->statement(preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $creations[$table]));
                        $avant = $cible->table($table)->count();
                        foreach ($lignes->chunk(500) as $lot) {
                            $cible->table($table)->insertOrIgnore($lot->values()->all());
                        }
                        $this->line("  $table : ".($cible->table($table)->count() - $avant).' ligne(s) copiée(s), '.$cible->table($table)->count().' au total');
                    } else {
                        $present = $cible && $cible->getSchemaBuilder()->hasTable($table) ? $cible->table($table)->count() : null;
                        $this->line("  $table : {$lignes->count()} ligne(s) dans la source".($present === null ? ', table absente de la base des espaces' : ", $present déjà dans la base des espaces"));
                    }
                }
                if (! $appliquer) {
                    $this->newLine();
                    $this->line('Rien n\'a été modifié. Relancer avec --appliquer (après une sauvegarde).');
                }

                return self::SUCCESS;
            });
        } catch (Throwable $e) {
            $this->error('Échec : '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
