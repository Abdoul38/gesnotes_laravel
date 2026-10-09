<?php

namespace Tests\Feature\Rapports;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Statistiques, historique, rapports et suivi d'exécution : contenu et périmètre faculté / département. */
class RapportsSuiviTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $facA;

    private int $depA;

    private int $etA;

    private int $etB;

    private int $matA;

    private int $matB;

    private int $pvA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $suivante = DB::table('annees_academiques')->insertGetId(['libelle' => '2027-2028', 'active' => 0]);
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $this->depA = $this->insererMinimal('departements', ['code' => 'DA', 'libelle' => 'Dépt A', 'faculte_id' => $this->facA]);
        $niveau = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        foreach (['A' => [$this->facA, $this->depA], 'B' => [$facB, null]] as $x => [$fac, $dep]) {
            $fil = DB::table('filieres')->insertGetId(['code' => "F$x", 'libelle' => "Filière $x", 'departement' => '', 'faculte' => "Faculté $x", 'faculte_id' => $fac, 'departement_id' => $dep, 'actif' => 1]);
            $promo = $this->creerPromotion($this->annee, ['filiere_id' => $fil, 'niveau_id' => $niveau]);
            $et = DB::table('etudiants')->insertGetId(['numero' => $x === 'A' ? '00001' : '00002', 'nom' => $x === 'A' ? 'ALPHA' : 'BETA', 'prenoms' => 'X', 'sexe' => 'F', 'filiere_id' => $fil]);
            DB::table('inscriptions')->insert(['etudiant_id' => $et, 'promotion_id' => $promo, 'niveau_id' => $niveau, 'annee_id' => $this->annee, 'date_inscription' => '2026-10-01',
                'statut' => 'inscrit', 'montant_paye' => 10000, 'mode_paiement' => 'especes']);
            DB::table('passages_niveau')->insert(['etudiant_id' => $et, 'filiere_id' => $fil, 'niveau_actuel_id' => $niveau, 'niveau_suivant_id' => $niveau,
                'annee_depart_id' => $this->annee, 'annee_arrivee_id' => $suivante, 'decision' => 'passage', 'date_decision' => '2027-07-01']);
            $pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => "PV $x", 'statut' => 'publie']);
            DB::table('resultats_semestre')->insert(['pv_id' => $pv, 'etudiant_id' => $et, 'moyenne_generale' => 12.75, 'valide' => 1, 'decision' => 'Admis', 'mention' => 'Assez Bien']);
            $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => "UE$x", 'libelle' => 'UE', 'credits_total' => 6, 'ordre' => 1]);
            $mat = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => "M$x", 'libelle' => "Matière $x", 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20,
                'credits' => 6, 'ordre' => 1, 'volume_horaire_prevu' => 30, 'heures_faites' => 0]);
            if ($x === 'A') {
                [$this->etA, $this->matA, $this->pvA] = [$et, $mat, $pv];
            } else {
                [$this->etB, $this->matB] = [$et, $mat];
            }
        }
    }

    private function doyenA(): Utilisateur
    {
        $this->donnerPermissions('doyen', ['stats_voir', 'inscription_voir', 'rapports', 'etudiants_voir']);

        return $this->creerUtilisateur('doyen', ['faculte_id' => $this->facA]);
    }

    public function test_admis_et_redoublants_par_etudiant(): void
    {
        // ALPHA, BETA : passage (setUp) ; ALPHA a un doublon de passage (compté une fois)
        $pvA = DB::table('pv_sessions')->where('id', $this->pvA)->first();
        $niveau = DB::table('niveaux')->value('id');
        $filA = DB::table('promotions')->where('id', $pvA->promotion_id)->value('filiere_id');
        $doublon = (array) DB::table('passages_niveau')->where('etudiant_id', $this->etA)->first(['etudiant_id', 'niveau_actuel_id', 'niveau_suivant_id',
            'annee_depart_id', 'annee_arrivee_id', 'decision', 'date_decision']);
        DB::table('passages_niveau')->insert($doublon + ['filiere_id' => DB::table('filieres')->where('code', 'FB')->value('id')]);
        $inscrire = function (string $nom) use ($pvA, $niveau) {
            $id = DB::table('etudiants')->insertGetId(['numero' => 'N'.$nom, 'nom' => $nom, 'prenoms' => 'X']);
            DB::table('inscriptions')->insert(['etudiant_id' => $id, 'promotion_id' => $pvA->promotion_id, 'niveau_id' => $niveau, 'annee_id' => $this->annee,
                'date_inscription' => '2026-10-01', 'statut' => 'inscrit', 'montant_paye' => 10000, 'mode_paiement' => 'especes']);

            return $id;
        };
        $redoublant = $inscrire('REDOUBLANT');
        DB::table('passages_niveau')->insert(['etudiant_id' => $redoublant, 'filiere_id' => $filA, 'niveau_actuel_id' => $niveau, 'niveau_suivant_id' => $niveau,
            'annee_depart_id' => $this->annee, 'annee_arrivee_id' => $this->annee, 'decision' => 'redoublement', 'date_decision' => '2027-07-01']);
        $defaillant = $inscrire('DEFAILLANT');
        $ajourne = $inscrire('AJOURNE');
        $rattrape = $inscrire('RATTRAPE');
        $inscrire('SANSRESULTAT');
        DB::table('resultats_semestre')->insert([
            ['pv_id' => $this->pvA, 'etudiant_id' => $defaillant, 'moyenne_generale' => 2, 'valide' => 0, 'defaillant' => 1, 'decision' => 'Défaillant', 'mention' => 'Défaillant'],
            ['pv_id' => $this->pvA, 'etudiant_id' => $ajourne, 'moyenne_generale' => 8, 'valide' => 0, 'defaillant' => 0, 'decision' => 'Ajourné', 'mention' => 'Ajourné'],
            ['pv_id' => $this->pvA, 'etudiant_id' => $rattrape, 'moyenne_generale' => 7, 'valide' => 0, 'defaillant' => 0, 'decision' => 'Ajourné', 'mention' => 'Ajourné'],
        ]);
        $ratt = DB::table('pv_sessions')->insertGetId(['promotion_id' => $pvA->promotion_id, 'semestre_id' => $pvA->semestre_id, 'type_session' => 'rattrapage',
            'pv_normale_id' => $this->pvA, 'libelle' => 'PV A ratt', 'statut' => 'publie']);
        DB::table('resultats_semestre')->insert(['pv_id' => $ratt, 'etudiant_id' => $rattrape, 'moyenne_generale' => 14.5, 'valide' => 1, 'decision' => 'Admis', 'mention' => 'Bien']);
        DB::table('resultats_semestre')->where('etudiant_id', $this->etA)->update(['mention' => 'Assez bien']);

        $page = $this->connecte($this->admin)->get($this->url('statistiques'))->assertOk()
            ->assertViewHas('inscrits', 7)->assertViewHas('admis', 2);
        // Admis (passage ou licence validée) et redoublants (tous les autres, même sans décision) : total = inscrits
        $page->assertViewHas('graphiques', fn ($g) => $g['decisions'] === [2, 5]
            && $g['mentions']['libelles']->all() === ['Très bien', 'Bien', 'Assez bien', 'Passable']
            && $g['mentions']['valeurs']->all() === [0, 1, 2, 0]);
        // Tableau par filière et évolution : mêmes chiffres
        $page->assertViewHas('lignes', fn ($l) => $l->sum('inscrits') === 7 && $l->sum('passages') === 2 && $l->sum('redoublants') === 5);
        $page->assertViewHas('evolution', fn ($e) => $e->count() === 1 && $e[0]->inscrits === 7 && $e[0]->passages === 2 && $e[0]->redoublants === 5 && (float) $e[0]->taux === 28.6);
        $page->assertSee('class="stats-graphe"', false)->assertDontSee('height="220"', false);
    }

    public function test_statistiques_et_historique(): void
    {
        $this->connecte($this->admin)->get($this->url('statistiques'))->assertOk()->assertSee('Filière A')->assertSee('Filière B')->assertSee('100%');
        $this->get($this->url("historique?et_id={$this->etB}"))->assertOk()->assertSee('BETA X')->assertSee('12.75');
        $this->assertDatabaseHas('audit_log', ['action' => 'consulter', 'module' => 'etudiant']);

        $doyen = $this->doyenA();
        $this->connecte($doyen)->get($this->url('statistiques'))->assertOk()->assertSee('Filière A')->assertDontSee('Filière B');
        $this->get($this->url('historique?q=a'))->assertOk()->assertSee('ALPHA X')->assertDontSee('BETA X');
        $this->get($this->url("historique?et_id={$this->etB}"))->assertOk()->assertSee('introuvable dans votre périmètre')->assertDontSee('12.75');
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('historique'))->assertRedirect();
    }

    public function test_rapports_et_export(): void
    {
        $this->connecte($this->admin);
        foreach (['inscriptions', 'filieres', 'comparatif', 'paiements'] as $r) {
            $this->get($this->url("rapports?rapport=$r"))->assertOk();
        }
        $this->get($this->url('rapports?rapport=inscriptions&print=1'))->assertOk()->assertSee('Service Central de la Scolarité');

        DB::table('etudiants')->where('id', $this->etA)->update(['nom' => '=CMD()']);
        $doyen = $this->doyenA();
        $this->connecte($doyen)->get($this->url('rapports?rapport=inscriptions'))->assertOk()->assertSee('00001')->assertDontSee('00002');
        $csv = $this->get($this->url('rapports/export?rapport=inscriptions'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=CMD()", $csv);
        $this->assertStringNotContainsString('BETA', $csv);
        $this->assertStringContainsString('10 000 FCFA', $csv);
        $comparatif = $this->get($this->url('rapports/export?rapport=comparatif'))->streamedContent();
        $this->assertMatchesRegularExpression('/2026-2027;1;0;1;1;10000/', $comparatif);
    }

    public function test_suivi_execution_perimetre(): void
    {
        $this->donnerPermissions('chef_departement', ['etudiants_voir']);
        $chef = $this->creerUtilisateur('chef_departement', ['departement_id' => $this->depA, 'faculte_id' => $this->facA]);
        $this->connecte($chef)->get($this->url('suivi-execution'))->assertOk()->assertSee('Matière A')->assertDontSee('Matière B');
        $this->post($this->url('suivi-execution'), ['mat_id' => $this->matB, 'heures_faites' => 10])->assertSessionHas('erreur', 'Matière introuvable dans votre périmètre.');
        $this->post($this->url('suivi-execution'), ['mat_id' => $this->matA, 'heures_faites' => 40])->assertSessionHas('erreur');
        $this->post($this->url('suivi-execution'), ['mat_id' => $this->matA, 'heures_faites' => '12,5'])->assertSessionHas('succes');
        $this->assertSame('12.5', DB::table('matieres')->where('id', $this->matA)->value('heures_faites'));
        $this->assertSame('0.0', DB::table('matieres')->where('id', $this->matB)->value('heures_faites'));

        $doyen = $this->doyenA();
        $this->connecte($doyen)->get($this->url('suivi-execution'))->assertOk()->assertSee('Matière A')->assertDontSee('💾');
        $this->post($this->url('suivi-execution'), ['mat_id' => $this->matA, 'heures_faites' => 5])->assertSessionHas('erreur');

        $ens = DB::table('enseignants')->insertGetId(['nom' => 'PROF', 'prenoms' => 'P']);
        DB::table('affectations')->insert(['enseignant_id' => $ens, 'matiere_id' => $this->matB, 'annee_id' => $this->annee, 'type_charge' => 'cours']);
        $this->donnerPermissions('enseignant', ['etudiants_voir']);
        $prof = $this->creerUtilisateur('enseignant', ['enseignant_id' => $ens]);
        $this->connecte($prof)->get($this->url('suivi-execution'))->assertOk()->assertSee('Matière B')->assertSee('PROF P')->assertDontSee('Matière A');
        $this->post($this->url('suivi-execution'), ['mat_id' => $this->matA, 'heures_faites' => 5])->assertSessionHas('erreur');
        $this->post($this->url('suivi-execution'), ['mat_id' => $this->matB, 'heures_faites' => 6])->assertSessionHas('succes');
    }
}
