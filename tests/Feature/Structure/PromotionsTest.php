<?php

namespace Tests\Feature\Structure;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromotionsTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private array $f = [];

    private array $n = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $fac1 = DB::table('facultes')->insertGetId(['code' => 'FADEG', 'libelle' => 'Droit']);
        $fac2 = DB::table('facultes')->insertGetId(['code' => 'FSA', 'libelle' => 'Agronomie']);
        $this->f['eco'] = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => 'Éco', 'faculte_id' => $fac1, 'actif' => 1]);
        $this->f['agr'] = DB::table('filieres')->insertGetId(['code' => 'AGR', 'libelle' => 'Agronomie', 'departement' => 'Prod', 'faculte_id' => $fac2, 'actif' => 1]);
        $this->f['old'] = DB::table('filieres')->insertGetId(['code' => 'OLD', 'libelle' => 'Ancienne', 'departement' => 'X', 'faculte_id' => $fac1, 'actif' => 0]);
        $this->n['l1'] = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $this->n['l2'] = DB::table('niveaux')->insertGetId(['code' => 'L2', 'libelle' => 'Licence 2']);
    }

    public function test_generer_toutes_les_combinaisons_actives(): void
    {
        $this->connecte($this->admin)->post($this->url('promotions/generer'), ['annee_id' => $this->annee])
            ->assertSessionHas('succes', '4 promotion(s) créée(s).');
        $this->assertSame(0, DB::table('promotions')->where('filiere_id', $this->f['old'])->count(), 'filière inactive ignorée');

        $this->connecte($this->admin)->post($this->url('promotions/generer'), ['annee_id' => $this->annee, 'filieres_ids' => [$this->f['eco']], 'niveaux_ids' => [$this->n['l1']]])
            ->assertSessionHas('succes', '0 promotion(s) créée(s) — 1 déjà existante(s).');
    }

    public function test_generer_sans_annee(): void
    {
        $this->connecte($this->admin)->post($this->url('promotions/generer'), [])
            ->assertSessionHas('erreur', 'Veuillez sélectionner une année.');
    }

    public function test_generer_limite_a_la_faculte_de_l_utilisateur(): void
    {
        $fac = DB::table('filieres')->where('id', $this->f['eco'])->value('faculte_id');
        $this->donnerPermissions('secretaire', ['inscription_ped']);
        $secretaire = $this->creerUtilisateur('secretaire', ['faculte_id' => $fac]);

        // Même en envoyant une filière d'une autre faculté
        $this->connecte($secretaire)->post($this->url('promotions/generer'), ['annee_id' => $this->annee, 'filieres_ids' => [$this->f['eco'], $this->f['agr']]])
            ->assertSessionHas('succes', '2 promotion(s) créée(s).');
        $this->assertSame(0, DB::table('promotions')->where('filiere_id', $this->f['agr'])->count());

        $page = $this->connecte($secretaire)->get($this->url('promotions'))->assertOk();
        $page->assertSee('ECO')->assertDontSee('AGR');
    }

    public function test_copier_une_annee(): void
    {
        $suivante = DB::table('annees_academiques')->insertGetId(['libelle' => '2027-2028']);
        $this->creerPromotion($this->annee, ['filiere_id' => $this->f['eco'], 'niveau_id' => $this->n['l1']]);
        $this->creerPromotion($this->annee, ['filiere_id' => $this->f['agr'], 'niveau_id' => $this->n['l2']]);

        $this->connecte($this->admin)->post($this->url('promotions/copier'), ['annee_source' => $this->annee, 'annee_dest' => $suivante])
            ->assertSessionHas('succes', '2 promotion(s) copiée(s) vers la nouvelle année.');
        $this->assertSame(2, DB::table('promotions')->where('annee_id', $suivante)->count());

        $this->connecte($this->admin)->post($this->url('promotions/copier'), ['annee_source' => $this->annee, 'annee_dest' => $this->annee])
            ->assertSessionHas('erreur', 'Années invalides.');
    }

    public function test_liste_par_defaut_sur_l_annee_active_puis_toutes(): void
    {
        $autre = DB::table('annees_academiques')->insertGetId(['libelle' => '2019-2020']);
        $this->creerPromotion($this->annee, ['filiere_id' => $this->f['eco'], 'niveau_id' => $this->n['l1']]);
        $this->creerPromotion($autre, ['filiere_id' => $this->f['agr'], 'niveau_id' => $this->n['l1']]);

        $this->connecte($this->admin)->get($this->url('promotions'))->assertSee('Promotions (1)');
        $this->connecte($this->admin)->get($this->url('promotions?annee_id=0'))->assertSee('Promotions (2)');
    }

    public function test_suppression_reservee_a_l_admin(): void
    {
        $id = $this->creerPromotion($this->annee, ['filiere_id' => $this->f['eco'], 'niveau_id' => $this->n['l1']]);
        $this->donnerPermissions('secretaire', ['inscription_ped']);

        $this->connecte($this->creerUtilisateur('secretaire'))->post($this->url('promotions/supprimer'), ['id' => $id])->assertForbidden();
        $this->assertDatabaseHas('promotions', ['id' => $id]);

        $this->connecte($this->admin)->post($this->url('promotions/supprimer'), ['id' => $id])->assertSessionHas('succes', 'Promotion supprimée.');
        $this->assertDatabaseMissing('promotions', ['id' => $id]);
        $this->assertDatabaseHas('journal_audit', ['action' => 'PROMO_SUPPRIMER', 'enregistrement_id' => $id]);
    }
}
