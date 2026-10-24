<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/** Alerte par e-mail quand une page plante : une fois par heure pour la même erreur, jamais pour une page introuvable. */
class AlerteErreurTest extends TestCase
{
    public function test_erreur_serveur_signalee_une_fois(): void
    {
        $this->installerLicence();
        Cache::flush();
        config(['gesnotes.alerte_erreurs' => 'admin@test.local', 'gesnotes.alerte_erreurs_tests' => true]);
        Route::middleware('web')->get('/panne-de-test', fn () => throw new RuntimeException('Panne volontaire du test'));
        $alertes = fn () => DB::table('email_logs')->where('contexte', 'alerte_erreur')->get();

        $this->withExceptionHandling();
        $this->get($this->url('panne-de-test'))->assertStatus(500);
        $this->assertCount(1, $alertes());
        $this->assertSame('admin@test.local', $alertes()[0]->destinataire);
        $this->assertStringContainsString('Erreur serveur', $alertes()[0]->sujet);

        // Même erreur dans l'heure : pas de second message ; page introuvable : jamais d'alerte
        $this->get($this->url('panne-de-test'))->assertStatus(500);
        $this->get($this->url('page-qui-n-existe-pas'))->assertStatus(404);
        $this->assertCount(1, $alertes());
    }
}
