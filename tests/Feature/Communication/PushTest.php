<?php

namespace Tests\Feature\Communication;

use App\Services\Communication\Push;
use Illuminate\Http\Client\Request as RequeteHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Notifications sur téléphone (Firebase, simulé) : enregistrement du téléphone, envoi, téléphone désinstallé retiré. */
class PushTest extends TestCase
{
    private string $cle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        // Fausse clé de compte de service (clé RSA de test, ne donne accès à rien)
        $prive = file_get_contents(base_path('tests/fixtures/cle-test-firebase.pem'));
        $this->cle = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->cle, json_encode(['type' => 'service_account', 'project_id' => 'projet-test', 'private_key' => $prive,
            'client_email' => 'test@projet-test.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token']));
        config(['gesnotes.firebase_credentials' => $this->cle]);
        app()->forgetInstance(Push::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->cle);
        parent::tearDown();
    }

    public function test_enregistrement_et_envoi(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'jeton-google', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::sequence()->push(['name' => 'ok'])->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);
        $et = DB::table('etudiants')->insertGetId(['numero' => '00001', 'nom' => 'ALPHA', 'prenoms' => 'Awa']);
        $parent = DB::table('parents')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Moussa', 'telephone' => '90112233', 'mot_de_passe_hash' => Hash::make('Parent123')]);
        DB::table('parents_etudiants')->insert(['parent_id' => $parent, 'etudiant_id' => $et, 'lien' => 'pere']);
        RateLimiter::clear('api_mobile_parents|udh|127.0.0.1|90112233');
        $jeton = $this->postJson($this->url('api/mobile/parents?action=login'), ['telephone' => '90112233', 'password' => 'Parent123'])->json('data.token');

        // Le téléphone du parent s'enregistre
        $this->postJson($this->url('api/mobile/parents?action=push_token'), ['token' => 'jeton-telephone-1', 'plateforme' => 'android'], ['Authorization' => "Bearer $jeton"])->assertOk();
        $this->assertDatabaseHas('appareils_push', ['type_compte' => 'parent', 'compte_id' => $parent, 'jeton' => 'jeton-telephone-1']);

        // Envoi : jeton Google signé, message au téléphone
        $this->assertSame(1, app(Push::class)->parentsDe($et, 'Absence de Awa', 'Absente au cours de Microéconomie.'));
        Http::assertSent(fn (RequeteHttp $r) => str_contains($r->url(), 'oauth2.googleapis.com') && str_starts_with((string) $r['assertion'], 'eyJ'));
        Http::assertSent(fn (RequeteHttp $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/projet-test/messages:send'
            && $r->hasHeader('Authorization', 'Bearer jeton-google') && $r['message']['token'] === 'jeton-telephone-1'
            && $r['message']['notification']['title'] === 'Absence de Awa');

        // Application désinstallée : Firebase répond UNREGISTERED, le téléphone est retiré
        $this->assertSame(0, app(Push::class)->parentsDe($et, 'Test', 'Test'));
        $this->assertDatabaseMissing('appareils_push', ['jeton' => 'jeton-telephone-1']);

        // Déconnexion : le téléphone se retire
        $this->postJson($this->url('api/mobile/parents?action=push_token'), ['token' => 'jeton-telephone-2'], ['Authorization' => "Bearer $jeton"]);
        $this->postJson($this->url('api/mobile/parents?action=push_token'), ['token' => 'jeton-telephone-2', 'retirer' => true], ['Authorization' => "Bearer $jeton"]);
        $this->assertSame(0, DB::table('appareils_push')->count());
    }

    public function test_sans_cle_rien_n_est_envoye(): void
    {
        Http::fake();
        config(['gesnotes.firebase_credentials' => '']);
        app()->forgetInstance(Push::class);
        DB::table('appareils_push')->insert(['type_compte' => 'etudiant', 'compte_id' => 1, 'jeton' => 'x']);
        $this->assertSame(0, app(Push::class)->etudiant(1, 'Titre', 'Texte'));
        Http::assertNothingSent();
    }
}
