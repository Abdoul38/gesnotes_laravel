<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** « Mon compte » : profil de l'utilisateur connecté et changement de son mot de passe. */
class MonCompteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
    }

    private function changer(string $actuel, string $nouveau, ?string $confirmation = null)
    {
        return $this->post($this->url('mon-compte/mot-de-passe'), [
            'mot_de_passe_actuel' => $actuel, 'mot_de_passe' => $nouveau, 'mot_de_passe_confirmation' => $confirmation ?? $nouveau,
        ]);
    }

    public function test_profil_accessible_en_cliquant_sur_son_nom(): void
    {
        $this->get($this->url('mon-compte'))->assertRedirect($this->url('connexion'));
        $fac = $this->insererMinimal('facultes', ['code' => 'FSJP', 'libelle' => 'Faculté de Droit']);
        $u = $this->creerUtilisateur('secretaire', ['nom' => 'GARBA', 'prenoms' => 'Aïcha', 'faculte_id' => $fac]);

        $this->connecte($u)->get($this->url('accueil'))->assertOk()
            ->assertSee('href="'.$this->url('mon-compte').'" class="sidebar-user"', false);
        $this->get($this->url('mon-compte'))->assertOk()
            ->assertSee('GARBA')->assertSee('secretaire@test.local')->assertSee('Secrétaire')->assertSee('Faculté de Droit')
            ->assertSee('Changer mon mot de passe')->assertSee('aria-current="page"', false);
    }

    public function test_changement_du_mot_de_passe(): void
    {
        $u = $this->creerUtilisateur('enseignant');
        RateLimiter::clear('mon_compte|'.$u->id);
        $this->connecte($u);

        $this->changer('Faux1234', 'Nouveau2026')->assertSessionHas('erreur', 'Mot de passe actuel incorrect.');
        $this->changer('MotDePasse1', 'Nouveau2026', 'Autre2026')->assertSessionHas('erreur', 'Les deux nouveaux mots de passe ne correspondent pas.');
        $this->changer('MotDePasse1', 'court1')->assertSessionHas('erreur');
        $this->changer('MotDePasse1', 'sanschiffres')->assertSessionHas('erreur');
        $this->changer('MotDePasse1', 'MotDePasse1')->assertSessionHas('erreur', "Choisissez un mot de passe différent de l'actuel.");
        $this->assertTrue(Hash::check('MotDePasse1', DB::table('utilisateurs')->where('id', $u->id)->value('mot_de_passe')));

        $this->changer('MotDePasse1', 'Nouveau2026')->assertRedirect($this->url('mon-compte'))->assertSessionHas('succes', 'Votre mot de passe a été modifié.');
        $hash = DB::table('utilisateurs')->where('id', $u->id)->value('mot_de_passe');
        $this->assertTrue(Hash::check('Nouveau2026', $hash));
        $this->assertNotSame('Nouveau2026', $hash);
        $this->assertDatabaseHas('audit_log', ['action' => 'modifier', 'module' => 'utilisateur', 'cible_id' => $u->id]);
    }

    public function test_essais_limites(): void
    {
        $u = $this->creerUtilisateur('secretaire');
        RateLimiter::clear('mon_compte|'.$u->id);
        $this->connecte($u);
        for ($i = 0; $i < 5; $i++) {
            $this->changer('Faux'.$i.'abc', 'Nouveau2026')->assertSessionHas('erreur', 'Mot de passe actuel incorrect.');
        }
        // Même le bon mot de passe est refusé tant que le blocage dure
        $this->changer('MotDePasse1', 'Nouveau2026')->assertSessionHas('erreur', fn ($m) => str_starts_with($m, "Trop d'essais"));
        $this->assertTrue(Hash::check('MotDePasse1', DB::table('utilisateurs')->where('id', $u->id)->value('mot_de_passe')));
        RateLimiter::clear('mon_compte|'.$u->id);
    }

    public function test_etudiant_y_accede_depuis_son_portail_mais_pas_avec_son_matricule(): void
    {
        $et = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Awa', 'numero' => 'ab123456']);
        $u = $this->creerUtilisateur('etudiant', ['etudiant_id' => $et]);
        RateLimiter::clear('mon_compte|'.$u->id);
        $this->connecte($u)->get($this->url('mon-compte'))->assertOk()->assertSee('ab123456');
        $this->changer('MotDePasse1', 'ab123456')->assertSessionHas('erreur', "Choisissez un mot de passe différent de l'actuel et de votre matricule.");
        $this->changer('MotDePasse1', 'Etudiant2026')->assertSessionHas('succes');
        // Le reste du personnel lui reste fermé
        $this->get($this->url('etudiants'))->assertRedirect();
    }
}
