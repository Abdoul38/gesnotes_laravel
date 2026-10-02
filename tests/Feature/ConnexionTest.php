<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ConnexionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('connexion|udh|127.0.0.1');
        $this->installerLicence();
    }

    public function test_page_de_connexion(): void
    {
        $this->get($this->url('connexion'))
            ->assertOk()
            ->assertSee('CURSUS — UDH')
            ->assertSee('Université Djibo Hamani de Tahoua')
            ->assertSee('index.php?page=login_parent', false);
    }

    public function test_connexion_avec_un_mot_de_passe_cree_par_l_ancienne_application(): void
    {
        $u = $this->creerUtilisateur('secretaire');
        DB::table('utilisateurs')->where('id', $u->id)->update(['mot_de_passe' => $this->hashAncien('Ancien#2025')]);
        $hashAvant = DB::table('utilisateurs')->where('id', $u->id)->value('mot_de_passe');

        $this->post($this->url('connexion'), ['email' => 'secretaire@test.local', 'mot_de_passe' => 'Ancien#2025'])
            ->assertRedirect($this->url('accueil'));

        $this->assertAuthenticatedAs($u);
        $ligne = DB::table('utilisateurs')->where('id', $u->id)->first();
        $this->assertNotNull($ligne->derniere_connexion);
        $this->assertSame($hashAvant, $ligne->mot_de_passe, 'le hash ne doit pas être réécrit (base partagée)');
        $this->assertDatabaseHas('journal_audit', ['utilisateur_id' => $u->id, 'action' => 'LOGIN', 'table_cible' => 'utilisateurs']);
        $this->assertDatabaseHas('audit_log', ['user_id' => $u->id, 'action' => 'connexion', 'module' => 'auth', 'description' => 'Connexion réussie']);
    }

    public function test_mauvais_mot_de_passe(): void
    {
        $this->creerUtilisateur('admin');

        $this->from($this->url('connexion'))
            ->post($this->url('connexion'), ['email' => 'admin@test.local', 'mot_de_passe' => 'faux'])
            ->assertRedirect($this->url('connexion'))
            ->assertSessionHas('erreur', 'Email ou mot de passe incorrect.');
        $this->assertGuest();
    }

    public function test_champs_vides(): void
    {
        $this->post($this->url('connexion'), ['email' => '', 'mot_de_passe' => ''])
            ->assertSessionHas('erreur', 'Veuillez remplir tous les champs.');
    }

    public function test_compte_desactive_refuse(): void
    {
        $this->creerUtilisateur('admin', ['actif' => 0]);

        $this->post($this->url('connexion'), ['email' => 'admin@test.local', 'mot_de_passe' => 'MotDePasse1'])
            ->assertSessionHas('erreur', 'Email ou mot de passe incorrect.');
        $this->assertGuest();
    }

    public function test_blocage_apres_cinq_echecs_meme_avec_le_bon_mot_de_passe(): void
    {
        $this->creerUtilisateur('admin');
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->url('connexion'), ['email' => 'admin@test.local', 'mot_de_passe' => 'faux']);
        }

        $this->post($this->url('connexion'), ['email' => 'admin@test.local', 'mot_de_passe' => 'MotDePasse1']);
        $this->assertGuest();
        $this->get($this->url('connexion'))->assertSee('Accès temporairement bloqué. Réessayez dans 5 minute(s).');
    }

    public function test_redirection_selon_le_role(): void
    {
        $this->creerUtilisateur('enseignant');

        $this->post($this->url('connexion'), ['email' => 'enseignant@test.local', 'mot_de_passe' => 'MotDePasse1'])
            ->assertRedirect('http://udh.localhost/index.php?page=portail_enseignant');
    }

    public function test_deconnexion(): void
    {
        $u = $this->creerUtilisateur('admin');

        $this->actingAs($u)->withSession(['universite' => 'udh'])->post($this->url('deconnexion'))
            ->assertRedirect($this->url('connexion'));
        $this->assertGuest();
        $this->assertDatabaseHas('journal_audit', ['utilisateur_id' => $u->id, 'action' => 'LOGOUT']);
    }

    public function test_deconnexion_par_get_impossible(): void
    {
        $this->actingAs($this->creerUtilisateur('admin'))->get($this->url('deconnexion'))->assertStatus(405);
    }
}
