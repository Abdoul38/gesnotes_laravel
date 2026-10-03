<?php

namespace Tests\Feature\Candidature;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Litiges KomiPay (paiements en ligne non confirmés) et demandes d'inscription. */
class LitigesEtDemandesTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $niveau;

    private int $filiere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        Cache::flush();
        config(['komipay.login' => 'test', 'komipay.api_key' => 'cle-test']);
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $this->niveau = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $this->filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'FSEJ', 'actif' => 1]);
    }

    private function enLigne(string $ref, array $attrs = []): int
    {
        $et = DB::table('etudiants')->insertGetId(['numero' => null, 'nom' => 'ALPHA', 'prenoms' => 'Awa', 'filiere_id' => $this->filiere]);

        return DB::table('inscriptions_en_ligne')->insertGetId($attrs + ['etudiant_id' => $et, 'numero_dossier' => 'D1', 'numero_depot' => '', 'filiere_id' => $this->filiere,
            'niveau_id' => $this->niveau, 'annee_id' => $this->annee, 'montant' => 10000, 'moyen_paiement' => 'mynita', 'reference_paiement' => $ref,
            'statut_paiement' => 'en_attente', 'statut' => 'en_cours', 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
    }

    public function test_litiges_komipay(): void
    {
        Http::fake(['*generateToken' => Http::response(['statut' => true, 'token' => 'j']),
            '*check-transaction-status' => Http::response(['statut' => true, 'data' => ['transactionStatus' => 'Success']])]);
        $verifie = $this->enLigne('REF-A');
        $manuel = $this->enLigne('REF-B');
        $rejete = $this->enLigne('REF-C');
        $recent = $this->enLigne('REF-D', ['created_at' => now(), 'updated_at' => now()]);

        $this->connecte($this->admin)->get($this->url('paiements/litiges-komipay'))->assertOk()->assertSee('REF-A')->assertSee('REF-C')->assertDontSee('REF-D')->assertSee('10 300 F');
        $this->post($this->url('paiements/litiges-komipay/verifier'), ['paiement_id' => $verifie])->assertSessionHas('succes');
        $this->post($this->url('paiements/litiges-komipay/confirmer'), ['paiement_id' => $manuel])->assertSessionHas('succes');
        $this->post($this->url('paiements/litiges-komipay/rejeter'), ['paiement_id' => $rejete])->assertSessionHas('succes');
        $this->post($this->url('paiements/litiges-komipay/rejeter'), ['paiement_id' => $verifie])->assertSessionHas('erreur');

        $this->assertSame(['complete', 'complete', 'annule'], DB::table('inscriptions_en_ligne')->whereIn('id', [$verifie, $manuel, $rejete])->orderBy('id')->pluck('statut')->all());
        $this->assertSame(2, DB::table('inscriptions')->count());
        $this->assertSame(['00001', '00002'], DB::table('etudiants')->whereNotNull('numero')->orderBy('numero')->pluck('numero')->all());
        $this->assertDatabaseHas('audit_log', ['module' => 'komipay', 'action' => 'confirmer']);
        $this->assertSame('en_cours', DB::table('inscriptions_en_ligne')->where('id', $recent)->value('statut'));

        $this->donnerPermissions('secretaire', ['paiements_voir']);
        $this->connecte($this->creerUtilisateur('secretaire'))->get($this->url('paiements/litiges-komipay'))->assertRedirect();
    }

    public function test_demandes_inscription(): void
    {
        $promo = $this->creerPromotion($this->annee, ['filiere_id' => $this->filiere, 'niveau_id' => $this->niveau]);
        $id = DB::table('demandes_inscription')->insertGetId(['token' => 't1', 'nom' => 'gamma', 'prenoms' => 'Ali', 'date_naissance' => '2006-02-03', 'filiere_souhaitee' => $this->filiere]);
        $refus = DB::table('demandes_inscription')->insertGetId(['token' => 't2', 'nom' => 'DELTA', 'prenoms' => 'Ida', 'date_naissance' => '2006-02-03']);

        $this->connecte($this->admin)->get($this->url('inscriptions/demandes'))->assertOk()->assertSee('gamma Ali');
        $this->post($this->url('inscriptions/demandes/accepter'), ['id' => $id, 'promotion_id' => $promo])->assertSessionHas('succes', 'Demande acceptée — Matricule : 00001');
        $this->post($this->url('inscriptions/demandes/accepter'), ['id' => $id, 'promotion_id' => $promo])->assertSessionHas('erreur');
        $this->assertSame(1, DB::table('etudiants')->count());
        $et = DB::table('etudiants')->first();
        $this->assertSame(['GAMMA', $this->filiere], [$et->nom, (int) $et->filiere_id]);
        $this->assertDatabaseHas('inscriptions', ['etudiant_id' => $et->id, 'promotion_id' => $promo, 'annee_id' => $this->annee]);

        $this->post($this->url('inscriptions/demandes/refuser'), ['id' => $refus, 'notes' => 'Dossier incomplet'])->assertSessionHas('succes');
        $this->get($this->url('inscriptions/demandes?statut=refusee'))->assertSee('Dossier incomplet');

        // Plus ouverte à tout compte connecté
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('inscriptions/demandes'))->assertRedirect();
    }
}
