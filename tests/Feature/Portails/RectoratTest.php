<?php

namespace Tests\Feature\Portails;

use App\Models\Utilisateur;
use App\Services\IndicateursRectorat;
use App\Support\Tableur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Tableau de bord du rectorat : indicateurs, PV consultables, parcours, exports, journal, mot de passe. */
class RectoratTest extends TestCase
{
    private Utilisateur $recteur;

    private int $annee;

    private int $etudiant;

    private int $pvPublie;

    private int $pvBrouillon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->recteur = $this->creerUtilisateur('recteur', ['prenoms' => 'Awa', 'nom' => 'RECTRICE']);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $suivante = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 0]);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'Faculté des Sciences Économiques']);
        $niveau = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $promo = $this->creerPromotion($this->annee, ['filiere_id' => $filiere, 'niveau_id' => $niveau]);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 30, 'ordre' => 1]);
        DB::table('matieres')->insert(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20,
            'credits' => 6, 'ordre' => 1, 'volume_horaire_prevu' => 40, 'heures_faites' => 30]);

        $this->etudiant = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Awa', 'numero' => '00001', 'sexe' => 'F']);
        $secret = DB::table('etudiants')->insertGetId(['nom' => 'BROUILLON', 'prenoms' => 'Zed', 'numero' => '00002', 'sexe' => 'M']);
        foreach ([$this->etudiant, $secret] as $et) {
            DB::table('inscriptions')->insert(['etudiant_id' => $et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        }
        DB::table('passages_niveau')->insert(['etudiant_id' => $this->etudiant, 'filiere_id' => $filiere, 'niveau_actuel_id' => $niveau, 'niveau_suivant_id' => $niveau,
            'annee_depart_id' => $this->annee, 'annee_arrivee_id' => $suivante, 'decision' => 'passage', 'date_decision' => '2026-07-01']);
        $this->pvPublie = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => 'PV S1', 'statut' => 'publie']);
        $this->pvBrouillon = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'rattrapage', 'libelle' => 'PV S1 R', 'statut' => 'brouillon']);
        DB::table('resultats_semestre')->insert([
            ['pv_id' => $this->pvPublie, 'etudiant_id' => $this->etudiant, 'moyenne_generale' => 13.5, 'valide' => 1, 'decision' => 'Admis', 'mention' => 'Assez bien', 'credits_obtenus' => 30],
            ['pv_id' => $this->pvBrouillon, 'etudiant_id' => $secret, 'moyenne_generale' => 4, 'valide' => 0, 'decision' => 'Ajourné', 'mention' => null, 'credits_obtenus' => 0],
        ]);
    }

    public function test_onglets_et_indicateurs(): void
    {
        $this->connecte($this->recteur);
        $this->get($this->url('rectorat'))->assertOk()->assertSee('Faculté des Sciences Économiques')->assertSee('100%')->assertSee('75%');
        foreach (['evolution', 'detail', 'programme', 'historique'] as $onglet) {
            $this->get($this->url("rectorat?onglet=$onglet"))->assertOk();
        }
        $this->get($this->url('rectorat?onglet=programme&prog_statut=en_cours'))->assertOk()->assertSee('Microéconomie')->assertSee('Non affectée');
        $this->get($this->url('rectorat?onglet=programme&prog_statut=complete'))->assertOk()->assertDontSee('Microéconomie');

        // PV : seuls les finalisés ou publiés, même en forçant l'identifiant
        $this->get($this->url('rectorat?onglet=pv'))->assertOk()->assertSee('Session normale')->assertDontSee('Rattrapage</td>', false);
        $this->get($this->url("rectorat?onglet=pv&pv_id={$this->pvPublie}"))->assertOk()->assertSee('ALPHA')->assertSee('13.50/20');
        $this->get($this->url("rectorat?onglet=pv&pv_id={$this->pvBrouillon}"))->assertOk()->assertDontSee('BROUILLON');
        $this->get($this->url("rectorat/pv/{$this->pvBrouillon}/impression"))->assertRedirect();
        $this->get($this->url("rectorat/pv/{$this->pvPublie}/impression?type=admis"))->assertOk()->assertSee('LISTE DES ÉTUDIANTS ADMIS')->assertSee('ALPHA')
            ->assertDontSee('<th>Rang</th>', false);   // pas de rang sur les PV imprimés
        $this->get($this->url("rectorat/pv/{$this->pvPublie}/impression?type=global"))->assertOk()->assertDontSee('<th>Rang</th>', false);
        $this->get($this->url('rectorat/rapport'))->assertOk()->assertSee('RAPPORT — ANNÉE 2025-2026');

        // Parcours d'un étudiant : uniquement les PV finalisés ou publiés
        $this->get($this->url('rectorat?onglet=etudiants&etu_q=AL'))->assertOk()->assertSee('Voir le parcours');
        $this->get($this->url("rectorat?onglet=etudiants&etu_id={$this->etudiant}"))->assertOk()->assertSee('Année académique 2025-2026')->assertSee('13.50')->assertSee('Passage');

        $this->assertDatabaseHas('rectorat_logs_consultation', ['user_id' => $this->recteur->id, 'action' => 'consultation_etudiant']);
        $this->assertDatabaseHas('rectorat_logs_consultation', ['action' => 'export_pdf_admis', 'cible' => "PV#{$this->pvPublie}"]);
    }

    public function test_admis_et_redoublants_meme_regle_que_les_statistiques(): void
    {
        // Doublon de passage (autre filière) pour ALPHA : compté une fois ; BROUILLON sans décision : redoublant
        $autre = DB::table('filieres')->insertGetId(['code' => 'ECO2', 'libelle' => 'Économie appliquée', 'departement' => '', 'faculte' => 'Faculté des Sciences Économiques']);
        $ligne = (array) DB::table('passages_niveau')->first(['etudiant_id', 'niveau_actuel_id', 'niveau_suivant_id', 'annee_depart_id', 'annee_arrivee_id', 'decision', 'date_decision']);
        DB::table('passages_niveau')->insert($ligne + ['filiere_id' => $autre]);

        $s = app(IndicateursRectorat::class)->pour($this->annee);
        $this->assertSame([2, 1, 1, 50.0], [$s['inscrits'], $s['passages'], $s['redoublants'], $s['taux_passage']]);
        $f = $s['facultes'][0];
        $this->assertSame([2, 1, 1, 50.0], [$f['inscrits'], $f['passages'], $f['redoublants'], $f['taux']]);
        $this->assertSame([2, 1, 1], [$f['filieres'][0]['inscrits'], $f['filieres'][0]['passages'], $f['filieres'][0]['redoublants']]);
        $e = collect($s['evolution'])->firstWhere('annee', '2025-2026');
        $this->assertSame([2, 1, 1, 50.0], [$e['inscrits'], $e['passages'], $e['redoublants'], $e['taux']]);
    }

    public function test_excel_et_historique_personnel(): void
    {
        $reponse = $this->connecte($this->recteur)->get($this->url('rectorat/excel'))->assertOk();
        $lignes = Tableur::xlsx($reponse->baseResponse->getFile()->getPathname());
        $synthese = array_values($lignes)[3];
        $this->assertSame([2, 1, 1], [(int) $synthese[2], (int) $synthese[3], (int) $synthese[4]]);
        // 2 inscrits, 1 admis : l'autre (sans décision) compte redoublant → 50 %
        $this->assertEquals(50, $synthese[8]);

        // Un autre recteur ne voit pas ces consultations ; l'administrateur les voit toutes
        $autre = $this->creerUtilisateur('recteur', ['email' => 'autre@test.local']);
        $this->connecte($autre)->get($this->url('rectorat?onglet=historique'))->assertOk()->assertDontSee('Export Excel détaillé');
        $this->connecte($this->creerUtilisateur('admin'))->get($this->url('rectorat?onglet=historique'))->assertOk()->assertSee('Export Excel détaillé')->assertSee('Awa RECTRICE');
    }

    public function test_acces_et_mot_de_passe(): void
    {
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('rectorat'))->assertRedirect();
        $this->connecte($this->recteur)->get($this->url('etudiants'))->assertRedirect($this->url('rectorat'));

        $this->post($this->url('rectorat/mot-de-passe'), ['ancien_mdp' => 'faux', 'nouveau_mdp' => 'Nouveau123', 'confirmer_mdp' => 'Nouveau123'])
            ->assertSessionHas('erreur', 'Mot de passe actuel incorrect.');
        $this->post($this->url('rectorat/mot-de-passe'), ['ancien_mdp' => 'MotDePasse1', 'nouveau_mdp' => 'abcdefgh', 'confirmer_mdp' => 'abcdefgh'])->assertSessionHas('erreur');
        $this->post($this->url('rectorat/mot-de-passe'), ['ancien_mdp' => 'MotDePasse1', 'nouveau_mdp' => 'Nouveau123', 'confirmer_mdp' => 'Autre1234'])
            ->assertSessionHas('erreur', 'La confirmation ne correspond pas au nouveau mot de passe.');
        $this->post($this->url('rectorat/mot-de-passe'), ['ancien_mdp' => 'MotDePasse1', 'nouveau_mdp' => 'Nouveau123', 'confirmer_mdp' => 'Nouveau123', 'onglet' => 'pv'])
            ->assertRedirect($this->url('rectorat?onglet=pv'))->assertSessionHas('succes');
        $this->assertTrue(Hash::check('Nouveau123', $this->recteur->fresh()->mot_de_passe));
    }
}
