<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionEtAccesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();

        // Page factice protégée par une permission, pour tester le middleware
        Route::middleware(['web', 'auth', 'permission:paiements_voir'])
            ->get('/test-paiements', fn () => 'page paiements');
    }

    private function sessionUdh(array $donnees = []): array
    {
        return $donnees + ['universite' => 'udh', 'derniere_activite' => time()];
    }

    public function test_accueil_exige_une_connexion(): void
    {
        $this->get($this->url('accueil'))->assertRedirect($this->url('connexion'));
    }

    public function test_deconnexion_apres_30_minutes_d_inactivite(): void
    {
        $this->actingAs($this->creerUtilisateur('admin'))
            ->withSession($this->sessionUdh(['derniere_activite' => time() - 31 * 60]))
            ->get($this->url('accueil'))
            ->assertRedirect($this->url('connexion?expired=1'));
        $this->assertGuest();
    }

    public function test_compte_desactive_en_cours_de_session(): void
    {
        $u = $this->creerUtilisateur('admin');
        DB::table('utilisateurs')->where('id', $u->id)->update(['actif' => 0]);

        // fresh() : en vrai, l'utilisateur est relu en base à chaque requête
        $this->actingAs($u->fresh())->withSession($this->sessionUdh())->get($this->url('accueil'))
            ->assertRedirect($this->url('connexion'));
        $this->assertGuest();
    }

    public function test_session_d_une_universite_refusee_sur_une_autre(): void
    {
        $u = $this->creerUtilisateur('admin');

        $this->actingAs($u)->withSession($this->sessionUdh(['universite' => 'udh']))
            ->get($this->url('accueil', 'uam'))
            ->assertRedirect($this->url('connexion', 'uam'));
        $this->assertGuest();
    }

    public function test_permission_refusee_puis_accordee(): void
    {
        $u = $this->creerUtilisateur('secretaire');

        $this->actingAs($u)->withSession($this->sessionUdh())->get($this->url('test-paiements'))
            ->assertRedirect($this->url('accueil'))
            ->assertSessionHas('erreur');

        $this->donnerPermissions('secretaire', ['paiements_voir']);
        $this->actingAs($u->fresh())->withSession($this->sessionUdh())->get($this->url('test-paiements'))
            ->assertOk()->assertSee('page paiements');
    }

    public function test_admin_a_toutes_les_permissions(): void
    {
        $this->actingAs($this->creerUtilisateur('admin'))->withSession($this->sessionUdh())
            ->get($this->url('test-paiements'))->assertOk();
    }

    public function test_menu_filtre_selon_les_permissions(): void
    {
        $this->donnerPermissions('enseignant', ['etudiants_voir', 'notes_saisir']);

        $page = $this->actingAs($this->creerUtilisateur('enseignant'))->withSession($this->sessionUdh())
            ->get($this->url('accueil'))->assertOk();

        $page->assertSee('Liste des étudiants')->assertSee('Portail enseignant');
        $page->assertDontSee('Paramètres')->assertDontSee('Paiements')->assertDontSee('Utilisateurs');
        // Page migrée → route Laravel ; page non migrée → ancienne application
        $page->assertSee('href="http://udh.localhost/accueil"', false);
        $page->assertSee('href="http://udh.localhost/etudiants"', false);
        $page->assertSee('href="http://udh.localhost/portail/enseignant"', false);
    }

    public function test_etudiant_renvoye_vers_son_portail(): void
    {
        $this->actingAs($this->creerUtilisateur('etudiant'))->withSession($this->sessionUdh())
            ->get($this->url('accueil'))
            ->assertRedirect($this->url('portail/etudiant'));
    }

    public function test_licence_absente_bloque_l_application(): void
    {
        DB::table('parametres')->where('cle', 'licence_cle')->delete();

        $this->actingAs($this->creerUtilisateur('secretaire'))->withSession($this->sessionUdh())
            ->get($this->url('accueil'))
            ->assertStatus(403)->assertSee('Accès temporairement indisponible')
            ->assertDontSee('Activer une licence');

        $this->actingAs($this->creerUtilisateur('admin'))->withSession($this->sessionUdh())
            ->get($this->url('accueil'))
            ->assertStatus(403)->assertSee('/licence', false);
        // La page Licence reste accessible pour corriger la clé
        $this->get($this->url('licence'))->assertOk()->assertSee('Licence invalide');
    }

    public function test_licence_d_une_autre_universite_refusee(): void
    {
        $this->installerLicence('UAM');

        $this->actingAs($this->creerUtilisateur('admin'))->withSession($this->sessionUdh())
            ->get($this->url('accueil'))
            ->assertStatus(403)->assertSee('Cette licence appartient à l&#039;établissement « UAM »', false);
    }

    public function test_en_tetes_de_securite(): void
    {
        $this->get($this->url('connexion'))
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $this->get($this->url('connexion'))->headers->get('Content-Security-Policy'));
    }

    public function test_espace_ministere_ne_sert_que_ses_pages(): void
    {
        // La connexion du personnel n'existe pas sur le sous-domaine du ministère
        $this->get('http://ministere.localhost/connexion')->assertRedirect('http://ministere.localhost/ministere');
    }

    public function test_page_de_presentation_sur_le_domaine_nu(): void
    {
        $this->get('http://localhost/')->assertOk()->assertSee('Universités partenaires')->assertSee('//ministere.localhost/ministere/connexion', false);
        // En ligne : liens vers le domaine par lequel on arrive, même si UNIVERSITES_DOMAINE est resté à « localhost »
        config(['universites.domaine' => 'localhost']);
        $this->get('https://cursus-niger.net/')->assertOk()->assertSee('//udh.cursus-niger.net/connexion', false)->assertDontSee('localhost/connexion');
        $this->get('https://www.cursus-niger.net/')->assertOk()->assertSee('//udh.cursus-niger.net/connexion', false);
        // Par adresse IP : le réglage
        $this->get('http://127.0.0.1/')->assertOk()->assertSee('//udh.localhost/connexion', false);
    }
}
