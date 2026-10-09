<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Copie des fichiers de l'ancienne application (sur des dossiers temporaires, avec un .env temporaire). */
class CopierFichiersTest extends TestCase
{
    private string $racine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->racine = sys_get_temp_dir().'/gesnotes-copie-'.uniqid();
        $ancienne = "$this->racine/ancienne";
        foreach (['uploads/photos/photo_1.jpg' => 'photo', 'uploads/logos/logo.png' => 'logo', 'assets/img/signature.png' => 'sig',
            'candidature/uploads/pieces/cni_1.pdf' => 'cni', 'candidature/assets/img/udh/slide-1.jpg' => 'slide',
            'pages/backups/gesnotes_test_udh_2026-09-30_17-55-24.sql' => '-- sql', 'pages/backups/inconnue_2026-01-01_00-00-00.sql' => '-- ?'] as $chemin => $contenu) {
            File::ensureDirectoryExists(dirname("$ancienne/$chemin"));
            file_put_contents("$ancienne/$chemin", $contenu);
        }
        file_put_contents("$this->racine/.env", "APP_NAME=CURSUS\n# DOSSIER_UPLOADS=\n");
        $this->app->useEnvironmentPath($this->racine);
        config(['gesnotes.ancienne_app_chemin' => $ancienne, 'gesnotes.dossier_sauvegardes' => "$this->racine/sauvegardes"]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->racine);
        parent::tearDown();
    }

    public function test_copie_non_destructive_relancable_et_env_mis_a_jour(): void
    {
        $dest = "$this->racine/fichiers";
        $this->artisan('gesnotes:copier-fichiers', ['--destination' => $dest])->expectsOutputToContain('6 fichier(s) à copier')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($dest);

        $this->artisan('gesnotes:copier-fichiers', ['--destination' => $dest, '--appliquer' => true])
            ->expectsOutputToContain('université non reconnue')->assertSuccessful();
        foreach (['uploads/photos/photo_1.jpg' => 'photo', 'uploads/logos/logo.png' => 'logo', 'uploads/signature.png' => 'sig',
            'pieces-candidats/cni_1.pdf' => 'cni', 'images-portail/udh/slide-1.jpg' => 'slide'] as $chemin => $contenu) {
            $this->assertSame($contenu, file_get_contents("$dest/$chemin"), $chemin);
        }
        $this->assertFileExists("$this->racine/sauvegardes/udh/gesnotes_test_udh_2026-09-30_17-55-24.sql");
        // Rien n'est retiré de l'ancienne application
        $this->assertFileExists("$this->racine/ancienne/uploads/photos/photo_1.jpg");
        $env = (string) file_get_contents("$this->racine/.env");
        $this->assertStringContainsString('DOSSIER_UPLOADS='.str_replace('\\', '/', $dest)."/uploads\n", $env);
        $this->assertStringContainsString('DOSSIER_PIECES_CANDIDATS=', $env);
        $this->assertSame(1, substr_count($env, 'DOSSIER_UPLOADS='));

        // Relancée : seuls les fichiers nouveaux sont copiés
        file_put_contents("$this->racine/ancienne/uploads/photos/photo_2.jpg", 'nouvelle');
        $this->artisan('gesnotes:copier-fichiers', ['--destination' => $dest, '--appliquer' => true])->expectsOutputToContain('1 fichier(s) copié(s)');
        $this->assertFileExists("$dest/uploads/photos/photo_2.jpg");
    }

    public function test_destination_interdite_dans_l_ancienne_application_ou_le_dossier_public(): void
    {
        $this->artisan('gesnotes:copier-fichiers', ['--destination' => "$this->racine/ancienne/copie"])->assertFailed();
        $this->artisan('gesnotes:copier-fichiers', ['--destination' => public_path('fichiers')])->assertFailed();
    }
}
