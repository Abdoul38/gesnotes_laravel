<?php

namespace Tests\Feature\Portails;

use App\Models\Utilisateur;
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

        $this->saisir($this->cours, 'exam', [$a => '30,5', $b => '', $intrus => '12'])->assertSessionHas('succes');
        $this->assertSame(['30.50', null, null], [$this->note($a, $this->m1)->note_examen, $this->note($a, $this->m1)->note_cc, $this->note($a, $this->m1)->note_finale]);
        $this->assertDatabaseMissing('notes', ['etudiant_id' => $intrus]);
        $this->assertDatabaseMissing('notes', ['etudiant_id' => $b]);

        // Le TD complète la note sans effacer l'examen ; matière pas encore finalisée (BRAVO incomplet)
        $this->saisir($this->td, 'cc', [$a => '15'])->assertSessionHas('succes');
        $this->assertSame(['30.50', '15.00', '45.50'], [$this->note($a, $this->m1)->note_examen, $this->note($a, $this->m1)->note_cc, $this->note($a, $this->m1)->note_finale]);
        $this->assertSame(0, (int) DB::table('matieres')->where('id', $this->m1)->value('finalisee'));
        $this->assertDatabaseHas('resultats_semestre', ['pv_id' => $this->pv, 'etudiant_id' => $a]);

        $this->saisir($this->cours, 'exam', [$b => '20'])->assertSessionHas('succes');
        $this->saisir($this->td, 'cc', [$b => '10'])->assertSessionHas('succes');
        $this->assertSame(1, (int) DB::table('matieres')->where('id', $this->m1)->value('finalisee'));

        // Matière finalisée : plus de saisie, pas de réouverture par l'enseignant
        $this->saisir($this->cours, 'exam', [$a => '40'])->assertSessionHas('erreur', 'Cette matière est finalisée : ses notes ne sont plus modifiables.');
        $this->post($this->url('portail/enseignant/finalisation'), ['mat_id' => $this->m1])->assertSessionHas('erreur');
        $this->assertSame('30.50', $this->note($a, $this->m1)->note_examen);
        $this->assertDatabaseHas('audit_log', ['module' => 'notes', 'action' => 'modifier']);
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
        $this->post($this->url('portail/enseignant/finalisation'), ['mat_id' => $this->m1])->assertSessionHas('succes');
        $this->post($this->url('portail/enseignant/finalisation'), ['mat_id' => $this->m1])->assertSessionHas('succes');
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
