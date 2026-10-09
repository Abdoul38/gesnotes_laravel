<?php

namespace Tests\Feature\Notes;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/**
 * Réclamations : liste, périmètre, traitement, export.
 * Maquette : UE1 = M1 examen/40 + CC/20, M2 examen/20.
 */
class ReclamationsTest extends TestCase
{
    private Utilisateur $admin;

    private int $promo;

    private int $sem;

    private int $m1;

    private int $m2;

    private int $pv;

    private int $et;

    private int $facB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte_id' => $this->facB]);
        $this->promo = $this->creerPromotion($annee, ['filiere_id' => $filiere]);
        $this->sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $this->m1 = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 3, 'ordre' => 1]);
        $this->m2 = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M2', 'libelle' => 'Statistiques', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 3, 'ordre' => 2]);
        $this->pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'normale', 'libelle' => 'PV S1 Éco']);
        $this->et = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Awa', 'numero' => '00001']);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $this->et, 'promotion_id' => $this->promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $this->et, 'inscription_id' => $ins, 'semestre_id' => $this->sem, 'annee_id' => $annee]);
        DB::table('notes')->insert([
            ['pv_id' => $this->pv, 'etudiant_id' => $this->et, 'matiere_id' => $this->m1, 'note_examen' => 10, 'note_cc' => 8, 'note_finale' => 18],
            ['pv_id' => $this->pv, 'etudiant_id' => $this->et, 'matiere_id' => $this->m2, 'note_examen' => 14, 'note_cc' => null, 'note_finale' => 14],
        ]);
    }

    private function reclamation(int $matiere, array $champs = []): int
    {
        return DB::table('reclamations')->insertGetId($champs + ['etudiant_id' => $this->et, 'pv_id' => $this->pv, 'matiere_id' => $matiere,
            'motif' => 'Copie mal corrigée', 'note_originale' => 6, 'source' => 'physique']);
    }

    public function test_liste_filtres_et_perimetre(): void
    {
        $this->reclamation($this->m1);
        $this->reclamation($this->m2, ['statut' => 'rejetee']);
        $this->connecte($this->admin)->get($this->url('reclamations'))->assertOk()
            ->assertSee('2 réclamation(s)')->assertSee('Microéconomie')->assertSee('Statistiques')->assertSee('Export Excel par matière');
        $this->get($this->url('reclamations?statut=rejetee'))->assertOk()->assertSee('1 réclamation(s)')->assertDontSee('Microéconomie')
            ->assertSee('Statistiques');
        $this->get($this->url('reclamations?statut=nimporte'))->assertOk()->assertSee('2 réclamation(s)');

        // Agent d'une autre faculté : rien
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $this->donnerPermissions('sc_entite', ['notes_saisir']);
        $this->connecte($this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]))->get($this->url('reclamations'))->assertOk()->assertSee('0 réclamation(s)');

        // Enseignant : seulement ses matières, sans export
        $ens = $this->insererMinimal('enseignants', ['nom' => 'PROF', 'prenoms' => 'X']);
        DB::table('affectations')->insert(['enseignant_id' => $ens, 'matiere_id' => $this->m2, 'annee_id' => DB::table('annees_academiques')->value('id')]);
        $this->donnerPermissions('enseignant', ['notes_saisir']);
        $this->connecte($this->creerUtilisateur('enseignant', ['enseignant_id' => $ens]))->get($this->url('reclamations'))->assertOk()
            ->assertSee('1 réclamation(s)')->assertSee('Statistiques')->assertDontSee('Export Excel');
    }

    public function test_reclamation_fondee_corrige_la_note_et_recalcule(): void
    {
        $id = $this->reclamation($this->m1);
        $this->connecte($this->admin)->get($this->url("reclamations/$id"))->assertOk()->assertSee('Réclamation #'.$id)->assertSee('Copie mal corrigée');

        // Seule la note d'examen est corrigée : le CC existant (8) est conservé
        $this->post($this->url("reclamations/$id"), ['action' => 'fondee', 'note_examen' => '30', 'commentaire' => 'Erreur de report'])
            ->assertRedirect($this->url('reclamations'));
        $note = DB::table('notes')->where(['etudiant_id' => $this->et, 'matiere_id' => $this->m1])->first();
        $this->assertEquals([30, 8, 38], [(float) $note->note_examen, (float) $note->note_cc, (float) $note->note_finale]);
        $r = DB::table('reclamations')->find($id);
        $this->assertSame(['fondee', 38.0, 'Erreur de report', $this->admin->id], [$r->statut, (float) $r->note_corrigee, $r->commentaire, (int) $r->traitee_par]);
        $this->assertNotNull(DB::table('resultats_semestre')->where(['pv_id' => $this->pv, 'etudiant_id' => $this->et])->value('moyenne_generale'));
        $this->assertDatabaseHas('audit_log', ['action' => 'modifier', 'module' => 'reclamation']);

        // Déjà traitée : refus
        $this->post($this->url("reclamations/$id"), ['action' => 'rejetee'])->assertSessionHas('erreur', 'Cette réclamation a déjà été traitée.');
        $this->assertSame('fondee', DB::table('reclamations')->where('id', $id)->value('statut'));
        $this->get($this->url("reclamations/$id"))->assertOk()->assertSee('Générer PV de réclamation');
    }

    public function test_pv_verrouille_pas_de_correction(): void
    {
        DB::table('pv_sessions')->where('id', $this->pv)->update(['verrouille' => 1]);
        $id = $this->reclamation($this->m1);
        $this->connecte($this->admin)->get($this->url("reclamations/$id"))->assertOk()
            ->assertSee('est verrouillé : pour corriger la note')->assertDontSee('id="btn-fondee"', false)->assertSee('id="btn-non-fondee"', false);

        // Fondée : refusée, note et réclamation inchangées
        $this->post($this->url("reclamations/$id"), ['action' => 'fondee', 'note_examen' => '30'])
            ->assertSessionHas('erreur', 'Le PV est verrouillé : faites-le déverrouiller avant de corriger la note (« non fondée » et « rejetée » restent possibles).');
        $this->assertEquals(10, DB::table('notes')->where(['etudiant_id' => $this->et, 'matiere_id' => $this->m1])->value('note_examen'));
        $this->assertSame('en_attente', DB::table('reclamations')->where('id', $id)->value('statut'));

        // Après déverrouillage : corrigée
        DB::table('pv_sessions')->where('id', $this->pv)->update(['verrouille' => 0]);
        $this->post($this->url("reclamations/$id"), ['action' => 'fondee', 'note_examen' => '30'])->assertRedirect($this->url('reclamations'));
        $this->assertEquals(30, DB::table('notes')->where(['etudiant_id' => $this->et, 'matiere_id' => $this->m1])->value('note_examen'));

        // Non fondée : possible même verrouillé
        DB::table('pv_sessions')->where('id', $this->pv)->update(['verrouille' => 1]);
        $id2 = $this->reclamation($this->m2);
        $this->post($this->url("reclamations/$id2"), ['action' => 'non_fondee'])->assertRedirect($this->url('reclamations'));
        $this->assertSame('non_fondee', DB::table('reclamations')->where('id', $id2)->value('statut'));
    }

    public function test_validation_du_traitement(): void
    {
        $id = $this->reclamation($this->m2);
        $this->connecte($this->admin);
        $this->post($this->url("reclamations/$id"), ['action' => 'fondee'])->assertSessionHas('erreur', 'Veuillez saisir au moins une note corrigée si la réclamation est fondée.');
        $this->post($this->url("reclamations/$id"), ['action' => 'fondee', 'note_examen' => '25'])->assertSessionHas('erreur', "Note d'examen invalide (0 à 20).");
        $this->post($this->url("reclamations/$id"), ['action' => 'supprimer'])->assertSessionHas('erreur', 'Action invalide.');
        $this->assertSame('en_attente', DB::table('reclamations')->where('id', $id)->value('statut'));

        // Note finale directe
        $this->post($this->url("reclamations/$id"), ['action' => 'fondee', 'note_finale_override' => '12.5'])->assertRedirect($this->url('reclamations'));
        $this->assertEquals(12.5, DB::table('notes')->where(['etudiant_id' => $this->et, 'matiere_id' => $this->m2])->value('note_finale'));

        $id2 = $this->reclamation($this->m1, ['etudiant_id' => DB::table('etudiants')->insertGetId(['nom' => 'BETA', 'prenoms' => 'B', 'numero' => '00002'])]);
        $this->post($this->url("reclamations/$id2"), ['action' => 'non_fondee'])->assertRedirect($this->url('reclamations'));
        $this->assertSame('non_fondee', DB::table('reclamations')->where('id', $id2)->value('statut'));
    }

    public function test_perimetre_du_traitement(): void
    {
        $id = $this->reclamation($this->m1);
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $this->donnerPermissions('doyen', ['notes_saisir']);
        $this->connecte($this->creerUtilisateur('doyen', ['faculte_id' => $facA]))->get($this->url("reclamations/$id"))->assertRedirect($this->url('reclamations'));
        $this->post($this->url("reclamations/$id"), ['action' => 'rejetee'])->assertRedirect($this->url('reclamations'));
        $this->assertSame('en_attente', DB::table('reclamations')->where('id', $id)->value('statut'));

        $this->connecte($this->creerUtilisateur('doyen', ['faculte_id' => $this->facB, 'email' => 'doyen2@test.local']))->get($this->url("reclamations/$id"))->assertOk();
        $this->donnerPermissions('enseignant', ['notes_saisir']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url("reclamations/$id"))->assertRedirect($this->url('reclamations'));
    }

    public function test_export_par_matiere(): void
    {
        $this->reclamation($this->m1, ['statut' => 'fondee', 'note_corrigee' => 12]);
        $this->reclamation($this->m2);
        $reponse = $this->connecte($this->admin)->get($this->url('reclamations/export'));
        $reponse->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $zip = new ZipArchive;
        $zip->open($reponse->baseResponse->getFile()->getPathname());
        $classeur = $zip->getFromName('xl/workbook.xml');
        $chaines = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        $this->assertStringContainsString('name="Recapitulatif"', $classeur);
        $this->assertStringContainsString('name="M1 - Microéconomie"', $classeur);
        $this->assertStringContainsString('name="M2 - Statistiques"', $classeur);
        $this->assertStringContainsString('TOTAL GENERAL : 2 reclamation(s)', $chaines);
        $this->assertStringContainsString('ALPHA Awa', $chaines);

        // Agent d'une autre faculté : rien à exporter ; enseignant : refusé
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $this->donnerPermissions('sc_entite', ['notes_saisir']);
        $this->connecte($this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]))->get($this->url('reclamations/export'))
            ->assertRedirect($this->url('reclamations'))->assertSessionHas('erreur', 'Aucune réclamation à exporter avec ces filtres.');
        $this->donnerPermissions('enseignant', ['notes_saisir']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('reclamations/export'))->assertRedirect($this->url('accueil'));
    }
}
