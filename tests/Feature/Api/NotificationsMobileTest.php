<?php

namespace Tests\Feature\Api;

use App\Services\Communication\Push;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** La cloche de l'application : historique des notifications du compte, annonces, nombre de non lues. */
class NotificationsMobileTest extends TestCase
{
    private int $et;

    private int $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->et = DB::table('etudiants')->insertGetId(['numero' => '00007', 'nom' => 'ALPHA', 'prenoms' => 'Awa', 'password_hash' => Hash::make('Secret123')]);
        $this->parent = DB::table('parents')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Moussa', 'telephone' => '90112233', 'mot_de_passe_hash' => Hash::make('Parent123')]);
        DB::table('parents_etudiants')->insert(['parent_id' => $this->parent, 'etudiant_id' => $this->et, 'lien' => 'pere']);
        RateLimiter::clear('api_mobile|udh|login|127.0.0.1|00007');
        RateLimiter::clear('api_mobile_parents|udh|127.0.0.1|90112233');
    }

    private function etudiant(string $action): TestResponse
    {
        static $jeton = null;
        $jeton ??= $this->postJson($this->url('api_mobile.php?action=login'), ['identifiant' => '00007', 'password' => 'Secret123'])->json('data.token');

        return $this->getJson($this->url("api_mobile.php?action=$action"), ['Authorization' => "Bearer $jeton"]);
    }

    public function test_cloche_etudiant_et_parent(): void
    {
        // Notification sans téléphone enregistré : gardée dans l'historique
        app(Push::class)->etudiant($this->et, 'Résultats disponibles', 'Les résultats du Semestre 1 sont publiés.', ['type' => 'resultats']);
        app(Push::class)->parentsDe($this->et, 'Absence de Awa', 'Absente au cours de Microéconomie.', ['type' => 'absence']);
        DB::table('annonces')->insert(['titre' => 'Rentrée', 'contenu' => '<b>Reprise</b> lundi', 'cible' => 'tous', 'publiee' => 1,
            'date_debut' => now()->subDay()->toDateString(), 'created_at' => now()->subHour()]);
        DB::table('annonces')->insert(['titre' => 'Réservée aux étudiants', 'contenu' => 'x', 'cible' => 'etudiants', 'publiee' => 1,
            'date_debut' => now()->subDay()->toDateString(), 'created_at' => now()->subHours(2)]);

        $this->etudiant('notifications')->assertOk()->assertJsonPath('data.non_lues', 3)
            ->assertJsonPath('data.elements.0.titre', 'Résultats disponibles')->assertJsonPath('data.elements.0.categorie', 'resultats')
            ->assertJsonPath('data.elements.1.titre', 'Rentrée')->assertJsonPath('data.elements.1.texte', 'Reprise lundi')
            ->assertJsonMissing(['titre' => 'Absence de Awa']);
        // Liste ouverte : plus de non lues ; une nouvelle notification compte de nouveau
        $this->etudiant('notifications_lues')->assertOk();
        $this->etudiant('notifications')->assertJsonPath('data.non_lues', 0)->assertJsonPath('data.elements.0.lue', true);
        $this->travel(1)->seconds();
        app(Push::class)->etudiant($this->et, 'Message de la scolarité', 'Votre dossier', ['type' => 'message']);
        $this->etudiant('notifications')->assertJsonPath('data.non_lues', 1);

        // Parent : ses notifications et les annonces (pas celles réservées aux étudiants)
        $jeton = $this->postJson($this->url('api/mobile/parents?action=login'), ['telephone' => '90112233', 'password' => 'Parent123'])->json('data.token');
        $this->getJson($this->url('api/mobile/parents?action=notifications'), ['Authorization' => "Bearer $jeton"])->assertOk()
            ->assertJsonPath('data.non_lues', 2)->assertJsonPath('data.elements.0.titre', 'Absence de Awa')
            ->assertJsonPath('data.elements.1.titre', 'Rentrée')->assertJsonMissing(['titre' => 'Réservée aux étudiants']);
    }
}
