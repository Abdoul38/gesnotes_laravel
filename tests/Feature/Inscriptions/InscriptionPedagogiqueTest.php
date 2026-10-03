<?php

namespace Tests\Feature\Inscriptions;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InscriptionPedagogiqueTest extends TestCase
{
    private Utilisateur $admin;

    private int $anneePrec;

    private int $annee;

    private int $filiere;

    private int $niveau;

    private array $sem = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->anneePrec = DB::table('annees_academiques')->insertGetId(['libelle' => '2024-2025']);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '']);
        $this->niveau = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        foreach (['S1', 'S2', 'S3', 'S4'] as $c) {
            $this->sem[$c] = DB::table('semestres')->insertGetId(['code' => $c, 'libelle' => "Semestre $c"]);
        }
    }

    /** Promotion avec une UE d'une matière (examen /20) pour S1 et son PV normal. */
    private function promotion(int $annee): array
    {
        $promo = $this->creerPromotion($annee, ['filiere_id' => $this->filiere, 'niveau_id' => $this->niveau]);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $this->sem['S1'], 'code' => 'UE1', 'libelle' => 'UE1', 'credits_total' => 4, 'ordre' => 1]);
        $mat = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Matière 1', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 4, 'ordre' => 1]);
        $pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $this->sem['S1'], 'type_session' => 'normale', 'libelle' => 'PV']);

        return [$promo, $mat, $pv];
    }

    private function inscrire(int $et, int $promo, int $annee): int
    {
        return DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $promo, 'niveau_id' => $this->niveau, 'annee_id' => $annee, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
    }

    public function test_affichage_et_suggestion(): void
    {
        [$promo] = $this->promotion($this->annee);
        $et = DB::table('etudiants')->insertGetId(['nom' => 'SALEY', 'prenoms' => 'Amina', 'numero' => '00031', 'filiere_id' => $this->filiere]);
        $this->inscrire($et, $promo, $this->annee);

        $this->connecte($this->admin)->get($this->url('inscriptions/pedagogique?matricule=00031'))->assertOk()
            ->assertSee('SALEY Amina')->assertSee('Inscription pédagogique — 2025-2026')->assertSee('✦ Suggéré', false)
            ->assertSee('Aucun semestre validé');
    }

    public function test_enregistrement_retrait_et_report_des_notes(): void
    {
        [$promoPrec, $matPrec, $pvPrec] = $this->promotion($this->anneePrec);
        [$promo, $mat, $pv] = $this->promotion($this->annee);
        $et = DB::table('etudiants')->insertGetId(['nom' => 'IDI', 'prenoms' => 'Hawa', 'filiere_id' => $this->filiere]);
        $this->inscrire($et, $promoPrec, $this->anneePrec);
        $ins = $this->inscrire($et, $promo, $this->annee);
        // Matière validée l'année précédente (15/20)
        DB::table('notes')->insert(['pv_id' => $pvPrec, 'etudiant_id' => $et, 'matiere_id' => $matPrec, 'note_examen' => 15, 'note_finale' => 15, 'observation' => 'AV']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $this->sem['S3'], 'annee_id' => $this->annee]);

        $this->connecte($this->admin)->post($this->url('inscriptions/pedagogique'), ['etudiant_id' => $et, 'inscription_id' => $ins, 'semestres' => [$this->sem['S1'], $this->sem['S2'], 999999]])
            ->assertRedirect($this->url("inscriptions/pedagogique?et_id=$et"))
            ->assertSessionHas('succes', '✅ Inscription pédagogique enregistrée — 2 semestre(s) autorisé(s). 1 note(s) reportée(s) automatiquement.');

        $actifs = DB::table('inscriptions_pedagogiques')->where(['etudiant_id' => $et, 'statut' => 'actif'])->orderBy('semestre_id')->pluck('semestre_id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame([$this->sem['S1'], $this->sem['S2']], $actifs);
        $this->assertSame('annule', DB::table('inscriptions_pedagogiques')->where(['etudiant_id' => $et, 'semestre_id' => $this->sem['S3']])->value('statut'));
        $this->assertDatabaseHas('notes', ['pv_id' => $pv, 'etudiant_id' => $et, 'matiere_id' => $mat, 'reportee' => 1, 'observation' => 'AV']);
        $this->assertDatabaseHas('resultats_semestre', ['pv_id' => $pv, 'etudiant_id' => $et, 'decision' => 'Admis']);
    }

    public function test_controles(): void
    {
        [$promo] = $this->promotion($this->annee);
        $et = DB::table('etudiants')->insertGetId(['nom' => 'A', 'prenoms' => 'B']);
        $autre = DB::table('etudiants')->insertGetId(['nom' => 'C', 'prenoms' => 'D']);
        $insAutre = $this->inscrire($autre, $promo, $this->annee);

        // Inscription d'un autre étudiant, ou aucun semestre
        $this->connecte($this->admin)->post($this->url('inscriptions/pedagogique'), ['etudiant_id' => $et, 'inscription_id' => $insAutre, 'semestres' => [$this->sem['S1']]])
            ->assertSessionHas('erreur', 'Veuillez sélectionner au moins un semestre.');
        $this->post($this->url('inscriptions/pedagogique'), ['etudiant_id' => $autre, 'inscription_id' => $insAutre, 'semestres' => []])
            ->assertSessionHas('erreur');
        $this->assertSame(0, DB::table('inscriptions_pedagogiques')->count());

        $this->donnerPermissions('enseignant', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('inscriptions/pedagogique'))->assertRedirect();
    }
}
