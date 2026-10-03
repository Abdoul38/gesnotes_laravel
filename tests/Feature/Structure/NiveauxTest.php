<?php

namespace Tests\Feature\Structure;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NiveauxTest extends TestCase
{
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
    }

    public function test_creation_code_en_majuscules(): void
    {
        $this->connecte($this->admin)->post($this->url('niveaux'), ['code' => ' dut1 ', 'libelle' => ' DUT 1ère Année '])
            ->assertRedirect($this->url('niveaux'))->assertSessionHas('succes', 'Niveau «DUT1» créé.');
        $this->assertDatabaseHas('niveaux', ['code' => 'DUT1', 'libelle' => 'DUT 1ère Année']);
    }

    public function test_champs_requis_longueur_et_doublon(): void
    {
        $this->connecte($this->admin)->post($this->url('niveaux'), ['code' => 'L1', 'libelle' => ''])
            ->assertSessionHas('erreur', 'Code et libellé requis.');
        $this->connecte($this->admin)->post($this->url('niveaux'), ['code' => 'ABCDEFGHIJK', 'libelle' => 'Trop long'])
            ->assertSessionHas('erreur', 'Le code ne doit pas dépasser 10 caractères.');
        DB::table('niveaux')->insert(['code' => 'L1', 'libelle' => 'Licence 1']);
        $this->connecte($this->admin)->post($this->url('niveaux'), ['code' => 'l1', 'libelle' => 'Autre'])
            ->assertSessionHas('erreur', 'Le code «L1» est déjà utilisé par un autre niveau.');
    }

    public function test_modification_y_compris_en_gardant_son_code(): void
    {
        $id = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);

        $this->connecte($this->admin)->post($this->url('niveaux'), ['id' => $id, 'code' => 'L1', 'libelle' => 'Licence première année'])
            ->assertSessionHas('succes', 'Niveau mis à jour.');
        $this->assertDatabaseHas('niveaux', ['id' => $id, 'libelle' => 'Licence première année']);
    }

    public function test_suppression_libre_et_bloquee(): void
    {
        $libre = DB::table('niveaux')->insertGetId(['code' => 'X1', 'libelle' => 'Libre']);
        $this->connecte($this->admin)->post($this->url('niveaux/supprimer'), ['id' => $libre])
            ->assertSessionHas('succes', 'Niveau supprimé.');
        $this->assertDatabaseMissing('niveaux', ['id' => $libre]);

        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027']);
        $utilise = DB::table('niveaux')->insertGetId(['code' => 'L2', 'libelle' => 'Licence 2']);
        $this->creerPromotion($annee, ['niveau_id' => $utilise]);
        $this->connecte($this->admin)->post($this->url('niveaux/supprimer'), ['id' => $utilise])
            ->assertSessionHas('erreur', 'Impossible — 1 promotion(s) utilisent déjà ce niveau.');
        $this->assertDatabaseHas('niveaux', ['id' => $utilise]);
    }

    public function test_suppression_bloquee_si_tarifs(): void
    {
        $id = DB::table('niveaux')->insertGetId(['code' => 'M1', 'libelle' => 'Master 1']);
        $this->insererMinimal('tarifs_niveaux', ['niveau_id' => $id]);

        $this->connecte($this->admin)->post($this->url('niveaux/supprimer'), ['id' => $id])
            ->assertSessionHas('erreur', 'Impossible — ce niveau est encore utilisé : 1 tarif(s).');
        $this->assertDatabaseHas('niveaux', ['id' => $id]);
    }

    public function test_page_liste(): void
    {
        DB::table('niveaux')->insert(['code' => 'L3', 'libelle' => 'Licence 3']);
        $this->connecte($this->admin)->get($this->url('niveaux'))->assertOk()->assertSee('Licence 3')->assertSee('Niveaux (1)');
    }
}
