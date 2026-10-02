<?php

namespace Tests\Feature\Structure;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SallesTest extends TestCase
{
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
    }

    private function salle(array $valeurs = []): int
    {
        return DB::table('salles')->insertGetId($valeurs + ['code' => 'A101', 'nom' => 'Amphi A', 'capacite' => 300, 'type' => 'amphitheatre']);
    }

    public function test_creation_et_permission_creee(): void
    {
        $this->connecte($this->admin)->get($this->url('salles'))->assertOk();
        $this->assertDatabaseHas('permissions', ['code' => 'salles_gerer', 'categorie' => 'Infrastructure']);

        $this->connecte($this->admin)->post($this->url('salles'), [
            'code' => ' B12 ', 'nom' => 'Salle TD 12', 'capacite' => '-5', 'type' => 'inconnu', 'batiment' => '', 'equipements' => 'Vidéoprojecteur',
        ])->assertSessionHas('succes', '✅ Salle « Salle TD 12 » créée.');

        $this->assertDatabaseHas('salles', ['code' => 'B12', 'capacite' => 0, 'type' => 'autre', 'batiment' => null, 'equipements' => 'Vidéoprojecteur']);
        $this->assertDatabaseHas('journal_audit', ['action' => 'CREER_SALLE', 'table_cible' => 'salles']);
    }

    public function test_code_en_double_et_champs_obligatoires(): void
    {
        $this->salle();
        $this->connecte($this->admin)->post($this->url('salles'), ['code' => 'A101', 'nom' => 'Autre'])
            ->assertSessionHas('erreur', 'Le code « A101 » est déjà utilisé par une autre salle.');
        $this->connecte($this->admin)->post($this->url('salles'), ['code' => '', 'nom' => 'X'])
            ->assertSessionHas('erreur', 'Le code et le nom sont obligatoires.');
    }

    public function test_modification(): void
    {
        $id = $this->salle();
        $this->connecte($this->admin)->post($this->url('salles'), ['id' => $id, 'code' => 'A101', 'nom' => 'Grand amphi', 'capacite' => 350, 'type' => 'amphitheatre'])
            ->assertSessionHas('succes', '✅ Salle « Grand amphi » mise à jour.');
        $this->assertDatabaseHas('salles', ['id' => $id, 'nom' => 'Grand amphi', 'capacite' => 350]);
    }

    public function test_filtres_et_retour_avec_le_type(): void
    {
        $this->salle();
        $this->salle(['code' => 'L1', 'nom' => 'Labo chimie', 'type' => 'laboratoire', 'capacite' => 30]);

        $page = $this->connecte($this->admin)->get($this->url('salles?type=laboratoire'))->assertOk();
        $page->assertSee('Labo chimie')->assertDontSee('Amphi A')->assertSee('(filtrées)');

        $this->connecte($this->admin)->post($this->url('salles/basculer?type=laboratoire'), ['id' => DB::table('salles')->where('code', 'L1')->value('id')])
            ->assertRedirect($this->url('salles?type=laboratoire'));
    }

    public function test_bouton_modifier_sans_script_en_ligne(): void
    {
        $this->salle();
        $html = $this->connecte($this->admin)->get($this->url('salles'))->getContent();
        $this->assertStringContainsString('data-onclick="ouvrirModal" data-args="[{&quot;id&quot;:', $html);
        $this->assertStringNotContainsString('onclick=', str_replace('data-onclick=', '', $html));
    }

    public function test_suppression_reservee_a_l_admin_meme_par_requete_forgee(): void
    {
        $id = $this->salle();
        $this->donnerPermissions('secretaire', ['inscription_ped', 'salles_gerer']);
        $secretaire = $this->creerUtilisateur('secretaire');

        $this->connecte($secretaire)->get($this->url('salles'))->assertOk()->assertDontSee('Supprimer A101');
        $this->connecte($secretaire)->post($this->url('salles/supprimer'), ['id' => $id])
            ->assertSessionHas('erreur', 'Seul un administrateur peut supprimer une salle.');
        $this->assertDatabaseHas('salles', ['id' => $id]);

        $this->connecte($this->admin)->post($this->url('salles/supprimer'), ['id' => $id])->assertSessionHas('succes', 'Salle supprimée.');
        $this->assertDatabaseMissing('salles', ['id' => $id]);
    }

    public function test_acces_exige_salles_gerer(): void
    {
        $this->donnerPermissions('secretaire', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('secretaire'))->get($this->url('salles'))->assertRedirect($this->url('accueil'));
    }
}
