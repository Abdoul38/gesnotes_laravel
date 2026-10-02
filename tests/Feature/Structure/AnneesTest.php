<?php

namespace Tests\Feature\Structure;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnneesTest extends TestCase
{
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
    }

    private function annee(string $libelle, int $active = 0): int
    {
        return DB::table('annees_academiques')->insertGetId(['libelle' => $libelle, 'active' => $active]);
    }

    public function test_liste_avec_compteurs(): void
    {
        $a = $this->annee('2025-2026', 1);
        $this->creerPromotion($a);

        $this->connecte($this->admin)->get($this->url('annees'))
            ->assertOk()->assertSee('2025-2026')->assertSee('🟢 Active')->assertSee('En cours');
    }

    public function test_creation(): void
    {
        $this->connecte($this->admin)->post($this->url('annees'), ['libelle' => ' 2026-2027 ', 'date_debut' => '2026-10-01', 'date_fin' => ''])
            ->assertRedirect($this->url('annees'))->assertSessionHas('succes', 'Année 2026-2027 créée.');
        $this->assertDatabaseHas('annees_academiques', ['libelle' => '2026-2027', 'date_debut' => '2026-10-01', 'date_fin' => null, 'active' => 0]);
    }

    public function test_creation_format_invalide_ou_doublon(): void
    {
        $this->connecte($this->admin)->post($this->url('annees'), ['libelle' => '2026/2027'])
            ->assertSessionHas('erreur', 'Format attendu : 2024-2025');
        $this->annee('2026-2027');
        $this->connecte($this->admin)->post($this->url('annees'), ['libelle' => '2026-2027'])
            ->assertSessionHas('erreur', 'Cette année existe déjà.');
    }

    public function test_activation_une_seule_annee_active(): void
    {
        $ancienne = $this->annee('2025-2026', 1);
        $nouvelle = $this->annee('2026-2027');

        $this->connecte($this->admin)->post($this->url('annees/activer'), ['id' => $nouvelle])
            ->assertSessionHas('succes', 'Année activée.');
        $this->assertSame(0, (int) DB::table('annees_academiques')->where('id', $ancienne)->value('active'));
        $this->assertSame(1, (int) DB::table('annees_academiques')->where('id', $nouvelle)->value('active'));
        $this->assertDatabaseHas('journal_audit', ['action' => 'ANNEE_ACTIVER', 'enregistrement_id' => $nouvelle]);
    }

    public function test_suppression(): void
    {
        $id = $this->annee('2020-2021');
        $this->connecte($this->admin)->post($this->url('annees/supprimer'), ['id' => $id])
            ->assertSessionHas('succes', 'Année supprimée.');
        $this->assertDatabaseMissing('annees_academiques', ['id' => $id]);
    }

    public function test_suppression_refusee_si_active(): void
    {
        $id = $this->annee('2025-2026', 1);
        $this->connecte($this->admin)->post($this->url('annees/supprimer'), ['id' => $id])->assertSessionHas('erreur');
        $this->assertDatabaseHas('annees_academiques', ['id' => $id]);
    }

    public function test_suppression_refusee_si_promotions_meme_par_requete_forgee(): void
    {
        // L'ancienne page masquait seulement le bouton : la cascade aurait
        // effacé promotions, inscriptions pédagogiques, emploi du temps…
        $id = $this->annee('2020-2021');
        $this->creerPromotion($id);

        $this->connecte($this->admin)->post($this->url('annees/supprimer'), ['id' => $id])
            ->assertSessionHas('erreur', 'Impossible : des promotions sont rattachées à cette année.');
        $this->assertDatabaseHas('annees_academiques', ['id' => $id]);
        $this->assertSame(1, DB::table('promotions')->where('annee_id', $id)->count());
    }

    public function test_acces_permission_et_roles(): void
    {
        // Permission de la page (inscription_ped) mais rôle non autorisé
        $this->donnerPermissions('sc_entite', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('sc_entite'))->get($this->url('annees'))
            ->assertRedirect($this->url('accueil'))->assertSessionHas('erreur', "Accès réservé à l'administration.");

        // Rôle autorisé mais sans la permission de la page
        $this->connecte($this->creerUtilisateur('sc_centrale'))->get($this->url('annees'))
            ->assertRedirect($this->url('accueil'));
        $this->connecte($this->creerUtilisateur('sc_centrale', ['email' => 'sc2@test.local']))
            ->post($this->url('annees'), ['libelle' => '2030-2031'])->assertRedirect($this->url('accueil'));
        $this->assertDatabaseMissing('annees_academiques', ['libelle' => '2030-2031']);

        // Les deux → accès
        $this->donnerPermissions('sc_centrale', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('sc_centrale', ['email' => 'sc3@test.local']))->get($this->url('annees'))->assertOk();
    }
}
