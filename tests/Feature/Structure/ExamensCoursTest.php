<?php

namespace Tests\Feature\Structure;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Présence, planning des examens et emploi du temps : périmètres et contrôles. */
class ExamensCoursTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $sem;

    private int $facA;

    private array $promo = [];

    private array $mat = [];

    private array $pv = [];

    private int $etA;

    private int $salle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $this->sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        foreach (['A' => $this->facA, 'B' => $facB] as $x => $fac) {
            $fil = DB::table('filieres')->insertGetId(['code' => "F$x", 'libelle' => "Filière $x", 'departement' => '', 'faculte' => "Faculté $x", 'faculte_id' => $fac, 'actif' => 1]);
            $this->promo[$x] = $this->creerPromotion($this->annee, ['filiere_id' => $fil]);
            $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $this->promo[$x], 'semestre_id' => $this->sem, 'code' => "UE$x", 'libelle' => "UE $x", 'credits_total' => 6, 'ordre' => 1]);
            $this->mat[$x] = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => "M$x", 'libelle' => "Matière $x", 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 6, 'ordre' => 1]);
            $this->pv[$x] = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo[$x], 'semestre_id' => $this->sem, 'type_session' => 'normale', 'libelle' => "PV $x"]);
        }
        $this->etA = DB::table('etudiants')->insertGetId(['numero' => '00001', 'nom' => 'ALPHA', 'prenoms' => 'Awa']);
        DB::table('inscriptions')->insert(['etudiant_id' => $this->etA, 'promotion_id' => $this->promo['A'], 'statut' => 'inscrit', 'date_inscription' => '2026-10-01']);
        $this->salle = $this->insererMinimal('salles', ['code' => 'A1', 'nom' => 'Amphi 1', 'capacite' => 100, 'actif' => 1]);
    }

    public function test_presence_par_enseignant_affecte(): void
    {
        $ens = DB::table('enseignants')->insertGetId(['nom' => 'PROF', 'prenoms' => 'P']);
        DB::table('affectations')->insert(['enseignant_id' => $ens, 'matiere_id' => $this->mat['A'], 'annee_id' => $this->annee, 'type_charge' => 'cours']);
        $this->donnerPermissions('enseignant', ['notes_saisir']);
        $prof = $this->creerUtilisateur('enseignant', ['enseignant_id' => $ens]);
        $intrus = DB::table('etudiants')->insertGetId(['numero' => '00009', 'nom' => 'INTRUS', 'prenoms' => 'Z']);

        $this->connecte($prof)->get($this->url('presence'))->assertOk()->assertSee('Filière A')->assertDontSee('Filière B');
        $this->getJson($this->url("presence/matieres?promotion_id={$this->promo['B']}"))->assertExactJson([]);
        $this->getJson($this->url("presence/matieres?promotion_id={$this->promo['A']}"))->assertJson([['libelle' => 'Matière A']]);
        $this->post($this->url('presence/seances'), ['promotion_id' => $this->promo['B'], 'matiere_id' => $this->mat['B'], 'date_seance' => '2026-11-02', 'type_seance' => 'CM'])->assertSessionHas('erreur');
        $this->post($this->url('presence/seances'), ['promotion_id' => $this->promo['A'], 'matiere_id' => $this->mat['A'], 'date_seance' => '2026-11-02', 'type_seance' => 'XX'])->assertSessionHas('erreur');
        $this->post($this->url('presence/seances'), ['promotion_id' => $this->promo['A'], 'matiere_id' => $this->mat['A'], 'date_seance' => '2026-11-02', 'type_seance' => 'TD'])->assertRedirect();
        $seance = (int) DB::table('seances')->value('id');
        $this->get($this->url("presence?seance_id=$seance"))->assertOk()->assertSee('ALPHA Awa');
        $this->post($this->url('presence'), ['seance_id' => $seance, 'statut' => [$this->etA => 'absent', $intrus => 'present'], 'motif' => [$this->etA => 'Malade']])->assertSessionHas('succes');
        $this->assertSame([['absent', 'Malade']], DB::table('presences')->get(['statut', 'motif'])->map(fn ($p) => [$p->statut, $p->motif])->all());

        // Doyen d'une autre faculté : séance invisible et non modifiable
        $this->donnerPermissions('doyen', ['notes_saisir']);
        $doyenB = $this->creerUtilisateur('doyen', ['faculte_id' => DB::table('facultes')->where('code', 'FB')->value('id')]);
        $this->connecte($doyenB)->get($this->url("presence?seance_id=$seance"))->assertOk()->assertDontSee('ALPHA Awa');
        $this->post($this->url('presence'), ['seance_id' => $seance, 'statut' => [$this->etA => 'present']])->assertSessionHas('erreur', 'Séance introuvable.');
    }

    public function test_planning_des_examens(): void
    {
        $this->donnerPermissions('doyen', ['etudiants_voir']);
        $doyenA = $this->creerUtilisateur('doyen', ['faculte_id' => $this->facA]);
        $this->connecte($doyenA)->get($this->url("planning?pv_id={$this->pv['B']}"))->assertRedirect($this->url('planning'));
        $this->post($this->url('planning'), ['pv_id' => $this->pv['B'], 'planning' => []])->assertSessionHas('erreur');

        $ligne = fn (array $v = []) => ['pv_id' => $this->pv['A'], 'planning' => [$this->mat['A'] => $v + ['date' => '2027-01-10', 'heure_debut' => '08:00', 'heure_fin' => '10:00', 'salle_id' => $this->salle]]];
        $this->get($this->url("planning?pv_id={$this->pv['A']}"))->assertOk()->assertSee('Matière A');
        $this->post($this->url('planning'), $ligne(['heure_fin' => '07:00']))->assertSessionHas('erreur');
        $this->post($this->url('planning'), $ligne())->assertSessionHas('succes');
        $this->assertSame('Amphi 1', DB::table('examens_planning')->value('salle'));
        // Même salle, même créneau, autre PV : refusé
        $this->connecte($this->admin)->post($this->url('planning'), ['pv_id' => $this->pv['B'], 'planning' => [$this->mat['B'] => ['date' => '2027-01-10', 'heure_debut' => '09:00', 'heure_fin' => '11:00', 'salle_id' => $this->salle]]])
            ->assertSessionHas('erreur');
        $this->getJson($this->url('planning/disponibilite?date=2027-01-10&heure_debut=09:00&heure_fin=11:00'))->assertJson(['occupees' => [$this->salle]]);
        $this->get($this->url("planning?pv_id={$this->pv['A']}&imprimer=1"))->assertOk()->assertSee('Planning des examens')->assertSee('10/01/2027');
        // Date vidée : ligne retirée
        $this->post($this->url('planning'), $ligne(['date' => '']))->assertSessionHas('succes');
        $this->assertSame(0, DB::table('examens_planning')->count());
    }

    public function test_emploi_du_temps(): void
    {
        $this->donnerPermissions('enseignant', ['etudiants_voir']);
        $champs = fn (array $v = []) => $v + ['promotion_id' => $this->promo['A'], 'annee_id' => $this->annee, 'semestre_id' => $this->sem, 'jour' => 'Lundi',
            'heure_debut' => '08:00', 'heure_fin' => '10:00', 'type_seance' => 'cours', 'matiere_id' => $this->mat['A'], 'salle_id' => $this->salle, 'couleur' => '#2C5F9E'];
        $prof = $this->creerUtilisateur('enseignant', ['email' => 'prof@test.local']);
        $this->connecte($prof)->get($this->url("emploi-du-temps?promo_id={$this->promo['A']}"))->assertOk()->assertDontSee('Ajouter une séance');
        $this->post($this->url('emploi-du-temps'), $champs())->assertSessionHas('erreur');
        $this->assertSame(0, DB::table('emploi_temps')->count());

        $this->connecte($this->admin);
        $this->post($this->url('emploi-du-temps'), $champs(['couleur' => 'red;background:url(x)']))->assertSessionHas('erreur');
        $this->post($this->url('emploi-du-temps'), $champs(['matiere_id' => $this->mat['B']]))->assertSessionHas('erreur');
        $this->post($this->url('emploi-du-temps'), $champs())->assertSessionHas('succes', 'Séance ajoutée.');
        $this->post($this->url('emploi-du-temps'), $champs(['promotion_id' => $this->promo['B'], 'matiere_id' => $this->mat['B'], 'heure_debut' => '09:00', 'heure_fin' => '11:00']))
            ->assertSessionHas('erreur', 'Cette salle est déjà occupée sur ce créneau.');
        $id = (int) DB::table('emploi_temps')->value('id');
        $this->get($this->url("emploi-du-temps?promo_id={$this->promo['A']}&annee_id={$this->annee}&sem_id={$this->sem}"))->assertOk()->assertSee('Matière A')->assertSee('Amphi 1');
        $this->post($this->url('emploi-du-temps'), $champs(['id' => $id, 'jour' => 'Mardi']))->assertSessionHas('succes', 'Séance modifiée.');
        $this->assertSame('Mardi', DB::table('emploi_temps')->where('id', $id)->value('jour'));
        $this->get($this->url("emploi-du-temps?promo_id={$this->promo['A']}&annee_id={$this->annee}&sem_id={$this->sem}&imprimer=1"))->assertOk()->assertSee('Emploi du Temps');

        // Doyen d'une autre faculté : ni modification ni suppression
        $this->donnerPermissions('doyen', ['etudiants_voir']);
        $doyenB = $this->creerUtilisateur('doyen', ['faculte_id' => DB::table('facultes')->where('code', 'FB')->value('id')]);
        $this->connecte($doyenB)->post($this->url('emploi-du-temps/supprimer'), ['id' => $id])->assertSessionHas('erreur');
        $this->connecte($this->admin)->post($this->url('emploi-du-temps/supprimer'), ['id' => $id])->assertSessionHas('succes');
        $this->assertSame(0, DB::table('emploi_temps')->count());
    }
}
