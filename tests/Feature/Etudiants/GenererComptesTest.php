<?php

namespace Tests\Feature\Etudiants;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GenererComptesTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $promo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->promo = $this->creerPromotion($this->annee);
    }

    private function inscrire(string $nom, string $prenoms, ?string $numero, ?int $promo = null): int
    {
        $id = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => $prenoms, 'numero' => $numero]);
        DB::table('inscriptions')->insert(['etudiant_id' => $id, 'promotion_id' => $promo ?? $this->promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);

        return $id;
    }

    private function generer(array $options = [])
    {
        return $this->connecte($this->admin)->post($this->url('etudiants/comptes'), $options + ['annee_id' => $this->annee, 'promo_id' => $this->promo]);
    }

    public function test_generation_et_etudiant_sans_matricule(): void
    {
        $a = $this->inscrire('ÉLODIE', 'Aïcha Mariam', '00012');
        $this->inscrire('SANS', 'Matricule', null);

        $this->generer()->assertOk()->assertSee('aicha.elodie.00012@udh.ne')->assertSee('✅ Créé', false)
            ->assertSee('Pas encore de matricule');

        $compte = DB::table('utilisateurs')->where('etudiant_id', $a)->first();
        $this->assertSame(['aicha.elodie.00012@udh.ne', 'etudiant', 1], [$compte->email, $compte->role, (int) $compte->actif]);
        $this->assertTrue(Hash::check('00012', $compte->mot_de_passe));
        $this->assertSame(1, DB::table('utilisateurs')->whereNotNull('etudiant_id')->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'generer', 'module' => 'comptes_etudiants']);
    }

    public function test_compte_existant_garde_sauf_si_ecraser(): void
    {
        $a = $this->inscrire('SALEY', 'Amina', '00031');
        $this->creerUtilisateur('etudiant', ['email' => 'amina@perso.ne', 'etudiant_id' => $a, 'mot_de_passe' => Hash::make('choisi')]);

        $this->generer()->assertOk()->assertSee('⏭️ Existant', false);
        $this->assertSame('amina@perso.ne', DB::table('utilisateurs')->where('etudiant_id', $a)->value('email'));

        $this->generer(['ecraser' => '1'])->assertOk()->assertSee('🔄 Mis à jour', false);
        $compte = DB::table('utilisateurs')->where('etudiant_id', $a)->first();
        $this->assertSame('amina.saley.00031@udh.ne', $compte->email);
        $this->assertTrue(Hash::check('00031', $compte->mot_de_passe));
    }

    public function test_identifiant_deja_pris(): void
    {
        $this->creerUtilisateur('secretaire', ['email' => 'ali.garba.00005@udh.ne']);
        $a = $this->inscrire('GARBA', 'Ali', '00005');
        $this->generer();
        $this->assertSame('ali.garba.00005.2@udh.ne', DB::table('utilisateurs')->where('etudiant_id', $a)->value('email'));
    }

    public function test_toutes_les_promotions_de_l_annee_seulement(): void
    {
        $autreAnnee = DB::table('annees_academiques')->insertGetId(['libelle' => '2024-2025']);
        $this->inscrire('A', 'Un', '00001', $this->creerPromotion($this->annee));
        $this->inscrire('B', 'Deux', '00002');
        $this->inscrire('C', 'Trois', '00003', $this->creerPromotion($autreAnnee));

        $this->generer(['tous' => '1']);
        $this->assertSame(2, DB::table('utilisateurs')->whereNotNull('etudiant_id')->count());
    }

    public function test_comptes_existants_et_impression(): void
    {
        $a = $this->inscrire('SALEY', 'Amina', '00031');
        $this->generer();

        $this->connecte($this->admin)->get($this->url("etudiants/comptes?annee_id={$this->annee}&voir_promo={$this->promo}"))->assertOk()
            ->assertSee('1 compte(s) trouvé(s)')->assertSee('amina.saley.00031@udh.ne')->assertSee('Jamais');
        $this->get($this->url("etudiants/comptes/imprimer?voir_promo={$this->promo}"))->assertOk()
            ->assertSee('Identifiants de connexion')->assertSee('<span class="mdp">00031</span>', false);
        $this->get($this->url('etudiants/comptes/imprimer?voir_promo=999999'))->assertNotFound();
    }

    public function test_reserve_admin_et_sc_centrale(): void
    {
        $this->donnerPermissions('secretaire', ['utilisateurs_gerer']);
        $this->connecte($this->creerUtilisateur('secretaire'))->post($this->url('etudiants/comptes'), ['annee_id' => $this->annee])->assertRedirect();
        $this->assertSame(0, DB::table('utilisateurs')->whereNotNull('etudiant_id')->count());
    }
}
