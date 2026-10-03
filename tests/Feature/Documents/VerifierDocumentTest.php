<?php

namespace Tests\Feature\Documents;

use App\Http\Controllers\Documents\VerifierDocumentController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Vérification publique des documents (cible des QR codes). */
class VerifierDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('verification|udh|127.0.0.1');
        $et = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Awa', 'numero' => '00001']);
        DB::table('releves_emis')->insert(['numero' => 'R26-000001', 'etudiant_id' => $et, 'pv_id' => 1, 'nom' => 'ALPHA', 'prenoms' => 'Awa',
            'semestre' => 'Semestre 1', 'mention_grade' => 'Bien', 'moyenne' => 14.5, 'annee_academique' => '2025-2026']);
        DB::table('diplomes_emis')->insert(['numero' => 'D26-LI-000001', 'etudiant_id' => $et, 'niveau' => 'licence', 'nom' => 'ALPHA', 'prenoms' => 'Awa',
            'specialite' => 'Économie', 'mention_grade' => 'Assez bien']);
    }

    public function test_page_publique_et_resultats(): void
    {
        $this->get($this->url('verification'))->assertOk()->assertSee('Saisissez le numéro')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get($this->url('verification?num=R26-000001'))->assertOk()
            ->assertSee('Ce document est authentique')->assertSee('ALPHA Awa')->assertSee('Relevé de notes')->assertSee('Semestre 1');
        $this->get($this->url('verification?num=D26-LI-000001'))->assertOk()->assertSee('Diplôme de Licence')->assertSee('Économie');
        $this->get($this->url('verification?num=R26-999999'))->assertOk()->assertSee('Numéro introuvable');
        $this->get($this->url('verification?num='.urlencode("<script>x</script>")))->assertOk()
            ->assertSee("n'a pas un format valide", false)->assertDontSee('<script>x', false);
    }

    public function test_limite_de_verifications_par_adresse(): void
    {
        for ($i = 0; $i < VerifierDocumentController::ESSAIS_MAX; $i++) {
            $this->get($this->url('verification?num=R26-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT)))->assertOk();
        }
        $this->get($this->url('verification?num=R26-000001'))->assertStatus(429)->assertSee('Trop de vérifications')->assertDontSee('ALPHA');
        // Ouvrir la page sans numéro ne compte pas
        $this->get($this->url('verification'))->assertOk();
    }

    public function test_etudiant_connecte_peut_verifier(): void
    {
        $this->connecte($this->creerUtilisateur('etudiant'))->get($this->url('verification?num=R26-000001'))->assertOk()->assertSee('authentique');
    }
}
