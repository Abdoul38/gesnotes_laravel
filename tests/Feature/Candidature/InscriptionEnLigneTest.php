<?php

namespace Tests\Feature\Candidature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Inscription académique en ligne : identification, paiement KomiPay (simulé), finalisation, reçu, accès. */
class InscriptionEnLigneTest extends TestCase
{
    private int $annee;

    private int $l2;

    private int $filiere;

    private int $ancien;

    private int $nouveau;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        Cache::flush();
        config(['komipay.login' => 'test', 'komipay.api_key' => 'cle-test', 'komipay.moyens' => ['mynita', 'amana']]);
        $precedente = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 0]);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $l1 = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $this->l2 = DB::table('niveaux')->insertGetId(['code' => 'L2', 'libelle' => 'Licence 2']);
        DB::table('tarifs_niveaux')->insert(['niveau_id' => $this->l2, 'montant' => 15000]);
        $this->filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'FSEJ', 'actif' => 1]);
        $this->ancien = DB::table('etudiants')->insertGetId(['numero' => '00007', 'nom' => 'ALPHA', 'prenoms' => 'Awa', 'filiere_id' => $this->filiere]);
        $this->nouveau = DB::table('etudiants')->insertGetId(['numero' => null, 'numero_dossier' => 'D123456', 'nom' => 'BETA', 'prenoms' => 'Zara', 'filiere_id' => $this->filiere]);
        DB::table('passages_niveau')->insert(['etudiant_id' => $this->ancien, 'filiere_id' => $this->filiere, 'niveau_actuel_id' => $l1, 'niveau_suivant_id' => $this->l2,
            'annee_depart_id' => $precedente, 'annee_arrivee_id' => $this->annee, 'decision' => 'passage', 'date_decision' => '2026-07-01']);
    }

    private function komipay(string $statut = 'Success'): void
    {
        Http::fake([
            '*generateToken' => Http::response(['statut' => true, 'token' => 'jeton']),
            '*b2c_standard' => Http::response(['statut' => true, 'reference_transaction' => 'REF-'.uniqid(), 'code_achat' => 'C-4242', 'message' => 'OK']),
            '*check-transaction-status' => Http::response(['statut' => true, 'data' => ['transactionStatus' => $statut], 'message' => '']),
        ]);
    }

    private function identifier(string $identifiant)
    {
        return $this->post($this->url('candidature/inscription'), ['identifiant' => $identifiant]);
    }

    private function idInscription(int $etudiant): int
    {
        return (int) DB::table('inscriptions_en_ligne')->where('etudiant_id', $etudiant)->value('id');
    }

    public function test_parcours_ancien_etudiant_mynita(): void
    {
        $this->komipay();
        $this->get($this->url('candidature/inscription'))->assertOk()->assertSee('Identification');
        $this->identifier('99999')->assertSessionHas('erreur', 'Matricule introuvable. Vérifiez votre numéro.');
        $this->identifier('00007')->assertRedirect();
        $id = $this->idInscription($this->ancien);
        $etape = fn (int $n) => $this->url("candidature/inscription/$id/etape/$n");
        $this->get($etape(2))->assertOk()->assertSee('Awa ALPHA')->assertSee('L2')->assertSee('15 000 FCFA');
        $this->get($etape(3))->assertOk()->assertSee('15 450 FCFA'); // + 3 % de frais

        $this->post($this->url("candidature/inscription/$id/paiement"), ['moyen' => 'visa_master', 'telephone' => '90000000'])->assertSessionHas('erreur', 'Moyen de paiement invalide.');
        $this->post($this->url("candidature/inscription/$id/paiement"), ['moyen' => 'mynita', 'telephone' => '90 00 00 00'])->assertRedirect($etape(4));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'b2c_standard') && $r['montant_a_payer'] === '15 450' && $r['numero_telephone_payeur'] === '+22790000000');
        $this->get($etape(4))->assertOk()->assertSee('C-4242');
        // Pas de second paiement tant que le premier est en attente
        $this->post($this->url("candidature/inscription/$id/paiement"), ['moyen' => 'amana', 'telephone' => '90000000'])->assertSessionHas('erreur');

        $this->getJson($this->url("candidature/inscription/$id/statut"))->assertJson(['ok' => true, 'statut' => 'valide']);
        $this->post($this->url("candidature/inscription/$id/confirmation"))->assertRedirect($etape(5));
        $this->post($this->url("candidature/inscription/$id/confirmation")); // seconde confirmation : sans effet
        $ins = DB::table('inscriptions_en_ligne')->find($id);
        $this->assertSame(['complete', 'valide', '00007'], [$ins->statut, $ins->statut_paiement, $ins->matricule]);
        $inscription = DB::table('inscriptions')->where('etudiant_id', $this->ancien)->get();
        $this->assertCount(1, $inscription);
        $this->assertSame(['mobile_money', $this->l2, $this->annee], [$inscription[0]->mode_paiement, (int) $inscription[0]->niveau_id, (int) $inscription[0]->annee_id]);
        $this->get($this->url("candidature/inscription/$id/recu"))->assertOk()->assertSee('REÇU D\'INSCRIPTION', false)->assertSee('data-qr="INS-', false)->assertDontSee('qrserver');

        // Une autre session ne voit ni l'inscription ni le reçu ; ressaisir le matricule ne les ouvre pas
        $this->flushSession();
        $this->get($etape(5))->assertRedirect($this->url('candidature/inscription'));
        $this->get($this->url("candidature/inscription/$id/recu"))->assertRedirect($this->url('candidature/inscription'));
        $this->identifier('00007')->assertRedirect($this->url('candidature/inscription'))->assertSessionHas('info');
    }

    public function test_nouveau_bachelier_matricule_et_garde_fous(): void
    {
        $this->komipay();
        DB::table('etudiants')->insert(['numero' => null, 'numero_dossier' => 'D777777', 'nom' => 'SANS', 'prenoms' => 'Filiere']);
        $this->identifier('D777777')->assertSessionHas('erreur', "Votre filière n'a pas encore été attribuée : contactez la scolarité avant de payer.");
        $this->assertSame(0, DB::table('inscriptions_en_ligne')->count());

        $this->identifier('d123456')->assertRedirect();
        $id = $this->idInscription($this->nouveau);
        $this->post($this->url("candidature/inscription/$id/paiement"), ['moyen' => 'amana', 'telephone' => '+227 91 00 00 00']);
        $this->post($this->url("candidature/inscription/$id/confirmation"));
        $this->assertSame('00008', DB::table('etudiants')->where('id', $this->nouveau)->value('numero'));
        $this->assertSame('L1', DB::table('niveaux')->where('id', DB::table('inscriptions_en_ligne')->where('id', $id)->value('niveau_id'))->value('code'));

        // Ancien étudiant sans décision de passage
        DB::table('passages_niveau')->delete();
        $this->identifier('00007')->assertSessionHas('erreur');
    }

    public function test_expiration_echec_et_paiement_non_configure(): void
    {
        $this->komipay('Echec');
        $this->identifier('00007');
        $id = $this->idInscription($this->ancien);
        $this->post($this->url("candidature/inscription/$id/paiement"), ['moyen' => 'mynita', 'telephone' => '90000000']);
        $this->post($this->url("candidature/inscription/$id/confirmation"))->assertSessionHas('erreur');
        $this->assertSame('echec', DB::table('inscriptions_en_ligne')->where('id', $id)->value('statut_paiement'));
        $this->assertSame(0, DB::table('inscriptions')->count());

        DB::table('inscriptions_en_ligne')->where('id', $id)->update(['statut_paiement' => 'en_attente', 'created_at' => now()->subMinutes(20)]);
        $this->post($this->url("candidature/inscription/$id/confirmation"))->assertRedirect($this->url("candidature/inscription/$id/etape/3"));
        $this->getJson($this->url("candidature/inscription/$id/statut"))->assertJson(['statut' => 'echec']);

        config(['komipay.login' => '']);
        $this->post($this->url("candidature/inscription/$id/paiement"), ['moyen' => 'mynita', 'telephone' => '90000000'])
            ->assertSessionHas('erreur', "Paiement refusé : Le paiement en ligne n'est pas configuré. Contactez la scolarité.");

        DB::table('portail_candidats_config')->insert(['cle' => 'inscription_ouverte', 'valeur' => '0']);
        $this->identifier('00007')->assertSessionHas('erreur');
    }
}
