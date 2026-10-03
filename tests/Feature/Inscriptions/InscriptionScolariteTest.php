<?php

namespace Tests\Feature\Inscriptions;

use App\Models\Utilisateur;
use App\Services\Inscriptions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InscriptionScolariteTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $anneePrec;

    private int $filiere;

    private int $l1;

    private int $l2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->anneePrec = DB::table('annees_academiques')->insertGetId(['libelle' => '2024-2025']);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'FSEJ']);
        $this->l1 = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $this->l2 = DB::table('niveaux')->insertGetId(['code' => 'L2', 'libelle' => 'Licence 2']);
    }

    private function etudiant(array $attributs = []): int
    {
        return DB::table('etudiants')->insertGetId($attributs + ['nom' => 'SALEY', 'prenoms' => 'Amina', 'filiere_id' => $this->filiere]);
    }

    private function inscrire(int $et, array $donnees = [], ?Utilisateur $u = null)
    {
        return $this->connecte($u ?? $this->admin)->post($this->url('inscriptions/scolarite'), $donnees + [
            'etudiant_id' => $et, 'niveau_id' => $this->l1, 'annee_id' => $this->annee, 'montant' => 10000, 'mode_paiement' => 'especes',
        ]);
    }

    public function test_nouveau_bachelier_recoit_son_matricule(): void
    {
        DB::table('etudiants')->insert(['nom' => 'X', 'prenoms' => 'Y', 'numero' => '00041']);
        $et = $this->etudiant(['numero' => '0']);

        $this->connecte($this->admin)->get($this->url("inscriptions/scolarite?id=$et"))->assertOk()
            ->assertSee('non encore attribué')->assertSee('Nouveau bachelier');

        $this->inscrire($et, ['mode_paiement' => 'mynita'])->assertRedirect($this->url("etudiants/dossier?id=$et"))
            ->assertSessionHas('succes', '✅ SALEY Amina inscrit. Matricule : 00042.');
        $ins = DB::table('inscriptions')->where('etudiant_id', $et)->first();
        $this->assertSame(['mobile_money', 10000.0, $this->admin->id], [$ins->mode_paiement, (float) $ins->montant_paye, (int) $ins->saisie_par]);
        $this->assertDatabaseHas('paiements', ['inscription_id' => $ins->id, 'mode_paiement' => 'mobile_money', 'annee_id' => $this->annee]);
        $this->assertDatabaseHas('promotions', ['id' => $ins->promotion_id, 'filiere_id' => $this->filiere, 'niveau_id' => $this->l1, 'annee_id' => $this->annee]);
        $this->assertDatabaseHas('audit_log', ['action' => 'creer', 'module' => 'inscription', 'cible_id' => $ins->id]);
    }

    public function test_ancien_etudiant_sans_passage_refuse(): void
    {
        $et = $this->etudiant(['numero' => '00007']);
        $this->connecte($this->admin)->get($this->url("inscriptions/scolarite?id=$et"))->assertOk()
            ->assertSee('Aucune décision de passage enregistrée')->assertSee('2025-2026')->assertSee('disabled title=', false);

        $this->inscrire($et)->assertSessionHas('erreur', "Cet étudiant n'a pas de décision de passage enregistrée. Inscription non autorisée.");
        $this->assertSame(0, DB::table('inscriptions')->count());
    }

    public function test_passage_vers_une_autre_filiere(): void
    {
        $droitPrive = DB::table('filieres')->insertGetId(['code' => 'DP', 'libelle' => 'Droit privé', 'departement' => '', 'faculte' => 'FSEJ']);
        $et = $this->etudiant(['numero' => '00007']);
        DB::table('passages_niveau')->insert(['etudiant_id' => $et, 'filiere_id' => $droitPrive, 'niveau_actuel_id' => $this->l1, 'niveau_suivant_id' => $this->l2,
            'annee_depart_id' => $this->anneePrec, 'annee_arrivee_id' => $this->annee, 'decision' => 'passage', 'date_decision' => '2025-09-01']);

        $this->connecte($this->admin)->get($this->url("inscriptions/scolarite?id=$et"))->assertOk()
            ->assertSee('Décision de passage enregistrée')->assertSee('Droit privé')->assertSee('✦ Suggéré');
        $this->inscrire($et, ['niveau_id' => $this->l2])->assertSessionHas('succes');

        $this->assertSame($droitPrive, (int) DB::table('etudiants')->where('id', $et)->value('filiere_id'));
        $promo = DB::table('inscriptions')->where('etudiant_id', $et)->value('promotion_id');
        $this->assertSame($droitPrive, (int) DB::table('promotions')->where('id', $promo)->value('filiere_id'));
    }

    public function test_doublon_et_controles(): void
    {
        $et = $this->etudiant(['numero' => '00009']);
        DB::table('passages_niveau')->insert(['etudiant_id' => $et, 'filiere_id' => $this->filiere, 'niveau_actuel_id' => $this->l1, 'niveau_suivant_id' => $this->l1,
            'annee_depart_id' => $this->anneePrec, 'annee_arrivee_id' => $this->annee, 'decision' => 'redoublement', 'date_decision' => '2025-09-01']);
        $this->inscrire($et)->assertSessionHas('succes');
        $this->inscrire($et)->assertSessionHas('erreur', 'Cet étudiant est déjà inscrit pour ce niveau et cette année.');

        $sansFiliere = $this->etudiant(['filiere_id' => null, 'nom' => 'IDI']);
        $this->inscrire($sansFiliere, ['niveau_id' => 999999])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Le niveau est obligatoire.') && str_contains($m, "n'a pas de filière"));
        $this->assertSame(1, DB::table('inscriptions')->count());
    }

    public function test_creation_reservee_admin_et_sc_centrale(): void
    {
        $this->donnerPermissions('secretaire', ['inscription_gerer']);
        $secretaire = $this->creerUtilisateur('secretaire');
        $et = $this->etudiant();

        $this->connecte($secretaire)->get($this->url("inscriptions/scolarite?id=$et"))->assertOk()
            ->assertSee('La création d\'une inscription est réservée', false);
        $this->inscrire($et, [], $secretaire)->assertSessionHas('erreur', 'Accès réservé à la scolarité centrale.');
        $this->assertSame(0, DB::table('inscriptions')->count());

        $this->donnerPermissions('enseignant', ['inscription_gerer']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('inscriptions/scolarite'))->assertRedirect();
    }

    public function test_recherche_par_matricule_ou_dossier(): void
    {
        $et = $this->etudiant(['numero' => '00031', 'numero_dossier' => 'D915839']);
        $promo = $this->creerPromotion($this->annee, ['filiere_id' => $this->filiere, 'niveau_id' => $this->l1]);
        DB::table('inscriptions')->insert(['etudiant_id' => $et, 'promotion_id' => $promo, 'niveau_id' => $this->l1, 'annee_id' => $this->annee, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01', 'montant_paye' => 10000, 'mode_paiement' => 'mobile_money']);

        $this->connecte($this->admin)->get($this->url('inscriptions/scolarite?matricule=d915839'))->assertOk()
            ->assertSee('SALEY Amina')->assertSee('1 inscription')->assertSee('En cours')->assertSee('mobile money')
            ->assertSee('recu_inscription', false);
        $this->get($this->url('inscriptions/scolarite?matricule=99999'))->assertSee('Aucun étudiant trouvé');
    }

    public function test_modification(): void
    {
        $et = $this->etudiant();
        $autre = DB::table('filieres')->insertGetId(['code' => 'GES', 'libelle' => 'Gestion', 'departement' => '']);
        $this->inscrire($et);
        $ins = DB::table('inscriptions')->first();

        $this->connecte($this->admin)->post($this->url('inscriptions/scolarite/modifier'), [
            'inscription_id' => $ins->id, 'filiere_id' => $autre, 'niveau_id' => $this->l2, 'annee_id' => $this->annee, 'montant_paye' => 5000, 'mode_paiement' => 'amana',
        ])->assertRedirect($this->url("inscriptions/scolarite?id=$et"))->assertSessionHas('succes', '✅ Inscription modifiée.');
        $ins2 = DB::table('inscriptions')->first();
        $this->assertSame([$this->l2, 5000.0, 'mobile_money'], [(int) $ins2->niveau_id, (float) $ins2->montant_paye, $ins2->mode_paiement]);
        $this->assertSame($autre, (int) DB::table('promotions')->where('id', $ins2->promotion_id)->value('filiere_id'));
        $this->assertSame($autre, (int) DB::table('etudiants')->where('id', $et)->value('filiere_id'));

        // Une ancienne inscription modifiée ne change pas la filière actuelle
        $promoPrec = $this->creerPromotion($this->anneePrec, ['filiere_id' => $autre, 'niveau_id' => $this->l1]);
        $vieille = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $promoPrec, 'niveau_id' => $this->l1, 'annee_id' => $this->anneePrec, 'statut' => 'inscrit', 'date_inscription' => '2024-10-01']);
        $this->post($this->url('inscriptions/scolarite/modifier'), ['inscription_id' => $vieille, 'filiere_id' => $this->filiere, 'niveau_id' => $this->l1, 'annee_id' => $this->anneePrec]);
        $this->assertSame($autre, (int) DB::table('etudiants')->where('id', $et)->value('filiere_id'));

        // Doublon niveau × année refusé
        $this->post($this->url('inscriptions/scolarite/modifier'), ['inscription_id' => $vieille, 'filiere_id' => $this->filiere, 'niveau_id' => $this->l2, 'annee_id' => $this->annee])
            ->assertSessionHas('erreur', 'Cet étudiant a déjà une autre inscription à ce niveau pour cette année.');
    }

    public function test_modification_limitee_a_la_faculte(): void
    {
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->where('id', $this->filiere)->update(['faculte_id' => $facB]);
        $et = $this->etudiant();
        $this->inscrire($et);
        $ins = DB::table('inscriptions')->value('id');

        $this->donnerPermissions('sc_entite', ['inscription_gerer']);
        $this->connecte($this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]))->post($this->url('inscriptions/scolarite/modifier'), [
            'inscription_id' => $ins, 'filiere_id' => $this->filiere, 'niveau_id' => $this->l2, 'annee_id' => $this->annee,
        ])->assertSessionHas('erreur', "Cette inscription ou cette filière n'appartient pas à votre faculté.");
        $this->assertSame($this->l1, (int) DB::table('inscriptions')->value('niveau_id'));
    }

    public function test_modes_de_paiement(): void
    {
        $this->assertSame('mobile_money', Inscriptions::mode('MyNITA'));
        $this->assertSame('especes', Inscriptions::mode(' Espèces '));
        $this->assertNull(Inscriptions::mode('bitcoin'));
    }
}
