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

    public function test_annonces_et_messages(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'jeton-google']), 'fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);
        $eco = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '']);
        $droit = DB::table('filieres')->insertGetId(['code' => 'DRT', 'libelle' => 'Droit', 'departement' => '']);
        $a = DB::table('etudiants')->insertGetId(['numero' => '00001', 'nom' => 'ALPHA', 'prenoms' => 'Awa', 'filiere_id' => $eco]);
        $b = DB::table('etudiants')->insertGetId(['numero' => '00002', 'nom' => 'BRAVO', 'prenoms' => 'Ben', 'filiere_id' => $droit]);
        $parent = DB::table('parents')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Moussa', 'telephone' => '90112233', 'mot_de_passe_hash' => 'x']);
        DB::table('parents_etudiants')->insert(['parent_id' => $parent, 'etudiant_id' => $a, 'lien' => 'pere']);
        DB::table('appareils_push')->insert([['type_compte' => 'etudiant', 'compte_id' => $a, 'jeton' => 'tel-alpha'],
            ['type_compte' => 'etudiant', 'compte_id' => $b, 'jeton' => 'tel-bravo'], ['type_compte' => 'parent', 'compte_id' => $parent, 'jeton' => 'tel-parent']]);
        $envoyes = fn () => collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), 'fcm.googleapis.com'))->map(fn ($r) => $r[0]['message']['token'])->values()->all();

        // Annonce à la filière Économie : l'étudiant de cette filière et son parent, pas l'étudiant de Droit
        $id = DB::table('annonces')->insertGetId(['titre' => 'Rentrée', 'contenu' => '<p>Reprise des cours lundi.</p>', 'cible' => 'filiere',
            'filiere_id' => $eco, 'publiee' => 1, 'date_debut' => now()->toDateString()]);
        $this->assertSame(2, app(Push::class)->annonce(DB::table('annonces')->find($id)));
        $this->assertEqualsCanonicalizing(['tel-alpha', 'tel-parent'], $envoyes());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm') && $r['message']['notification']['title'] === '📢 Rentrée'
            && $r['message']['notification']['body'] === 'Reprise des cours lundi.');
        // Une seule fois par annonce ; « étudiants uniquement » : pas les parents
        $this->assertSame(0, app(Push::class)->annonce(DB::table('annonces')->find($id)));
        $id2 = DB::table('annonces')->insertGetId(['titre' => 'Examens', 'contenu' => 'Calendrier', 'cible' => 'etudiants', 'publiee' => 1, 'date_debut' => now()->toDateString()]);
        $this->assertSame(2, app(Push::class)->annonce(DB::table('annonces')->find($id2)));

        // Message de la scolarité : téléphone de l'étudiant destinataire
        $this->connecte($this->creerUtilisateur('admin'))->post($this->url('messagerie'), ['sujet' => 'Votre dossier', 'corps' => 'Merci de passer.', 'dest_id' => $b])
            ->assertSessionHas('succes');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm') && $r['message']['token'] === 'tel-bravo'
            && $r['message']['notification']['title'] === '✉️ Message de la scolarité' && $r['message']['notification']['body'] === 'Votre dossier');
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
