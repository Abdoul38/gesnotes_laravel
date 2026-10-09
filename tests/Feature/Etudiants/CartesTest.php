<?php

namespace Tests\Feature\Etudiants;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartesTest extends TestCase
{
    private Utilisateur $admin;

    private int $et;

    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'FSEJ']);
        $niveau = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $promo = $this->creerPromotion($this->annee, ['filiere_id' => $filiere, 'niveau_id' => $niveau]);
        $this->et = DB::table('etudiants')->insertGetId([
            'nom' => 'SALEY', 'prenoms' => 'Amina', 'numero' => '00031', 'filiere_id' => $filiere,
            'date_naissance' => '2004-05-06', 'lieu_naissance' => 'Tahoua', 'nationalite' => 'Malienne',
        ]);
        DB::table('inscriptions')->insert(['etudiant_id' => $this->et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
    }

    public function test_page_de_selection(): void
    {
        $this->connecte($this->admin)->get($this->url('etudiants/cartes'))->assertOk()
            ->assertSee('00031 — SALEY Amina')->assertSee('FSEJ');
    }

    public function test_carte_individuelle_enregistree_et_numero_reutilise(): void
    {
        $prefixe = 'CE'.date('y').'-';
        $this->connecte($this->admin)->get($this->url("etudiants/cartes/imprimer?id={$this->et}"))->assertOk()
            ->assertSee('SALEY')->assertSee('Économie')->assertSee('L1')->assertSee('2025-2026')
            ->assertSee('Malienne')->assertSee('06/05/2004 à Tahoua')
            ->assertSee("N° {$prefixe}000001")->assertSee('data-qr=', false)->assertSee('<svg', false)
            ->assertDontSee('<script>', false);

        $this->get($this->url("etudiants/cartes/imprimer?id={$this->et}"))->assertOk()->assertSee("{$prefixe}000001");
        $this->assertSame(1, DB::table('cartes_emises')->count());
    }

    public function test_numerotation_apres_suppression_ne_reprend_pas_un_numero_existant(): void
    {
        $prefixe = 'CE'.date('y').'-';
        // L'original comptait les lignes : avec 000002 seul, il recalculait 000002 → doublon
        DB::table('cartes_emises')->insert(['numero' => $prefixe.'000002', 'etudiant_id' => 999, 'annee_academique' => '2024-2025', 'nom' => 'X', 'prenoms' => 'Y']);

        $this->connecte($this->admin)->get($this->url("etudiants/cartes/imprimer?id={$this->et}"))->assertOk()
            ->assertSee("{$prefixe}000003");
    }

    public function test_generation_en_masse_par_faculte(): void
    {
        $autre = DB::table('etudiants')->insertGetId(['nom' => 'ZAKARI', 'prenoms' => 'Ali', 'numero' => '00032']);
        $promoAutre = $this->creerPromotion($this->annee, ['filiere_id' => DB::table('filieres')->insertGetId(['code' => 'MAT', 'libelle' => 'Maths', 'departement' => '', 'faculte' => 'FST'])]);
        DB::table('inscriptions')->insert(['etudiant_id' => $autre, 'promotion_id' => $promoAutre, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);

        $this->connecte($this->admin)->get($this->url("etudiants/cartes/imprimer?masse=1&annee_id={$this->annee}&faculte=FSEJ"))->assertOk()
            ->assertSee('(1 cartes)')->assertSee('SALEY')->assertDontSee('ZAKARI');
        $this->get($this->url("etudiants/cartes/imprimer?masse=1&annee_id={$this->annee}"))->assertOk()
            ->assertSee('(2 cartes)')->assertSee('ZAKARI');
    }

    public function test_sans_etudiant_retour_a_la_selection(): void
    {
        $this->connecte($this->admin)->get($this->url('etudiants/cartes/imprimer'))->assertRedirect($this->url('etudiants/cartes'));
    }

    public function test_reserve_aux_roles_administratifs(): void
    {
        // Même avec la permission, un enseignant n'y a pas accès (contrôle de rôle de l'original)
        $this->donnerPermissions('enseignant', ['carte_etudiant']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('etudiants/cartes'))->assertRedirect();
        $this->assertSame(0, DB::table('cartes_emises')->count());
    }

    public function test_seule_la_signature_de_l_autorite_figure_sur_la_carte(): void
    {
        $this->connecte($this->admin);
        foreach (['administratif', 'moderne', 'badge'] as $modele) {
            DB::table('parametres')->updateOrInsert(['cle' => 'carte_etudiant_modele'], ['valeur' => $modele]);
            $this->get($this->url("etudiants/cartes/imprimer?id={$this->et}"))->assertOk()
                ->assertSee("Signature de l'autorité", false)->assertDontSee('Signature du titulaire')->assertDontSee('c-cachet', false);
        }
    }

    public function test_cachet_et_signature_reserves_aux_connectes(): void
    {
        $this->get($this->url('fichiers/officiels/cachet'))->assertRedirect();
        $this->connecte($this->admin)->get($this->url('fichiers/officiels/autre'))->assertNotFound();
    }

    public function test_melange_des_couleurs(): void
    {
        $this->assertSame('#000000', \App\Http\Controllers\Etudiants\CartesController::melanger('#000000', 0.5, true));
        $this->assertSame('#808080', \App\Http\Controllers\Etudiants\CartesController::melanger('#ffffff', 0.5, true));
        $this->assertSame('#ffffff', \App\Http\Controllers\Etudiants\CartesController::melanger('#ffffff', 0.5, false));
    }
}
