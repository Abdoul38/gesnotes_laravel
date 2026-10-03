<?php

namespace Tests\Feature\Finances;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Paiements : liste, saisie, périmètre, CSV, reçu. */
class PaiementsTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $etB;

    private int $etA;

    private int $facA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $this->etA = $this->etudiantInscrit('ALPHA', $this->facA, 'FA1');
        $this->etB = $this->etudiantInscrit('BETA', $facB, 'FB1');
    }

    private function etudiantInscrit(string $nom, int $faculte, string $code): int
    {
        $filiere = DB::table('filieres')->insertGetId(['code' => $code, 'libelle' => "Filière $code", 'departement' => '', 'faculte_id' => $faculte]);
        $promo = $this->creerPromotion($this->annee, ['filiere_id' => $filiere]);
        $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'X', 'numero' => (string) random_int(10000, 99999)]);
        DB::table('inscriptions')->insert(['etudiant_id' => $et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);

        return $et;
    }

    private function paiement(int $et, array $champs = []): int
    {
        return DB::table('paiements')->insertGetId($champs + ['etudiant_id' => $et, 'type_paiement' => 'inscription', 'montant_du' => 50000,
            'montant_paye' => 20000, 'mode_paiement' => 'especes', 'statut' => 'partiel', 'date_paiement' => '2025-10-02', 'annee_id' => $this->annee]);
    }

    private function agentFaculteA(): Utilisateur
    {
        $this->donnerPermissions('sc_entite', ['paiements_voir', 'paiements_gerer']);

        return $this->creerUtilisateur('sc_entite', ['faculte_id' => $this->facA]);
    }

    public function test_liste_totaux_et_filtres(): void
    {
        $this->paiement($this->etA);
        $this->paiement($this->etB, ['montant_paye' => 50000, 'statut' => 'complet', 'type_paiement' => 'scolarite']);
        $this->connecte($this->admin)->get($this->url('paiements'))->assertOk()
            ->assertSee('Paiements (2)')->assertSee('100 000 FCFA')->assertSee('70 000 FCFA')->assertSee('30 000 FCFA');
        $this->get($this->url('paiements?statut=complet'))->assertOk()->assertSee('Paiements (1)')->assertSee('BETA');
        $this->get($this->url('paiements?type_paiement=nimporte'))->assertOk()->assertSee('Paiements (2)');
        $this->get($this->url("paiements?et_id={$this->etA}"))->assertOk()->assertSee('Paiements (1)')->assertSee('Matricule');

        // Agent de la faculté A : seulement ALPHA
        $this->connecte($this->agentFaculteA())->get($this->url('paiements'))->assertOk()->assertSee('Paiements (1)')->assertSee('ALPHA')->assertDontSee('BETA');
    }

    public function test_saisie_modification_suppression(): void
    {
        $this->connecte($this->admin);
        $champs = ['etudiant_id' => $this->etA, 'type_paiement' => 'scolarite', 'mode_paiement' => 'mobile_money', 'annee_id' => $this->annee,
            'montant_du' => '75000', 'montant_paye' => '75000', 'date_paiement' => '2025-11-05', 'reference' => 'MM-123'];
        $this->post($this->url('paiements'), $champs)->assertRedirect($this->url('paiements'))->assertSessionHas('succes');
        $p = DB::table('paiements')->first();
        $this->assertSame(['complet', $this->admin->id, 'MM-123'], [$p->statut, (int) $p->saisi_par, $p->reference]);

        // Valeurs refusées (avant : erreur SQL ou montant négatif enregistré)
        $this->post($this->url('paiements'), ['type_paiement' => 'cadeau'] + $champs)->assertSessionHas('erreur');
        $this->post($this->url('paiements'), ['mode_paiement' => 'komipay'] + $champs)->assertSessionHas('erreur');
        $this->post($this->url('paiements'), ['montant_paye' => '-500'] + $champs)->assertSessionHas('erreur');
        $this->post($this->url('paiements'), ['annee_id' => 9999] + $champs)->assertSessionHas('erreur');
        $this->assertSame(1, DB::table('paiements')->count());

        // Modification : statut recalculé, étudiant inchangé
        $this->post($this->url('paiements'), ['action' => 'modifier', 'id' => $p->id, 'etudiant_id' => $this->etB, 'montant_paye' => '30000'] + $champs)
            ->assertSessionHas('succes');
        $p = DB::table('paiements')->first();
        $this->assertSame(['partiel', $this->etA], [$p->statut, (int) $p->etudiant_id]);

        $this->post($this->url('paiements/supprimer'), ['id' => $p->id])->assertSessionHas('succes');
        $this->assertSame(0, DB::table('paiements')->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'supprimer', 'module' => 'paiement']);
    }

    public function test_perimetre_des_actions(): void
    {
        $pB = $this->paiement($this->etB);
        $agent = $this->agentFaculteA();
        $champs = ['type_paiement' => 'scolarite', 'mode_paiement' => 'especes', 'montant_du' => '1', 'montant_paye' => '1'];
        $this->connecte($agent);
        $this->post($this->url('paiements'), ['etudiant_id' => $this->etB] + $champs)->assertSessionHas('erreur', 'Étudiant introuvable.');
        $this->post($this->url('paiements'), ['action' => 'modifier', 'id' => $pB] + $champs)->assertSessionHas('erreur', 'Paiement introuvable.');
        $this->post($this->url('paiements/supprimer'), ['id' => $pB])->assertSessionHas('erreur', 'Paiement introuvable.');
        $this->assertSame(1, DB::table('paiements')->count());
        $this->get($this->url("paiements/recu?id=$pB"))->assertRedirect($this->url('paiements'));

        // Sans « paiements_gerer » : lecture seule
        $this->donnerPermissions('secretaire', ['paiements_voir']);
        $this->connecte($this->creerUtilisateur('secretaire'))->get($this->url('paiements'))->assertOk()->assertDontSee('Nouveau paiement');
        $this->post($this->url('paiements/supprimer'), ['id' => $pB])->assertRedirect($this->url('accueil'));
        $this->assertSame(1, DB::table('paiements')->count());
    }

    public function test_export_csv_sans_formules(): void
    {
        DB::table('etudiants')->where('id', $this->etA)->update(['nom' => '=HYPERLINK("http://x")']);
        $this->paiement($this->etA, ['reference' => '+CMD']);
        $csv = $this->connecte($this->admin)->get($this->url('paiements?export=csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+CMD", $csv);
        $this->assertStringContainsString('Montant dû', $csv);
    }

    public function test_recu(): void
    {
        $id = $this->paiement($this->etA, ['reference' => 'REC-9']);
        $this->connecte($this->admin)->get($this->url("paiements/recu?id=$id"))->assertOk()
            ->assertSee('Reçu de Paiement')->assertSee('N° REC-9')->assertSee('20 000 FCFA')->assertSee('Reste à payer : 30 000 FCFA');
        $this->donnerPermissions('enseignant', ['paiements_voir']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url("paiements/recu?id=$id"))->assertRedirect($this->url('accueil'));
    }

    public function test_recu_inscription(): void
    {
        $ins = DB::table('inscriptions')->where('etudiant_id', $this->etA)->value('id');
        $this->connecte($this->admin)->get($this->url("inscriptions/recu?et_id={$this->etA}"))->assertOk()
            ->assertSee("Reçu d'Inscription", false)->assertSee('ALPHA X')->assertSee('2025-2026')->assertSee('INS-2025-'.str_pad((string) $ins, 6, '0', STR_PAD_LEFT));
        // Inscription annulée : plus de reçu « officiellement inscrit »
        DB::table('inscriptions')->where('id', $ins)->update(['statut' => 'annule']);
        $this->get($this->url("inscriptions/recu?et_id={$this->etA}&ins_id=$ins"))->assertSessionHas('erreur');
        // Autre faculté
        $this->connecte($this->agentFaculteA())->get($this->url("inscriptions/recu?et_id={$this->etB}"))->assertSessionHas('erreur');
    }

    public function test_finances_apercu_sans_doublons(): void
    {
        // ALPHA a deux inscriptions dans la faculté A : son paiement ne doit compter qu'une fois
        $promo2 = $this->creerPromotion($this->annee, ['filiere_id' => DB::table('filieres')->where('code', 'FA1')->value('id')]);
        DB::table('inscriptions')->insert(['etudiant_id' => $this->etA, 'promotion_id' => $promo2, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        $this->paiement($this->etA, ['montant_paye' => 20000, 'date_paiement' => date('Y-m-d')]);
        $this->paiement($this->etB, ['montant_paye' => 7000]);
        $cat = DB::table('categories_depenses')->insertGetId(['libelle' => 'Loyer', 'predefinie' => 1]);
        DB::table('depenses')->insert(['categorie_id' => $cat, 'libelle' => 'Loyer octobre', 'montant' => 5000, 'date_depense' => date('Y-m-d'), 'annee_id' => $this->annee]);

        $this->connecte($this->admin)->get($this->url('finances'))->assertOk()
            ->assertSee('27 000 FCFA')->assertSee('5 000 FCFA')->assertSee('22 000 FCFA')->assertSee('donnees-finances', false);
        $this->connecte($this->agentFaculteA())->get($this->url('finances'))->assertOk()->assertSee('20 000 FCFA')->assertDontSee('40 000 FCFA');
    }

    public function test_tarifs_et_depenses(): void
    {
        $filA = DB::table('filieres')->where('code', 'FA1')->value('id');
        $filB = DB::table('filieres')->where('code', 'FB1')->value('id');
        $niveau = DB::table('promotions')->value('niveau_id');
        $this->connecte($this->admin);
        $this->post($this->url('finances/tarifs'), ['filiere_id' => $filA, 'niveau_id' => $niveau, 'type_paiement' => 'inscription', 'montant' => '15000'])
            ->assertSessionHas('succes');
        $this->post($this->url('finances/tarifs'), ['filiere_id' => $filA, 'niveau_id' => $niveau, 'type_paiement' => 'inscription', 'montant' => '18000']);
        $this->assertEquals([1, 18000], [DB::table('grille_tarifaire')->count(), (float) DB::table('grille_tarifaire')->value('montant')]);
        $this->post($this->url('finances/tarifs'), ['filiere_id' => $filA, 'niveau_id' => $niveau, 'type_paiement' => 'cadeau', 'montant' => '1'])->assertSessionHas('erreur');
        $this->get($this->url('finances?tab=tarifs'))->assertOk()->assertSee('18 000 FCFA');

        $cat = DB::table('categories_depenses')->insertGetId(['libelle' => 'Transport', 'predefinie' => 1]);
        $dep = ['categorie_id' => $cat, 'libelle' => 'Carburant', 'montant' => '12000', 'date_depense' => '2025-11-02', 'mode_paiement' => 'especes', 'annee_id' => $this->annee];
        $this->post($this->url('finances/depenses'), $dep)->assertSessionHas('succes');
        $this->post($this->url('finances/depenses'), ['montant' => '-5'] + $dep)->assertSessionHas('erreur');
        $this->post($this->url('finances/depenses'), ['categorie_id' => 9999] + $dep)->assertSessionHas('erreur');
        $id = DB::table('depenses')->value('id');
        $this->post($this->url('finances/depenses'), ['action' => 'modifier', 'id' => $id, 'montant' => '13000'] + $dep)->assertSessionHas('succes');
        $this->assertEquals(13000, DB::table('depenses')->value('montant'));
        $this->get($this->url('finances?tab=depenses'))->assertOk()->assertSee('Carburant')->assertSee('Électricité &amp; eau', false);

        // Agent de la faculté A : tarifs de sa faculté seulement, pas de modification des dépenses (communes)
        $agent = $this->agentFaculteA();
        $this->connecte($agent);
        $this->post($this->url('finances/tarifs'), ['filiere_id' => $filB, 'niveau_id' => $niveau, 'type_paiement' => 'inscription', 'montant' => '1'])
            ->assertSessionHas('erreur', 'Filière ou niveau introuvable.');
        $this->post($this->url('finances/depenses/supprimer'), ['id' => $id])->assertSessionHas('erreur');
        $this->post($this->url('finances/depenses'), $dep)->assertSessionHas('erreur');
        $this->get($this->url('finances?tab=depenses'))->assertOk()->assertDontSee('Nouvelle dépense');
        $this->assertSame(1, DB::table('depenses')->count());

        $this->connecte($this->admin)->post($this->url('finances/depenses/supprimer'), ['id' => $id])->assertSessionHas('succes');
        $this->assertDatabaseHas('audit_log', ['action' => 'supprimer', 'module' => 'depense']);
    }
}
