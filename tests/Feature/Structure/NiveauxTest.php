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
        DB::table('tarifs_niveaux')->insert(['niveau_id' => $id] + $this->colonnesObligatoires('tarifs_niveaux', ['niveau_id']));

        $this->connecte($this->admin)->post($this->url('niveaux/supprimer'), ['id' => $id])
            ->assertSessionHas('erreur', 'Impossible — ce niveau est encore utilisé : 1 tarif(s).');
        $this->assertDatabaseHas('niveaux', ['id' => $id]);
    }

    public function test_page_liste(): void
    {
        DB::table('niveaux')->insert(['code' => 'L3', 'libelle' => 'Licence 3']);
        $this->connecte($this->admin)->get($this->url('niveaux'))->assertOk()->assertSee('Licence 3')->assertSee('Niveaux (1)');
    }

    /** Valeurs factices pour les colonnes NOT NULL sans défaut d'une table. */
    private function colonnesObligatoires(string $table, array $sauf): array
    {
        $valeurs = [];
        foreach (DB::select('SELECT column_name AS c, data_type AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND is_nullable = \'NO\' AND column_default IS NULL AND extra NOT LIKE \'%auto_increment%\'', [$table]) as $col) {
            if (in_array($col->c, $sauf, true)) {
                continue;
            }
            $valeurs[$col->c] = match (true) {
                str_contains($col->t, 'int'), str_contains($col->t, 'decimal') => 1,
                str_contains($col->t, 'date') => '2026-01-01',
                default => 'x',
            };
        }

        return $valeurs;
    }
}
