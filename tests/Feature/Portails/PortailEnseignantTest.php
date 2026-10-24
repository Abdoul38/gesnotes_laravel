<?php

namespace Tests\Feature\Portails;

use App\Models\Utilisateur;
use App\Services\NotesEnseignants;
use App\Services\Pv;
use App\Services\SaisieNotes;
use App\Support\Tableur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Portail enseignant : affectations, saisie par composante, finalisation, périmètres, rattrapage. */
class PortailEnseignantTest extends TestCase
{
    private int $annee;

    private int $promo;

    private int $sem;

    private int $facA;

    private int $depA;

    private int $m1;

    private int $m2;

    private int $pv;

    private Utilisateur $cours;

    private Utilisateur $td;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->donnerPermissions('enseignant', ['notes_saisir']);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $this->depA = $this->insererMinimal('departements', ['code' => 'DA', 'libelle' => 'Département A', 'faculte_id' => $this->facA]);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'FA1', 'libelle' => 'Économie', 'departement' => '', 'faculte_id' => $this->facA, 'departement_id' => $this->depA]);
        $this->promo = $this->creerPromotion($this->annee, ['filiere_id' => $filiere]);
        $this->sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $this->m1 = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 3, 'ordre' => 1]);
        $this->m2 = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M2', 'libelle' => 'Statistiques', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 3, 'ordre' => 2]);
        $this->pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'normale', 'libelle' => 'PV S1']);

        $e1 = DB::table('enseignants')->insertGetId(['nom' => 'COURS', 'prenoms' => 'Prof', 'grade' => 'Maître-assistant']);
        $e2 = DB::table('enseignants')->insertGetId(['nom' => 'TRAVAUX', 'prenoms' => 'Dirigés']);
        DB::table('affectations')->insert([
            ['enseignant_id' => $e1, 'matiere_id' => $this->m1, 'annee_id' => $this->annee, 'type_charge' => 'cours'],
            ['enseignant_id' => $e2, 'matiere_id' => $this->m1, 'annee_id' => $this->annee, 'type_charge' => 'td'],
        ]);
        $this->cours = $this->creerUtilisateur('enseignant', ['enseignant_id' => $e1, 'email' => 'cours@test.local']);
        $this->td = $this->creerUtilisateur('enseignant', ['enseignant_id' => $e2, 'email' => 'td@test.local']);
    }

    private function etudiant(string $numero, string $nom): int
    {
        $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'X', 'numero' => $numero]);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $this->promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $this->sem, 'annee_id' => $this->annee]);

        return $et;
    }

    private function saisir(Utilisateur $u, string $tc, array $notes, ?int $mat = null, ?int $pv = null)
    {
        return $this->connecte($u)->post($this->url('portail/enseignant/notes'), ['annee_id' => $this->annee, 'pv_id' => $pv ?? $this->pv,
            'mat_id' => $mat ?? $this->m1, 'type_charge' => $tc, 'notes' => $notes]);
    }

    private function note(int $et, int $mat, ?int $pv = null): object
    {
        return DB::table('notes')->where(['pv_id' => $pv ?? $this->pv, 'etudiant_id' => $et, 'matiere_id' => $mat])->first();
    }

    public function test_saisie_cours_puis_td_et_finalisation(): void
    {
        $a = $this->etudiant('00001', 'ALPHA');
        $b = $this->etudiant('00002', 'BRAVO');
        $intrus = DB::table('etudiants')->insertGetId(['nom' => 'INTRUS', 'prenoms' => 'Z', 'numero' => '00099']);

        $this->connecte($this->cours)->get($this->url('portail/enseignant'))->assertOk()->assertSee('COURS Prof')->assertSee('Microéconomie');
        $this->get($this->url("portail/enseignant?pv_id={$this->pv}&mat_id={$this->m1}"))->assertOk()->assertSee('ALPHA X')->assertSee('Examen (/40)')->assertDontSee('TD (/20)')
            // Même précision que la saisie du PV (avant : pas de 0,25, une note de 12,10 était refusée par le navigateur)
            ->assertSee('step="0.01" data-decimales="2"', false)->assertDontSee('step="0.25"', false);
        // Matière non affectée : rien n'est affiché
        $this->get($this->url("portail/enseignant?pv_id={$this->pv}&mat_id={$this->m2}"))->assertOk()->assertDontSee('ALPHA X');

        // Hors bornes : rien n'est enregistré ; TD refusé à l'enseignant de cours
        $this->saisir($this->cours, 'exam', [$a => '30', $b => '50'])->assertSessionHas('erreur');
        $this->assertSame(0, DB::table('notes')->count());
        $this->saisir($this->cours, 'cc', [$a => '10'])->assertSessionHas('erreur', "Vous n'êtes pas affecté pour saisir ce type de note sur cette matière.");

        // Enregistrées sans être finalisées : rien dans le PV global, ni résultat
        $this->saisir($this->cours, 'exam', [$a => '30,5', $b => '', $intrus => '12'])
            ->assertSessionHas('succes', "Notes enregistrées. Elles n'apparaîtront dans le PV qu'après leur finalisation.");
        $this->assertSame(0, DB::table('notes')->count());
        $this->assertSame(['30.50'], DB::table('notes_enseignants')->pluck('note')->all());
        $this->assertDatabaseMissing('notes_enseignants', ['etudiant_id' => $intrus]);
        // … mais l'enseignant les retrouve dans son portail
        $this->get($this->url("portail/enseignant?annee_id={$this->annee}&pv_id={$this->pv}&mat_id={$this->m1}"))->assertOk()
            ->assertSee('value="30.5"', false)->assertSee('1 note(s) en attente')->assertSee('1 note(s) à finaliser');
        $this->saisir($this->td, 'cc', [$a => '15'])->assertSessionHas('succes');
        $this->assertSame(0, DB::table('notes')->count());
        $this->assertDatabaseMissing('resultats_semestre', ['pv_id' => $this->pv]);

        // L'enseignant de cours finalise : son examen passe dans le PV (le TD, pas encore finalisé, n'y est pas)
        $finaliser = fn (Utilisateur $u, ?int $pv = null) => $this->connecte($u)->post($this->url('portail/enseignant/finalisation'),
            ['annee_id' => $this->annee, 'pv_id' => $pv ?? $this->pv, 'mat_id' => $this->m1]);
        $finaliser($this->cours)->assertSessionHas('succes');
        $this->assertSame(['30.50', null, null], [$this->note($a, $this->m1)->note_examen, $this->note($a, $this->m1)->note_cc, $this->note($a, $this->m1)->note_finale]);
        $this->assertDatabaseMissing('notes', ['etudiant_id' => $b]);
        $this->assertSame(0, (int) DB::table('matieres')->where('id', $this->m1)->value('finalisee'));
        $this->assertDatabaseHas('resultats_semestre', ['pv_id' => $this->pv, 'etudiant_id' => $a]);

        // Examen finalisé : plus de saisie, pas de réouverture par l'enseignant
        $this->saisir($this->cours, 'exam', [$a => '40'])->assertSessionHas('erreur', 'Ces notes sont finalisées : elles ne sont plus modifiables.');
        $finaliser($this->cours)->assertSessionHas('erreur');
        $this->get($this->url("portail/enseignant?annee_id={$this->annee}&pv_id={$this->pv}&mat_id={$this->m1}"))->assertSee('Notes finalisées')->assertDontSee('name="notes[', false);

        // Le TD finalise à son tour : note complète dans le PV, matière finalisée
        $this->saisir($this->td, 'cc', [$b => '10'])->assertSessionHas('succes');
        $finaliser($this->td)->assertSessionHas('succes');
        $this->assertSame(['30.50', '15.00', '45.50'], [$this->note($a, $this->m1)->note_examen, $this->note($a, $this->m1)->note_cc, $this->note($a, $this->m1)->note_finale]);
        $this->assertSame([null, '10.00'], [$this->note($b, $this->m1)->note_examen, $this->note($b, $this->m1)->note_cc]);
        $this->assertSame(1, (int) DB::table('matieres')->where('id', $this->m1)->value('finalisee'));
        $this->assertSame(0, DB::table('notes_enseignants')->count());
        $this->assertDatabaseHas('audit_log', ['module' => 'notes', 'action' => 'modifier']);
    }

    public function test_reouverture_garde_les_notes_du_pv_jusqu_a_la_nouvelle_finalisation(): void
    {
        $a = $this->etudiant('00001', 'ALPHA');
        $chefA = $this->creerUtilisateur('chef_departement', ['departement_id' => $this->depA, 'faculte_id' => $this->facA]);
        $this->donnerPermissions('chef_departement', ['notes_saisir']);
        $finaliser = fn (Utilisateur $u, string $action = 'finaliser') => $this->connecte($u)->post($this->url('portail/enseignant/finalisation'),
            ['annee_id' => $this->annee, 'pv_id' => $this->pv, 'mat_id' => $this->m1, 'action' => $action]);
        $this->saisir($this->cours, 'exam', [$a => '20'])->assertSessionHas('succes');
        $finaliser($this->cours)->assertSessionHas('succes');

        // Rouverte par le chef de département : l'enseignant corrige, le PV garde 20 jusqu'à la finalisation
        $finaliser($this->cours, 'rouvrir')->assertSessionHas('erreur');
        $finaliser($chefA, 'rouvrir')->assertSessionHas('succes');
        $this->saisir($this->cours, 'exam', [$a => '25'])->assertSessionHas('succes');
        $this->assertSame('20.00', $this->note($a, $this->m1)->note_examen);
        $finaliser($this->cours)->assertSessionHas('succes');
        $this->assertSame('25.00', $this->note($a, $this->m1)->note_examen);

        // PV verrouillé : ni saisie, ni finalisation
        DB::table('pv_sessions')->where('id', $this->pv)->update(['verrouille' => 1]);
        $finaliser($chefA)->assertSessionHas('erreur', 'Ce PV est verrouillé.');
    }

    public function test_matiere_finalisee_avant_la_finalisation_par_composante(): void
    {
        // Ancienne règle : matière finalisée, sans finalisation par composante → reste finalisée en session normale
        $a = $this->etudiant('00001', 'ALPHA');
        DB::table('matieres')->where('id', $this->m1)->update(['finalisee' => 1]);
        $this->saisir($this->cours, 'exam', [$a => '20'])->assertSessionHas('erreur', 'Ces notes sont finalisées : elles ne sont plus modifiables.');
        $this->connecte($this->cours)->get($this->url("portail/enseignant?annee_id={$this->annee}"))->assertSee('✅ Finalisée');

        // Rouverte pour le seul examen : le TD reste finalisé
        app(NotesEnseignants::class)->rouvrir(app(Pv::class)->trouver($this->pv), DB::table('matieres')->find($this->m1), ['exam']);
        $this->saisir($this->cours, 'exam', [$a => '20'])->assertSessionHas('succes');
        $this->saisir($this->td, 'cc', [$a => '10'])->assertSessionHas('erreur');
        $this->assertSame(0, (int) DB::table('matieres')->where('id', $this->m1)->value('finalisee'));
    }

    public function test_mes_examens_matieres_et_surveillances(): void
    {
        $autre = DB::table('enseignants')->insertGetId(['nom' => 'AUTRE', 'prenoms' => 'Prof']);
        DB::table('planning_examens')->insert([
            ['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'session' => 'normale', 'matiere_id' => $this->m1, 'date_examen' => '2026-01-12', 'heure_debut' => '08:00', 'heure_fin' => '10:00', 'salle' => 'Amphi A', 'surveillant_1' => $autre, 'surveillant_2' => null],
            ['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'session' => 'rattrapage', 'matiere_id' => $this->m2, 'date_examen' => '2026-01-13', 'heure_debut' => '14:00', 'heure_fin' => '16:00', 'salle' => 'Salle 3', 'surveillant_1' => $this->td->enseignant_id, 'surveillant_2' => null],
        ]);

        // Enseignant de cours : l'examen de sa matière, pas celui des Statistiques
        $this->connecte($this->cours)->get($this->url('portail/enseignant'))->assertSee('Mes examens');
        $this->get($this->url('portail/enseignant/examens'))->assertOk()->assertSee('Microéconomie')->assertSee('Amphi A')->assertSee('08:00 – 10:00')
            ->assertSee('AUTRE Prof')->assertSee('Enseignant de la matière')->assertDontSee('Statistiques');
        // Enseignant de TD : sa matière et sa surveillance
        $this->connecte($this->td)->get($this->url('portail/enseignant/examens'))->assertOk()->assertSee('Microéconomie')->assertSee('Statistiques')
            ->assertSee('Salle 3')->assertSee('Surveillant')->assertSee('Rattrapage');
        // Sans fiche enseignant
        $this->connecte($this->creerUtilisateur('enseignant', ['email' => 'sans@test.local']))->get($this->url('portail/enseignant/examens'))
            ->assertSessionHas('erreur', 'Aucun dossier enseignant lié à votre compte.');
    }

    public function test_mon_emploi_du_temps(): void
    {
        $promo2 = $this->creerPromotion($this->annee, ['filiere_id' => DB::table('filieres')->value('id'), 'niveau_id' => $this->insererMinimal('niveaux', ['code' => 'L2', 'libelle' => 'Licence 2'])]);
        $e1 = $this->cours->enseignant_id;
        DB::table('emploi_temps')->insert([
            ['promotion_id' => $this->promo, 'annee_id' => $this->annee, 'semestre_id' => $this->sem, 'jour' => 'Mardi', 'heure_debut' => '08:00', 'heure_fin' => '10:00', 'matiere_id' => $this->m1, 'enseignant_id' => $e1, 'salle' => 'Amphi A', 'type_seance' => 'cours'],
            ['promotion_id' => $promo2, 'annee_id' => $this->annee, 'semestre_id' => $this->sem, 'jour' => 'Mardi', 'heure_debut' => '09:00', 'heure_fin' => '11:00', 'matiere_id' => null, 'enseignant_id' => $e1, 'salle' => 'Salle 2', 'type_seance' => 'td'],
            ['promotion_id' => $this->promo, 'annee_id' => $this->annee, 'semestre_id' => $this->sem, 'jour' => 'Jeudi', 'heure_debut' => '14:00', 'heure_fin' => '16:00', 'matiere_id' => $this->m2, 'enseignant_id' => $this->td->enseignant_id, 'salle' => 'Salle 9', 'type_seance' => 'cours'],
        ]);

        $this->connecte($this->cours)->get($this->url('portail/enseignant'))->assertSee('Emploi du temps');
        $this->get($this->url('portail/enseignant/emploi-du-temps'))->assertOk()->assertSee('Microéconomie')->assertSee('08:00 – 10:00')->assertSee('Amphi A')
            ->assertSee('Salle 2')->assertSee('L2')->assertSee('Chevauche une autre séance')->assertSee('2 séance(s) par semaine · 4 h')
            ->assertDontSee('Salle 9');
        $this->connecte($this->creerUtilisateur('enseignant', ['email' => 'sans@test.local']))->get($this->url('portail/enseignant/emploi-du-temps'))
            ->assertSessionHas('erreur', 'Aucun dossier enseignant lié à votre compte.');
    }

    public function test_perimetres_lecture_seule_et_volume(): void
    {
        $a = $this->etudiant('00001', 'ALPHA');
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $this->donnerPermissions('doyen', ['notes_saisir']);
        $this->donnerPermissions('chef_departement', ['notes_saisir']);
        $doyenB = $this->creerUtilisateur('doyen', ['faculte_id' => $facB]);
        $chefA = $this->creerUtilisateur('chef_departement', ['departement_id' => $this->depA, 'faculte_id' => $this->facA]);

        // Doyen d'une autre faculté : ni notes, ni liste, ni modèle, ni volume
        $this->connecte($doyenB)->get($this->url("portail/enseignant?pv_id={$this->pv}&mat_id={$this->m1}"))->assertOk()->assertDontSee('ALPHA X')->assertDontSee('Microéconomie');
        $this->get($this->url("portail/enseignant/modele?annee_id={$this->annee}&pv_id={$this->pv}&mat_id={$this->m1}&tc=exam"))->assertRedirect();
        $this->post($this->url('portail/enseignant/volume'), ['mat_id' => $this->m1, 'volume_horaire_prevu' => 30])->assertSessionHas('erreur');
        $this->assertNull(DB::table('matieres')->where('id', $this->m1)->value('volume_horaire_prevu'));

        // Chef du département : consultation, volume, finalisation et réouverture ; pas de saisie
        $this->connecte($chefA)->get($this->url("portail/enseignant?pv_id={$this->pv}&mat_id={$this->m1}"))->assertOk()->assertSee('ALPHA X')->assertSee('Consultation seule');
        $this->saisir($chefA, 'exam', [$a => '10'])->assertSessionHas('erreur');
        $this->assertSame(0, DB::table('notes')->count());
        $this->post($this->url('portail/enseignant/volume'), ['mat_id' => $this->m1, 'volume_horaire_prevu' => '30'])->assertSessionHas('succes');
        $fin = ['annee_id' => $this->annee, 'pv_id' => $this->pv, 'mat_id' => $this->m1];
        $this->post($this->url('portail/enseignant/finalisation'), $fin)->assertSessionHas('succes');
        $this->assertSame(1, (int) DB::table('matieres')->where('id', $this->m1)->value('finalisee'));
        $this->post($this->url('portail/enseignant/finalisation'), $fin)->assertSessionHas('succes');
        $this->assertSame(0, (int) DB::table('matieres')->where('id', $this->m1)->value('finalisee'));

        // Heures : bornées par le volume prévu ; enseignant ne fixe pas le volume
        $this->connecte($this->cours)->post($this->url('portail/enseignant/heures'), ['mat_id' => $this->m1, 'heures_faites' => '40'])->assertSessionHas('erreur');
        $this->post($this->url('portail/enseignant/heures'), ['mat_id' => $this->m1, 'heures_faites' => '12,5'])->assertSessionHas('succes');
        $this->assertSame('12.5', DB::table('matieres')->where('id', $this->m1)->value('heures_faites'));
        $this->post($this->url('portail/enseignant/volume'), ['mat_id' => $this->m1, 'volume_horaire_prevu' => 60])->assertSessionHas('erreur');
        $this->post($this->url('portail/enseignant/heures'), ['mat_id' => $this->m2, 'heures_faites' => '5'])->assertSessionHas('erreur');

        // PV verrouillé : refus
        DB::table('pv_sessions')->where('id', $this->pv)->update(['verrouille' => 1]);
        $this->saisir($this->cours, 'exam', [$a => '10'])->assertSessionHas('erreur', 'Ce PV est verrouillé.');

        // Compte sans fiche enseignant
        $this->connecte($this->creerUtilisateur('enseignant', ['email' => 'sans@test.local']))->get($this->url('portail/enseignant'))
            ->assertSessionHas('erreur', 'Aucun dossier enseignant lié à votre compte.');
    }

    public function test_td_du_rattrapage_calcule_depuis_l_examen(): void
    {
        // Exemple de l'université : examen /30 et TD /30 ; 1ère session 5 + 17 ; rattrapage 15/30 → TD 15/30, note 30/60
        $mat = (object) ['note_max_examen' => 30, 'note_max_cc' => 30];
        $this->assertSame(15.0, SaisieNotes::tdRattrapage(15.0, $mat));
        $this->assertSame(10.0, SaisieNotes::tdRattrapage(30.0, (object) ['note_max_examen' => 60, 'note_max_cc' => 20]));
        $this->assertNull(SaisieNotes::tdRattrapage(12.0, (object) ['note_max_examen' => 20, 'note_max_cc' => 0]));
        $this->assertNull(SaisieNotes::tdRattrapage(null, $mat));

        // Pas d'onglet TD au rattrapage pour l'enseignant de TD
        $ratt = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'rattrapage',
            'libelle' => 'PV S1 rattrapage', 'pv_normale_id' => $this->pv]);
        $this->connecte($this->td)->get($this->url("portail/enseignant?annee_id={$this->annee}&pv_id=$ratt&mat_id={$this->m1}"))->assertOk()
            ->assertSee("le TD est calculé à partir de la note d'examen");
        $this->saisir($this->td, 'cc', [1 => '10'], $this->m1, $ratt)->assertSessionHas('erreur');
    }

    public function test_rattrapage_td_de_premiere_session_et_absent(): void
    {
        // M1 (Exam./40 + TD/20) non validée en 1ère session : 10 + 5 = 15 ; 12 + 4 = 16
        $a = $this->etudiant('00001', 'ALPHA');
        $b = $this->etudiant('00002', 'BRAVO');
        DB::table('notes')->insert([
            ['pv_id' => $this->pv, 'etudiant_id' => $a, 'matiere_id' => $this->m1, 'note_examen' => 10, 'note_cc' => 5, 'note_finale' => 15, 'observation' => 'NV'],
            ['pv_id' => $this->pv, 'etudiant_id' => $b, 'matiere_id' => $this->m1, 'note_examen' => 12, 'note_cc' => 4, 'note_finale' => 16, 'observation' => 'NV'],
        ]);
        $ratt = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'rattrapage',
            'libelle' => 'PV S1 rattrapage', 'pv_normale_id' => $this->pv]);

        // L'enseignant de cours saisit l'examen : 30/40 reporté sur le TD (15/20), soit 45 — le TD de 1ère session (5) n'est pas repris ;
        // BRAVO laissé vide = n'a pas composé
        $this->saisir($this->cours, 'exam', [$a => '30', $b => ''], $this->m1, $ratt)->assertSessionHas('succes');
        $this->assertSame(0, DB::table('notes')->where('pv_id', $ratt)->count());
        $this->connecte($this->cours)->post($this->url('portail/enseignant/finalisation'), ['annee_id' => $this->annee, 'pv_id' => $ratt, 'mat_id' => $this->m1])->assertSessionHas('succes');
        $this->assertSame(['30.00', '15.00', '45.00'], [$this->note($a, $this->m1, $ratt)->note_examen, $this->note($a, $this->m1, $ratt)->note_cc, $this->note($a, $this->m1, $ratt)->note_finale]);
        $this->assertSame([null, 1], [$this->note($b, $this->m1, $ratt)->note_finale, (int) $this->note($b, $this->m1, $ratt)->defaillant]);
    }

    public function test_rattrapage_modele_et_import(): void
    {
        $admin = $this->creerUtilisateur('admin');
        $a = $this->etudiant('00001', 'ALPHA');
        $b = $this->etudiant('00002', 'BRAVO');
        $c = $this->etudiant('00003', 'CHARLIE');
        $d = $this->etudiant('00004', 'DELTA');
        DB::table('notes')->insert([
            ['pv_id' => $this->pv, 'etudiant_id' => $a, 'matiere_id' => $this->m2, 'note_examen' => 15, 'note_finale' => 15, 'observation' => 'AV'],
            ['pv_id' => $this->pv, 'etudiant_id' => $b, 'matiere_id' => $this->m2, 'note_examen' => 9, 'note_finale' => 9, 'observation' => 'NV'],
            ['pv_id' => $this->pv, 'etudiant_id' => $c, 'matiere_id' => $this->m2, 'note_examen' => 5, 'note_finale' => 5, 'observation' => 'NV'],
            ['pv_id' => $this->pv, 'etudiant_id' => $d, 'matiere_id' => $this->m2, 'note_examen' => 4, 'note_finale' => 4, 'observation' => 'NV'],
        ]);
        $ratt = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'rattrapage',
            'libelle' => 'PV S1 rattrapage', 'pv_normale_id' => $this->pv]);

        // Modèle : uniquement les non validés de la matière
        $modele = $this->connecte($admin)->get($this->url("portail/enseignant/modele?annee_id={$this->annee}&pv_id=$ratt&mat_id={$this->m2}&tc=exam"));
        $modele->assertRedirect();
        $e3 = DB::table('enseignants')->insertGetId(['nom' => 'STAT', 'prenoms' => 'Prof']);
        DB::table('affectations')->insert(['enseignant_id' => $e3, 'matiere_id' => $this->m2, 'annee_id' => $this->annee, 'type_charge' => 'cours_td']);
        $modele = $this->get($this->url("portail/enseignant/modele?annee_id={$this->annee}&pv_id=$ratt&mat_id={$this->m2}&tc=exam"))->assertOk();
        $lignes = Tableur::xlsx($modele->baseResponse->getFile()->getPathname());
        $this->assertSame(['N° Etudiant', 'Nom', 'Prénoms', 'Note/20'], $lignes[2]);
        $this->assertSame(['00002', '00003', '00004'], array_map(fn ($l) => $l[0], array_slice($lignes, 2)));
        $this->get($this->url("portail/enseignant/modele?annee_id={$this->annee}&pv_id=$ratt&mat_id={$this->m2}&tc=cc"))->assertRedirect();

        // Hors bornes : rien n'est importé
        $import = fn ($csv) => $this->post($this->url('portail/enseignant/import'), ['annee_id' => $this->annee, 'pv_id' => $ratt, 'mat_id' => $this->m2,
            'type_charge' => 'exam', 'fichier_import' => $this->fichierTexte($csv)]);
        $import("N° Etudiant;Nom;Prénoms;Note/20\n00002;BRAVO;X;25\n")->assertSessionHas('erreur');
        $this->assertSame(0, DB::table('notes')->where('pv_id', $ratt)->count());

        // BRAVO garde sa meilleure note (9 > 8), CHARLIE améliore, DELTA absent, ALPHA (acquise) ignoré
        $import("N° Etudiant;Nom;Prénoms;Note/20\n00001;ALPHA;X;3\n2;BRAVO;X;8\n00003;CHARLIE;X;12,5\n")
            ->assertSessionHas('succes', '✅ Import réussi — 2 note(s) importée(s) · 1 matricule(s) introuvable(s).');
        $this->assertSame('9.00', $this->note($b, $this->m2, $ratt)->note_finale);
        $this->assertSame('12.50', $this->note($c, $this->m2, $ratt)->note_finale);
        $this->assertSame(1, (int) $this->note($d, $this->m2, $ratt)->defaillant);
        $this->assertDatabaseMissing('notes', ['pv_id' => $ratt, 'etudiant_id' => $a]);
        $this->assertDatabaseHas('resultats_semestre', ['pv_id' => $ratt, 'etudiant_id' => $d]);
    }
}
