<?php

namespace App\Console\Commands;

use App\Support\FichierEnv;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Crée le compte MySQL avec lequel le Ministère et l'ANAB lisent les bases
 * des universités (IndicateursNationaux::dans) : SELECT sur chaque base
 * d'université active, INSERT sur leur seul journal audit_log (consultations
 * de l'ANAB) — rien d'autre. Mot de passe aléatoire écrit directement dans
 * le .env (ESPACES_DB_USERNAME / ESPACES_DB_PASSWORD), jamais affiché.
 *
 * À lancer avec un compte MySQL autorisé à créer des comptes (root en
 * général). Sans --appliquer : liste les droits qui seraient donnés.
 * Relancée, elle change le mot de passe et redonne les mêmes droits.
 */
class CompteLectureEspaces extends Command
{
    protected $signature = 'gesnotes:compte-lecture-espaces
        {--utilisateur=gesnotes_lecture : nom du compte MySQL}
        {--hote=localhost : hôte d\'où l\'application se connecte (localhost, 127.0.0.1, %…)}
        {--appliquer : crée le compte, donne les droits et écrit le .env}';

    protected $description = 'Crée le compte MySQL en lecture seule des espaces Ministère / ANAB et le renseigne dans le .env';

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $utilisateur = (string) $this->option('utilisateur');
        $hote = (string) $this->option('hote');
        if (! preg_match('/^[A-Za-z0-9_]{1,32}$/', $utilisateur) || ! preg_match('/^[A-Za-z0-9_.%-]{1,60}$/', $hote)) {
            $this->error('Nom de compte ou hôte refusé (lettres, chiffres, _ ; hôte : aussi . - %).');

            return self::FAILURE;
        }
        $bases = [];
        foreach ($registre->actives() as $u) {
            $courante->pour($u, function () use (&$bases, $u) {
                $bases[$u->sigle] = DB::connection()->getDatabaseName();
            });
        }
        $bases = array_filter($bases, fn ($b) => preg_match('/^[A-Za-z0-9_]+$/', (string) $b));
        if (! $bases) {
            $this->error('Aucune base d\'université active.');

            return self::FAILURE;
        }
        $compte = "'$utilisateur'@'$hote'";
        $this->line("Compte $compte :");
        foreach ($bases as $sigle => $base) {
            $this->line("  $sigle : SELECT sur `$base`.*, INSERT sur `$base`.`audit_log`");
        }
        if (! $this->option('appliquer')) {
            $this->newLine();
            $this->line('Rien n\'a été modifié. Relancer avec --appliquer (le mot de passe, aléatoire, sera écrit dans le .env sans être affiché).');

            return self::SUCCESS;
        }

        $motDePasse = Str::random(40); // lettres et chiffres
        $admin = DB::connection();
        try {
            $admin->statement("CREATE USER IF NOT EXISTS $compte IDENTIFIED BY ".$admin->getPdo()->quote($motDePasse));
            $admin->statement("ALTER USER $compte IDENTIFIED BY ".$admin->getPdo()->quote($motDePasse));
            foreach ($bases as $base) {
                $admin->statement("GRANT SELECT ON `$base`.* TO $compte");
                $admin->statement("GRANT INSERT ON `$base`.`audit_log` TO $compte");
            }
        } catch (Throwable $e) {
            $this->error('Échec MySQL (le compte de l\'application a-t-il le droit de créer des comptes ?) : '.$e->getMessage());

            return self::FAILURE;
        }

        // Essai de connexion avec le nouveau compte : lecture permise, écriture refusée
        $config = config('database.connections.universite');
        foreach ($bases as $sigle => $base) {
            try {
                $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname=$base;charset=utf8mb4", $utilisateur, $motDePasse, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->query('SELECT COUNT(*) FROM etudiants')->fetchColumn();
                $ecriture = true;
                try {
                    $pdo->exec('UPDATE etudiants SET id = id WHERE 1 = 0');
                } catch (Throwable) {
                    $ecriture = false;
                }
                $this->line(($ecriture ? '<fg=red>✘</>' : '<fg=green>✔</>')." $sigle : lecture possible, écriture ".($ecriture ? 'POSSIBLE — vérifier les droits' : 'refusée'));
            } catch (Throwable $e) {
                $this->line("<fg=red>✘</> $sigle : connexion impossible avec le nouveau compte — ".$e->getMessage());
            }
        }

        $env = app()->environmentFilePath();
        FichierEnv::definir($env, 'ESPACES_DB_USERNAME', $utilisateur);
        FichierEnv::definir($env, 'ESPACES_DB_PASSWORD', $motDePasse);
        if (app()->configurationIsCached()) {
            $this->callSilently('config:clear');
        }
        $this->info("Compte prêt ; ESPACES_DB_USERNAME et ESPACES_DB_PASSWORD écrits dans $env (mot de passe non affiché).");

        return self::SUCCESS;
    }
}
