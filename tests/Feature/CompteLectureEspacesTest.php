<?php

namespace Tests\Feature;

use App\Support\FichierEnv;
use RuntimeException;
use Tests\TestCase;

/** Compte MySQL en lecture seule des espaces : constat et écriture du .env (la création réelle n'est pas testée : elle touche au serveur). */
class CompteLectureEspacesTest extends TestCase
{
    public function test_constat_sans_rien_modifier(): void
    {
        $this->artisan('gesnotes:compte-lecture-espaces')
            ->expectsOutputToContain("Compte 'gesnotes_lecture'@'localhost'")
            ->expectsOutputToContain('UDH : SELECT sur `gesnotes_test_udh`.*, INSERT sur `gesnotes_test_udh`.`audit_log`')
            ->expectsOutputToContain('Rien n\'a été modifié')
            ->assertSuccessful();
        $this->artisan('gesnotes:compte-lecture-espaces', ['--utilisateur' => "x'; DROP USER root; --"])->assertFailed();
    }

    public function test_ecriture_du_fichier_env(): void
    {
        $env = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($env, "APP_NAME=CURSUS\n# ESPACES_DB_USERNAME=\n# ESPACES_DB_PASSWORD=ancien\nAUTRE=1\n");
        FichierEnv::definir($env, 'ESPACES_DB_USERNAME', 'gesnotes_lecture');
        FichierEnv::definir($env, 'ESPACES_DB_PASSWORD', 'Abc123xyz');
        FichierEnv::definir($env, 'ESPACES_BASE', 'gesnotes_espaces');
        $this->assertSame("APP_NAME=CURSUS\nESPACES_DB_USERNAME=gesnotes_lecture\nESPACES_DB_PASSWORD=Abc123xyz\nAUTRE=1\nESPACES_BASE=gesnotes_espaces\n", file_get_contents($env));
        // Remplacement d'une valeur déjà définie, une seule ligne
        FichierEnv::definir($env, 'ESPACES_DB_PASSWORD', 'Nouveau456');
        $this->assertSame(1, substr_count((string) file_get_contents($env), 'ESPACES_DB_PASSWORD='));
        $this->assertStringContainsString("ESPACES_DB_PASSWORD=Nouveau456\n", (string) file_get_contents($env));
        unlink($env);

        $this->expectException(RuntimeException::class);
        FichierEnv::definir($env, 'ESPACES_DB_PASSWORD', "a\nAPP_DEBUG=true");
    }
}
