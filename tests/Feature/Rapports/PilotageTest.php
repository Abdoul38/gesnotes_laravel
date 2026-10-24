<?php

namespace Tests\Feature\Rapports;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tableau de pilotage : réussite, notes non finalisées, retards par enseignant, présence ; périmètres. */
class PilotageTest extends TestCase
{
    private int $facA;

    private int $facB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->donnerPermissions('doyen', ['stats_voir']);
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $this->facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte_id' => $this->facA, 'actif' => 1]);
        $promo = $this->creerPromotion($annee, ['filiere_id' => $filiere, 'niveau_id' => DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1'])]);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $micro = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 3, 'ordre' => 1]);
        $stats = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M2', 'libelle' => 'Statistiques', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 3, 'ordre' => 2]);
        $pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => 'PV S1']);
        $ets = [];
        foreach ([1, 1, 0, null] as $i => $valide) {
            $ets[] = $et = DB::table('etudiants')->insertGetId(['numero' => '0000'.$i, 'nom' => 'ETU'.$i, 'prenoms' => 'X']);
            if ($valide !== null) {
                DB::table('resultats_semestre')->insert(['pv_id' => $pv, 'etudiant_id' => $et, 'moyenne_generale' => $valide ? 12 : 8, 'valide' => $valide]);
            }
        }
        $prof = DB::table('enseignants')->insertGetId(['nom' => 'RETARD', 'prenoms' => 'Paul']);
        DB::table('affectations')->insert(['enseignant_id' => $prof, 'matiere_id' => $micro, 'annee_id' => $annee, 'type_charge' => 'cours_td']);
        // Statistiques finalisée ; Microéconomie non (examen passé il y a 10 jours, 1 note en attente)
        DB::table('notes_finalisations')->insert(['pv_id' => $pv, 'matiere_id' => $stats, 'composante' => 'exam']);
        DB::table('notes_enseignants')->insert(['pv_id' => $pv, 'etudiant_id' => $et, 'matiere_id' => $micro, 'composante' => 'exam', 'note' => 20]);
        DB::table('planning_examens')->insert(['promotion_id' => $promo, 'semestre_id' => $sem, 'session' => 'normale', 'matiere_id' => $micro,
            'date_examen' => now()->subDays(10)->toDateString(), 'heure_debut' => '08:00', 'heure_fin' => '10:00']);
        $seance = DB::table('seances')->insertGetId(['matiere_id' => $micro, 'promotion_id' => $promo, 'date_seance' => '2026-11-03', 'type_seance' => 'CM']);
        DB::table('presences')->insert(array_map(fn ($e, $statut) => ['seance_id' => $seance, 'etudiant_id' => $e, 'statut' => $statut],
            $ets, ['present', 'retard', 'present', 'absent']));
    }

    public function test_indicateurs_et_perimetres(): void
    {
        $page = $this->connecte($this->creerUtilisateur('admin'))->get($this->url('pilotage'))->assertOk();
        $page->assertSee('66,7 %')                          // 2 validés sur 3
            ->assertSee('Microéconomie')->assertDontSee('Statistiques</td>', false)
            ->assertSee('RETARD Paul')->assertSee('10 jour(s)')
            ->assertSee('Examen, TD')                       // composantes à finaliser
            ->assertSee('75,0 %');                          // présence : 3 sur 4 (présent ou en retard)

        // Doyen d'une autre faculté : rien de la faculté A
        $this->connecte($this->creerUtilisateur('doyen', ['faculte_id' => $this->facB, 'email' => 'doyenb@test.local']))->get($this->url('pilotage'))->assertOk()
            ->assertSee('Faculté B')->assertDontSee('Microéconomie')->assertDontSee('RETARD Paul');
        // Doyen de la faculté A : ses données
        $this->connecte($this->creerUtilisateur('doyen', ['faculte_id' => $this->facA, 'email' => 'doyena@test.local']))->get($this->url('pilotage'))->assertOk()
            ->assertSee('RETARD Paul');
        // Recteur : depuis son espace
        $recteur = $this->creerUtilisateur('recteur', ['email' => 'recteur@test.local']);
        $this->connecte($recteur)->get($this->url('rectorat/pilotage'))->assertOk()->assertSee('RETARD Paul');
        $this->get($this->url('pilotage'))->assertRedirect();
        // Enseignant : pas d'accès
        $this->connecte($this->creerUtilisateur('enseignant', ['email' => 'prof@test.local']))->get($this->url('pilotage'))->assertRedirect();
    }
}
