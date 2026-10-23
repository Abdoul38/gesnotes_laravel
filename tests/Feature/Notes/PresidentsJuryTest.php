<?php

namespace Tests\Feature\Notes;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Présidents de jury : désignation par semestre, « Mes jurys », verrouillage, nom sur les documents. */
class PresidentsJuryTest extends TestCase
{
    private int $annee;

    private int $facA;

    private int $promo;

    private int $sem;

    private int $pv;

    private int $ens;

    private Utilisateur $admin;

    private Utilisateur $president;

    private Utilisateur $collegue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->donnerPermissions('enseignant', ['notes_saisir']);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte_id' => $this->facA]);
        $this->promo = $this->creerPromotion($this->annee, ['filiere_id' => $filiere]);
        $this->sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        DB::table('unites_enseignement')->insert(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $this->pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'normale', 'libelle' => 'PV S1 Économie']);
        $this->ens = DB::table('enseignants')->insertGetId(['nom' => 'Garba', 'prenoms' => 'Moussa', 'grade' => 'Pr']);
        $autre = DB::table('enseignants')->insertGetId(['nom' => 'Issa', 'prenoms' => 'Ali']);
        $this->admin = $this->creerUtilisateur('admin');
        $this->president = $this->creerUtilisateur('enseignant', ['enseignant_id' => $this->ens, 'email' => 'president@test.local']);
        $this->collegue = $this->creerUtilisateur('enseignant', ['enseignant_id' => $autre, 'email' => 'collegue@test.local']);
    }

    private function designer(Utilisateur $u, int $enseignant)
    {
        return $this->connecte($u)->post($this->url('jury/presidents'), ['annee_id' => $this->annee, 'enseignant_id' => $enseignant, 'semestres' => [$this->promo.'-'.$this->sem]]);
    }

    public function test_designation_par_semestre_et_perimetre(): void
    {
        $this->connecte($this->admin)->get($this->url('jury/presidents'))->assertOk()->assertSee('Économie')->assertSee('Semestre 1')->assertSee('Non désigné');
        $this->designer($this->admin, $this->ens)->assertSessionHas('succes');
        $this->assertDatabaseHas('presidents_jury', ['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'enseignant_id' => $this->ens]);
        $this->get($this->url('jury/presidents'))->assertSee('Garba Moussa')->assertDontSee('Non désigné');

        // Agent d'une autre faculté : semestre hors de son périmètre
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $this->donnerPermissions('sc_entite', ['pv_creer']);
        $agentB = $this->creerUtilisateur('sc_entite', ['faculte_id' => $facB, 'email' => 'agentb@test.local']);
        $this->connecte($agentB)->get($this->url('jury/presidents'))->assertOk()->assertDontSee('Économie')->assertDontSee('Semestre 1');
        $this->designer($agentB, $this->ens)->assertSessionHas('erreur');

        // Un seul président par semestre : le nouveau remplace l'ancien ; retrait
        $this->designer($this->admin, DB::table('enseignants')->where('nom', 'Issa')->value('id'))->assertSessionHas('succes');
        $this->assertSame(1, DB::table('presidents_jury')->count());
        $this->post($this->url('jury/presidents/retirer'), ['annee_id' => $this->annee, 'semestre' => $this->promo.'-'.$this->sem])->assertSessionHas('succes');
        $this->assertSame(0, DB::table('presidents_jury')->count());

        // Un enseignant ne désigne pas
        $this->designer($this->president, $this->ens);
        $this->assertSame(0, DB::table('presidents_jury')->count());
    }

    public function test_president_verrouille_imprime_mais_ne_saisit_pas(): void
    {
        $this->designer($this->admin, $this->ens);
        $this->connecte($this->president)->get($this->url('jury'))->assertOk()->assertSee('PV S1 Économie')->assertSee('Verrouiller');
        $this->connecte($this->collegue)->get($this->url('jury'))->assertOk()->assertSee("Vous n'êtes président d'aucun jury", false)->assertDontSee('PV S1 Économie');

        // Verrouillage : par le président seulement
        $this->post($this->url("jury/pv/{$this->pv}/verrou"), ['action' => 'verrouiller'])->assertSessionHas('erreur');
        $this->assertSame(0, (int) DB::table('pv_sessions')->where('id', $this->pv)->value('verrouille'));
        $this->connecte($this->president)->post($this->url("jury/pv/{$this->pv}/verrou"), ['action' => 'verrouiller'])->assertSessionHas('succes');
        $this->assertSame(1, (int) DB::table('pv_sessions')->where('id', $this->pv)->value('verrouille'));
        $this->post($this->url("jury/pv/{$this->pv}/verrou"), ['action' => 'deverrouiller'])->assertSessionHas('succes');
        $this->assertSame(0, (int) DB::table('pv_sessions')->where('id', $this->pv)->value('verrouille'));

        // Documents avec le nom du président ; refusés aux autres enseignants
        foreach (['pv', 'deliberation', 'admis'] as $doc) {
            $this->get($this->url("jury/pv/{$this->pv}/$doc"))->assertOk()->assertSee('Pr Moussa GARBA');
        }
        $this->connecte($this->collegue)->get($this->url("jury/pv/{$this->pv}/pv"))->assertRedirect($this->url('jury'));

        // Pas de saisie dans le PV
        $this->connecte($this->president)->post($this->url("pv/{$this->pv}/notes"), ['notes' => []])->assertRedirect();
        $this->get($this->url("pv/{$this->pv}"))->assertRedirect();

        // Nom aussi sur le PV imprimé par la scolarité
        $this->connecte($this->admin)->get($this->url("pv/impression?id={$this->pv}"))->assertOk()->assertSee('Pr Moussa GARBA');
    }
}
