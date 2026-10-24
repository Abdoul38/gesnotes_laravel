<?php

namespace Tests\Feature\Communication;

use App\Models\Utilisateur;
use App\Services\Communication\Notifications;
use App\Services\Parametres;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Alertes aux parents : absences, résultats à la publication, rappel des examens la veille. */
class AlertesParentsTest extends TestCase
{
    private Utilisateur $admin;

    private int $promo;

    private int $sem;

    private int $mat;

    private int $et;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'actif' => 1]);
        $this->promo = $this->creerPromotion($annee, ['filiere_id' => $filiere]);
        $this->sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $this->mat = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 6, 'ordre' => 1]);
        $this->et = DB::table('etudiants')->insertGetId(['numero' => '00001', 'nom' => 'ALPHA', 'prenoms' => 'Awa', 'filiere_id' => $filiere]);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $this->et, 'promotion_id' => $this->promo, 'annee_id' => $annee, 'statut' => 'inscrit', 'date_inscription' => '2026-10-01']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $this->et, 'inscription_id' => $ins, 'semestre_id' => $this->sem, 'annee_id' => $annee]);
        $parent = DB::table('parents')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Moussa', 'telephone' => '90112233', 'mot_de_passe_hash' => 'x']);
        DB::table('parents_etudiants')->insert(['parent_id' => $parent, 'etudiant_id' => $this->et, 'lien' => 'pere']);
    }

    private function sms(string $contexte)
    {
        return DB::table('sms_logs')->where('contexte', $contexte)->get();
    }

    public function test_absence_signalee_une_fois(): void
    {
        $this->connecte($this->admin)->post($this->url('presence/seances'), ['promotion_id' => $this->promo, 'matiere_id' => $this->mat,
            'date_seance' => '2026-11-03', 'type_seance' => 'TD'])->assertRedirect();
        $seance = (int) DB::table('seances')->value('id');
        $this->post($this->url('presence'), ['seance_id' => $seance, 'statut' => [$this->et => 'absent']])->assertSessionHas('succes');
        $this->assertCount(1, $this->sms('absence'));
        $this->assertSame('90112233', $this->sms('absence')[0]->numero);
        $this->assertStringContainsString('Awa ALPHA a été absent(e) au cours de Microéconomie (TD) du 03/11/2026', $this->sms('absence')[0]->message);
        // Réenregistrée : pas de second message ; présent puis absent de nouveau : nouveau message
        $this->post($this->url('presence'), ['seance_id' => $seance, 'statut' => [$this->et => 'absent']]);
        $this->assertCount(1, $this->sms('absence'));
        $this->post($this->url('presence'), ['seance_id' => $seance, 'statut' => [$this->et => 'present']]);
        $this->post($this->url('presence'), ['seance_id' => $seance, 'statut' => [$this->et => 'absent']]);
        $this->assertCount(2, $this->sms('absence'));

        // Alerte désactivée dans les paramètres : rien
        DB::table('parametres')->updateOrInsert(['cle' => 'parents_alerte_absences'], ['valeur' => '0']);
        app()->forgetInstance(Parametres::class);
        $this->post($this->url('presence'), ['seance_id' => $seance, 'statut' => [$this->et => 'present']]);
        $this->post($this->url('presence'), ['seance_id' => $seance, 'statut' => [$this->et => 'absent']]);
        $this->assertCount(2, $this->sms('absence'));
    }

    public function test_resultats_avec_moyenne_et_decision(): void
    {
        $pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'normale', 'libelle' => 'PV S1', 'statut' => 'publie']);
        DB::table('resultats_semestre')->insert(['pv_id' => $pv, 'etudiant_id' => $this->et, 'moyenne_generale' => 14, 'valide' => 1, 'decision' => 'Admis']);
        app(Notifications::class)->notifierPv($pv, false, true);
        $this->assertStringContainsString('Moyenne : 14,00/20 — semestre validé', $this->sms('pv_publie')[0]->message);
    }

    public function test_rappel_des_examens_la_veille(): void
    {
        DB::table('planning_examens')->insert(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'session' => 'normale', 'matiere_id' => $this->mat,
            'date_examen' => now()->addDay()->toDateString(), 'heure_debut' => '08:00', 'heure_fin' => '10:00', 'salle' => 'Amphi A']);
        $this->artisan('gesnotes:rappels-examens', ['--universite' => ['udh']])->expectsOutputToContain('1 rappel(s)')->assertSuccessful();
        $this->assertStringContainsString("Awa ALPHA a l'examen de Microéconomie demain", $this->sms('rappel_examen')[0]->message);
        $this->assertStringContainsString('à 08:00, salle Amphi A', $this->sms('rappel_examen')[0]->message);
        // Relancée : pas de doublon
        $this->artisan('gesnotes:rappels-examens', ['--universite' => ['udh']])->expectsOutputToContain('0 rappel(s)');
        $this->assertCount(1, $this->sms('rappel_examen'));
    }
}
