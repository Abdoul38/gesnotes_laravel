<?php

namespace App\Console\Commands;

use App\Services\Communication\Courriel;
use App\Services\Licence;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contrôles avant la bascule (étape 13, voir BASCULE.md) : configuration
 * de production, dossiers de fichiers, puis pour chaque université active
 * sa base (tables du schéma de référence), sa licence, son administrateur
 * et sa messagerie. N'affiche jamais un secret : seulement « présent » /
 * « absent » / « valeur par défaut ».
 * Lecture seule : aucune base ni aucun fichier n'est modifié.
 */
class VerifierBascule extends Command
{
    protected $signature = 'gesnotes:verifier-bascule';

    protected $description = 'Vérifie la configuration et chaque base d\'université avant la mise en production';

    /** Empreinte SHA-256 de la clé de licence par défaut de l'ancienne application (anomalie A7). */
    private const EMPREINTE_SECRET_ANCIEN = 'a7042e7d545e669ab682bc96ec937541765cd7200099f04873e15662278942f9';

    private int $erreurs = 0;

    private int $alertes = 0;

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $this->info('Configuration');
        $prod = app()->environment('production');
        $this->ligne(filled(config('app.key')), 'APP_KEY présente');
        $this->ligne($prod, 'APP_ENV=production', bloquant: false);
        $this->ligne(! config('app.debug'), 'APP_DEBUG désactivé', bloquant: $prod);
        $this->ligne(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL en https', bloquant: false);
        $this->ligne((bool) config('session.secure'), 'Cookies de session « secure » (SESSION_SECURE_COOKIE=true)', bloquant: $prod);
        $secret = (string) config('licence.secret');
        $this->ligne($secret !== '', 'LICENCE_SECRET renseignée');
        $this->ligne($secret === '' || ! hash_equals(self::EMPREINTE_SECRET_ANCIEN, hash('sha256', $secret)),
            'LICENCE_SECRET différente de la valeur par défaut de l\'ancienne application', bloquant: false);
        $this->ligne(filled(config('komipay.login')) && filled(config('komipay.api_key')), 'Identifiants KomiPay renseignés (paiement en ligne)', bloquant: false);

        $this->newLine();
        $this->info('Dossiers');
        $ancienne = rtrim(str_replace('\\', '/', (string) config('gesnotes.ancienne_app_chemin')), '/');
        foreach (['dossier_uploads' => 'Fichiers déposés (DOSSIER_UPLOADS)', 'dossier_pieces_candidats' => 'Pièces des candidats (DOSSIER_PIECES_CANDIDATS)',
            'dossier_sauvegardes' => 'Sauvegardes (DOSSIER_SAUVEGARDES)'] as $cle => $libelle) {
            $d = (string) config("gesnotes.$cle");
            $this->ligne($d !== '' && is_dir($d) && is_writable($d), "$libelle : existe et accessible en écriture");
            if ($d !== '' && $ancienne !== '' && str_starts_with(str_replace('\\', '/', $d), $ancienne.'/')) {
                $this->ligne(false, "$libelle : encore dans l'ancienne application — à déplacer avant de la retirer", bloquant: false);
            }
        }

        $images = (string) config('gesnotes.dossier_images_portail');
        $this->ligne($images !== '' && is_dir($images), 'Images du portail candidats (DOSSIER_IMAGES_PORTAIL)', bloquant: false);

        $schema = $this->tablesDuSchema();
        foreach ($registre->actives() as $u) {
            $this->newLine();
            $this->info("Université {$u->sigle} ({$u->code})");
            try {
                $courante->pour($u, function () use ($schema) {
                    $tables = array_map(fn ($t) => $t->n, DB::select('SELECT table_name AS n FROM information_schema.tables WHERE table_schema = DATABASE()'));
                    $manquantes = array_diff($schema, $tables);
                    $this->ligne(! $manquantes, 'Tables du schéma de référence'.($manquantes ? ' — manquantes : '.implode(', ', array_slice($manquantes, 0, 10)) : ''));
                    $licence = app(Licence::class)->verifierInstallation();
                    $this->ligne($licence['valide'], 'Licence'.($licence['valide'] ? '' : ' — '.$licence['erreur']));
                    $this->ligne(DB::table('utilisateurs')->where('role', 'admin')->where('actif', 1)->exists(), 'Au moins un administrateur actif');
                    $this->ligne(app(Courriel::class)->actif(), 'Messagerie (SMTP) configurée', bloquant: false);
                });
            } catch (Throwable $e) {
                $this->ligne(false, 'Base injoignable : '.$e->getMessage());
            }
        }

        $comptes = $registre->trouver((string) config('gesnotes.espaces_comptes'));
        $this->newLine();
        $this->ligne($comptes !== null && $comptes->actif, 'Base des comptes Ministère / ANAB (ESPACES_UNIVERSITE_COMPTES) : université active');

        $this->newLine();
        $this->line("Résultat : {$this->erreurs} erreur(s), {$this->alertes} alerte(s).");

        return $this->erreurs > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function ligne(bool $ok, string $texte, bool $bloquant = true): void
    {
        if ($ok) {
            $this->line("  <fg=green>✔</> $texte");

            return;
        }
        $bloquant ? $this->erreurs++ : $this->alertes++;
        $this->line($bloquant ? "  <fg=red>✘</> $texte" : "  <fg=yellow>!</> $texte");
    }

    /** @return list<string> tables (hors vues) du schéma de référence */
    private function tablesDuSchema(): array
    {
        preg_match_all('/^CREATE TABLE `([^`]+)`/m', (string) file_get_contents(database_path('schema/universite-schema.sql')), $m);

        return $m[1];
    }
}
