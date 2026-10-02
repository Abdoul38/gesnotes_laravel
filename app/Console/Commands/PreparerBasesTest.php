<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;

/**
 * (Re)crée les bases de test à partir du schéma de référence.
 * Ne touche qu'aux bases dont le nom contient « _test ».
 */
class PreparerBasesTest extends Command
{
    protected $signature = 'gesnotes:preparer-bases-test {bases=gesnotes_test_udh,gesnotes_test_uam : bases à recréer, séparées par des virgules}';

    protected $description = 'Recrée les bases de test vides depuis database/schema/universite-schema.sql';

    public function handle(): int
    {
        $schema = file_get_contents(database_path('schema/universite-schema.sql'));
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', config('database.connections.universite.host'), config('database.connections.universite.port')),
            config('database.connections.universite.username'),
            config('database.connections.universite.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        foreach (explode(',', $this->argument('bases')) as $base) {
            $base = trim($base);
            if (! preg_match('/^[a-z0-9_]*_test[a-z0-9_]*$/', $base)) {
                $this->error("« $base » refusée : seules les bases contenant « _test » peuvent être recréées.");

                return self::FAILURE;
            }
            $pdo->exec("DROP DATABASE IF EXISTS `$base`");
            $pdo->exec("CREATE DATABASE `$base` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$base`");
            $pdo->exec($schema);
            $nb = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$base'")->fetchColumn();
            $this->info("$base : $nb tables et vues créées.");
        }

        return self::SUCCESS;
    }
}
