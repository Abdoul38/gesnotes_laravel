<?php

namespace Tests\Feature\Documents;

use App\Http\Controllers\Documents\VerifierDocumentController;
use App\Services\CleVerification;
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
        $this->get($this->url('verification'))->assertOk()->assertSee('saisissez son numéro et son code de vérification')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        // Numéro seul : aucune réponse, qu'il existe ou non (on ne parcourt pas les numéros)
        foreach (['R26-000001', 'R26-999999'] as $num) {
            $this->get($this->url("verification?num=$num"))->assertOk()->assertSee('Code de vérification requis')
                ->assertDontSee('authentique')->assertDontSee('A. A.')->assertDontSee('2025-2026')->assertDontSee('non vérifié');
        }
        $this->get($this->url('verification?num=R26-000001&cle=0000000000000000'))->assertOk()->assertDontSee('ALPHA')->assertDontSee('authentique');
        // Numéro + code de vérification imprimé : détail complet ; mauvais code ou code d'un autre document : rien
        $code = app(CleVerification::class)->code('R26-000001');
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $code);
        $this->get($this->url('verification?num=R26-000001&code='.strtolower(str_replace('-', ' ', $code))))->assertOk()
            ->assertSee('Ce document est authentique')->assertSee('ALPHA Awa')->assertSee('Semestre 1')->assertDontSee('détail masqué');
        $this->get($this->url('verification?num=R26-000001&code=0000-0000'))->assertOk()->assertSee('Document non vérifié')->assertDontSee('ALPHA');
        $this->get($this->url('verification?num=D26-LI-000001&code='.$code))->assertOk()->assertDontSee('Économie')->assertDontSee('authentique');
        // Numéro + nom du titulaire (documents sans code) : authenticité, type, initiales, année
        $this->get($this->url('verification?num=R26-000001&nom=alpha'))->assertOk()
            ->assertSee('Ce document est authentique')->assertSee('A. A.')->assertSee('Relevé de notes')->assertSee('2025-2026')
            ->assertDontSee('Bien')->assertDontSee('Semestre 1')->assertSee('détail masqué');
        $this->get($this->url('verification?num=R26-000001&nom=Awa+Alpha'))->assertOk()->assertSee('authentique');
        $this->get($this->url('verification?num=R26-000001&nom=BRAVO'))->assertOk()->assertSee('Document non vérifié')->assertDontSee('A. A.');
        // Adresse du QR code (clé valide) : détail complet
        $this->get(app(CleVerification::class)->url('R26-000001'))->assertOk()
            ->assertSee('ALPHA Awa')->assertSee('Semestre 1')->assertSee('Bien')->assertDontSee('détail masqué');
        $this->get(app(CleVerification::class)->url('D26-LI-000001'))->assertOk()->assertSee('Diplôme de Licence')->assertSee('Économie');
        // La clé d'un document ne vaut pas pour un autre
        $cle = app(CleVerification::class)->cle('R26-000001');
        $this->get($this->url("verification?num=D26-LI-000001&cle=$cle"))->assertOk()->assertDontSee('Économie');
        // Personnel connecté : détail complet
        $this->connecte($this->creerUtilisateur('secretaire'))->get($this->url('verification?num=R26-000001'))->assertOk()->assertSee('ALPHA Awa');
        $this->post($this->url('deconnexion'));
        $this->get($this->url('verification?num=R26-999999&code=0000-0000'))->assertOk()->assertSee('Document non vérifié');
        $this->get($this->url('verification?num='.urlencode('<script>x</script>')))->assertOk()
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
        $this->connecte($this->creerUtilisateur('etudiant'))->get($this->url('verification?num=R26-000001&nom=ALPHA'))->assertOk()->assertSee('authentique');
    }
}
