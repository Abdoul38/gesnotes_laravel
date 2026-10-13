<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** API mobile : inscription académique en ligne de l'étudiant connecté (mêmes règles et même paiement que le portail). */
class ApiInscriptionAcademiqueTest extends TestCase
{
    private int $annee;

    private int $ancien;

    private int $nouveau;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        Cache::flush();
        config(['komipay.login' => 'test', 'komipay.api_key' => 'cle-test', 'komipay.moyens' => ['mynita', 'amana', 'visa_master']]);
        $precedente = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 0]);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $l1 = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $l2 = DB::table('niveaux')->insertGetId(['code' => 'L2', 'libelle' => 'Licence 2']);
        DB::table('tarifs_niveaux')->insert(['niveau_id' => $l2, 'montant' => 15000]);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'FSEJ', 'actif' => 1]);
        $mdp = Hash::make('Secret123');
        $this->ancien = DB::table('etudiants')->insertGetId(['numero' => '00007', 'nom' => 'ALPHA', 'prenoms' => 'Awa', 'filiere_id' => $filiere,
            'telephone' => '90000000', 'password_hash' => $mdp]);
        $this->nouveau = DB::table('etudiants')->insertGetId(['numero' => null, 'numero_dossier' => 'D123456', 'nom' => 'BETA', 'prenoms' => 'Zara',
            'filiere_id' => $filiere, 'password_hash' => $mdp]);
        DB::table('passages_niveau')->insert(['etudiant_id' => $this->ancien, 'filiere_id' => $filiere, 'niveau_actuel_id' => $l1, 'niveau_suivant_id' => $l2,
            'annee_depart_id' => $precedente, 'annee_arrivee_id' => $this->annee, 'decision' => 'passage', 'date_decision' => '2026-07-01']);
        foreach (['00007', 'D123456'] as $id) {
            RateLimiter::clear("api_mobile|udh|login|127.0.0.1|$id");
        }
    }

    private function komipay(string $statut = 'Success'): void
    {
        Http::fake([
            '*generateToken' => Http::response(['statut' => true, 'token' => 'jeton']),
            '*b2c_standard' => Http::response(['statut' => true, 'reference_transaction' => 'REF-'.uniqid(), 'code_achat' => 'C-4242', 'message' => 'OK']),
            '*check-transaction-status' => Http::response(['statut' => true, 'data' => ['transactionStatus' => $statut], 'message' => '']),
        ]);
    }

    private function jeton(string $identifiant): string
    {
        return $this->postJson($this->url('api_mobile.php?action=login'), ['identifiant' => $identifiant, 'password' => 'Secret123'])->json('data.token');
    }

    private function api(string $jeton, string $action, array $donnees = [], string $methode = 'post'): TestResponse
    {
        $entetes = ['Authorization' => "Bearer $jeton"];

        return $methode === 'get'
            ? $this->getJson($this->url('api_mobile.php?'.http_build_query(['action' => $action] + $donnees)), $entetes)
            : $this->postJson($this->url("api_mobile.php?action=$action"), $donnees, $entetes);
    }

    public function test_parcours_complet_ancien_etudiant(): void
    {
        $this->komipay();
        $j = $this->jeton('00007');

        // Avant : rien d'ouvert ; carte bancaire réservée au portail web (3-D Secure)
        $this->api($j, 'inscription_academique', [], 'get')->assertOk()->assertJson(['data' => ['ouverte' => true, 'annee' => '2026-2027', 'inscrit' => false,
            'en_ligne' => null, 'moyens' => ['mynita' => 'MyNITA', 'amana' => 'Amana Microfinance'], 'carte_sur_le_web' => true, 'telephone' => '90000000']])
            ->assertJsonMissingPath('data.moyens.visa_master');

        // Ouverture : identifié par son jeton, niveau tiré de la décision de passage, tarif + 3 % de frais
        $etat = $this->api($j, 'inscription_academique_demarrer')->assertOk()->json('data.en_ligne');
        $this->assertSame(['L2', 'Économie', 15000, 450, 15450], [$etat['niveau_code'], $etat['filiere'], (int) $etat['montant']['base'], (int) $etat['montant']['frais'], (int) $etat['montant']['total']]);
        $id = $etat['id'];
        // Reprise plutôt que doublon
        $this->assertSame($id, $this->api($j, 'inscription_academique_demarrer')->json('data.en_ligne.id'));

        $this->api($j, 'inscription_academique_payer', ['id' => $id, 'moyen' => 'visa_master', 'telephone' => '90000000'])
            ->assertStatus(400)->assertJson(['message' => 'Moyen de paiement invalide.']);
        $this->api($j, 'inscription_academique_payer', ['id' => $id, 'moyen' => 'mynita', 'telephone' => 'abc'])->assertStatus(400);
        $this->api($j, 'inscription_academique_payer', ['id' => $id, 'moyen' => 'mynita', 'telephone' => '90 00 00 00'])->assertOk()
            ->assertJson(['data' => ['en_ligne' => ['statut_paiement' => 'en_attente', 'moyen_paiement' => 'mynita', 'code_achat' => 'C-4242']]]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'b2c_standard') && $r['montant_a_payer'] === '15 450' && $r['numero_telephone_payeur'] === '+22790000000');
        // Pas de second paiement tant que le premier est en attente
        $this->api($j, 'inscription_academique_payer', ['id' => $id, 'moyen' => 'amana', 'telephone' => '90000000'])->assertStatus(400);

        // Validation sur le téléphone → suivi : inscription finalisée, reçu disponible
        $r = $this->api($j, 'inscription_academique_statut', ['id' => $id], 'get')->assertOk()
            ->assertJson(['data' => ['statut' => 'valide', 'inscrit' => true, 'en_ligne' => ['statut' => 'complete', 'matricule' => '00007']]]);
        $recu = $this->api($j, 'recu', ['ins_id' => $r->json('data.inscription_id')], 'get')->assertOk()->json('data');
        $this->assertSame(['00007', 15000, 'mynita', 'FSEJ', 'L2'], [$recu['matricule'], (int) $recu['montant'], $recu['moyen_paiement'], $recu['faculte'], $recu['niveau']]);
        $this->assertStringStartsWith('REF-', $recu['reference_paiement']);
        $this->assertStringStartsWith('INS-', $recu['recu_numero']);
        $this->assertSame(1, DB::table('inscriptions')->where(['etudiant_id' => $this->ancien, 'annee_id' => $this->annee])->count());

        // Déjà payé : redémarrer redonne l'inscription complète, plus de paiement
        $this->assertSame('complete', $this->api($j, 'inscription_academique_demarrer')->json('data.en_ligne.statut'));
        $this->api($j, 'inscription_academique_payer', ['id' => $id, 'moyen' => 'mynita', 'telephone' => '90000000'])->assertStatus(400);
    }

    public function test_nouveau_bachelier_echec_et_cloisonnement(): void
    {
        $this->komipay('Echec');
        $j = $this->jeton('D123456');
        $id = $this->api($j, 'inscription_academique_demarrer')->assertOk()->json('data.en_ligne.id');
        $this->assertSame('L1', DB::table('niveaux')->where('id', DB::table('inscriptions_en_ligne')->where('id', $id)->value('niveau_id'))->value('code'));
        $this->api($j, 'inscription_academique_payer', ['id' => $id, 'moyen' => 'amana', 'telephone' => '91000000'])->assertOk();
        $this->api($j, 'inscription_academique_statut', ['id' => $id], 'get')->assertOk()->assertJson(['data' => ['statut' => 'echec', 'inscrit' => false]]);
        $this->assertSame(0, DB::table('inscriptions')->count());

        // L'inscription d'un autre étudiant n'est ni consultable ni payable
        $autre = $this->jeton('00007');
        $this->api($autre, 'inscription_academique_statut', ['id' => $id], 'get')->assertNotFound();
        $this->api($autre, 'inscription_academique_payer', ['id' => $id, 'moyen' => 'amana', 'telephone' => '91000000'])->assertNotFound();
        // Sans jeton : refusé
        $this->postJson($this->url('api_mobile.php?action=inscription_academique_demarrer'))->assertUnauthorized();
    }

    public function test_refus_sans_decision_de_passage_ou_inscriptions_fermees(): void
    {
        $j = $this->jeton('00007');
        DB::table('passages_niveau')->delete();
        $this->api($j, 'inscription_academique_demarrer')->assertStatus(400)->assertJsonPath('message', fn ($m) => str_contains($m, 'décision de passage'));
        DB::table('portail_candidats_config')->insert(['cle' => 'inscription_ouverte', 'valeur' => '0']);
        $this->api($j, 'inscription_academique', [], 'get')->assertJsonPath('data.ouverte', false);
        $this->api($j, 'inscription_academique_demarrer')->assertStatus(400)->assertJsonPath('message', fn ($m) => str_contains($m, 'fermées'));
        $this->assertSame(0, DB::table('inscriptions_en_ligne')->count());
    }
}
