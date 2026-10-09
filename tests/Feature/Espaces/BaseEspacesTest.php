<?php

namespace Tests\Feature\Espaces;

use App\Models\Espaces\CompteAnab;
use App\Models\Espaces\CompteMesr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Comptes et journal des espaces Ministère / ANAB dans leur base propre (ESPACES_BASE). */
class BaseEspacesTest extends TestCase
{
    public function test_comptes_et_journal_hors_de_la_base_udh(): void
    {
        $this->installerLicence();
        $c = CompteMesr::create(['login' => 'dsi', 'nom_complet' => 'DSI', 'role' => 'admin', 'mot_de_passe' => 'Ministere2026', 'actif' => 1]);
        CompteAnab::create(['login' => 'bourses', 'nom_complet' => 'Bourses', 'role' => 'admin', 'mot_de_passe' => 'Bourses2026', 'actif' => 1]);
        $this->assertSame('espaces', $c->getConnectionName());
        $this->assertSame([1, 1], [DB::connection('espaces')->table('mesr_comptes')->count(), DB::connection('espaces')->table('anab_comptes')->count()]);
        // Rien dans la base de l'université : une restauration de la base UDH ne les touche plus
        $this->assertSame([0, 0], [DB::table('mesr_comptes')->count(), DB::table('anab_comptes')->count()]);

        // Connexion et journal du ministère dans la base des espaces
        $this->post($this->url('ministere/connexion', 'ministere'), ['user' => 'dsi', 'pass' => 'Ministere2026'])->assertRedirect($this->url('ministere', 'ministere'));
        $this->get($this->url('ministere', 'ministere'))->assertOk();
        $this->assertSame(1, DB::connection('espaces')->table('mesr_logs_consultation')->count());
        $this->assertSame(0, DB::table('mesr_logs_consultation')->count());
    }

    public function test_copie_depuis_la_base_udh_non_destructive_et_relancable(): void
    {
        DB::table('mesr_comptes')->insert(['id' => 7, 'login' => 'ancien', 'nom_complet' => 'Ancien compte', 'role' => 'admin', 'mot_de_passe' => Hash::make('Ancien2026'), 'actif' => 1]);
        DB::table('anab_comptes')->insert(['id' => 3, 'login' => 'agent', 'nom_complet' => 'Agent', 'role' => 'agent', 'mot_de_passe' => Hash::make('Agent2026'), 'actif' => 1]);
        DB::table('mesr_logs_consultation')->insert(['login' => 'ancien', 'action' => 'consultation_dashboard', 'created_at' => '2026-01-01 10:00:00']);

        // Constat seul : rien n'est copié
        $this->artisan('gesnotes:separer-espaces')->expectsOutputToContain('mesr_comptes : 1 ligne(s) dans la source')->assertSuccessful();
        $this->assertSame(0, DB::connection('espaces')->table('mesr_comptes')->count());

        $this->artisan('gesnotes:separer-espaces', ['--appliquer' => true])
            ->expectsOutputToContain('mesr_comptes : 1 ligne(s) copiée(s), 1 au total')->assertSuccessful();
        $this->assertSame('ancien', CompteMesr::find(7)->login);
        $this->assertTrue(Hash::check('Agent2026', CompteAnab::find(3)->mot_de_passe));
        $this->assertSame(1, DB::connection('espaces')->table('mesr_logs_consultation')->count());
        // La source est intacte (l'ancienne application s'en sert encore) ; relancer ne recopie rien
        $this->assertSame(1, DB::table('mesr_comptes')->count());
        $this->artisan('gesnotes:separer-espaces', ['--appliquer' => true])->expectsOutputToContain('mesr_comptes : 0 ligne(s) copiée(s), 1 au total');
    }

    public function test_sans_base_propre_ancien_fonctionnement(): void
    {
        config(['gesnotes.espaces_base' => null]);
        $c = CompteMesr::create(['login' => 'dsi', 'nom_complet' => 'DSI', 'role' => 'admin', 'mot_de_passe' => 'Ministere2026', 'actif' => 1]);
        $this->assertSame('universite', $c->getConnectionName());
        $this->assertSame([1, 0], [DB::table('mesr_comptes')->count(), DB::connection('espaces')->table('mesr_comptes')->count()]);
        $this->artisan('gesnotes:separer-espaces')->expectsOutputToContain('ESPACES_BASE')->assertFailed();
    }
}
