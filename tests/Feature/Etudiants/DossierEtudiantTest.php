<?php

namespace Tests\Feature\Etudiants;

use App\Models\Utilisateur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DossierEtudiantTest extends TestCase
{
    private Utilisateur $admin;

    private int $et;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        File::deleteDirectory(base_path('storage/framework/testing/uploads'));
        $this->admin = $this->creerUtilisateur('admin');
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '']);
        $this->et = DB::table('etudiants')->insertGetId([
            'nom' => 'SALEY', 'prenoms' => 'Amina', 'numero' => '00031', 'filiere_id' => $filiere,
            'numero_dossier' => 'D123456', 'date_naissance' => '2004-05-06', 'statut_etudiant' => 'actif',
        ]);
    }

    private function url_dossier(): string
    {
        return $this->url("etudiants/dossier?id={$this->et}");
    }

    private function fichier(string $nom, string $contenu): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    public function test_affichage_et_journal_de_consultation(): void
    {
        $this->connecte($this->admin)->get($this->url_dossier())->assertOk()
            ->assertSee('SALEY Amina')->assertSee('00031')->assertSee('06/05/2004')->assertSee('✏️ Modifier', false);
        $this->assertDatabaseHas('audit_log', ['action' => 'consulter', 'module' => 'etudiant', 'cible_id' => $this->et]);
    }

    public function test_candidature_liee_par_numero_de_dossier_et_lien_memorise(): void
    {
        $candidat = $this->insererMinimal('candidats', []);
        $cand = $this->insererMinimal('candidatures', ['candidat_id' => $candidat, 'numero_dossier' => 'd123456 ', 'statut' => 'accepte', 'etudiant_id' => null, 'doc_cni' => 'cni_1_abc.pdf']);

        $this->connecte($this->admin)->get($this->url_dossier())->assertOk()
            ->assertSee('🗂️ ACCEPTE', false)->assertSee('fichiers/pieces/cni_1_abc.pdf', false);
        $this->assertSame($this->et, (int) DB::table('candidatures')->where('id', $cand)->value('etudiant_id'));
    }

    public function test_notes_resultats_et_attestation_l2(): void
    {
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026']);
        $filiere = DB::table('etudiants')->where('id', $this->et)->value('filiere_id');
        $sem = [];
        foreach (['S1', 'S2', 'S3', 'S4'] as $code) {
            $sem[$code] = DB::table('semestres')->insertGetId(['code' => $code, 'libelle' => "Semestre $code"]);
        }
        foreach (['L1' => ['S1', 'S2'], 'L2' => ['S3', 'S4']] as $niveauCode => $semestres) {
            $niveau = DB::table('niveaux')->insertGetId(['code' => $niveauCode, 'libelle' => $niveauCode]);
            $promo = $this->creerPromotion($annee, ['filiere_id' => $filiere, 'niveau_id' => $niveau]);
            $inscription = DB::table('inscriptions')->insertGetId(['etudiant_id' => $this->et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
            foreach ($semestres as $s) {
                $pv = $this->insererMinimal('pv_sessions', ['promotion_id' => $promo, 'semestre_id' => $sem[$s], 'type_session' => 'normale']);
                $this->insererMinimal('resultats_semestre', ['pv_id' => $pv, 'etudiant_id' => $this->et, 'valide' => 1, 'moyenne_generale' => 12.5, 'decision' => 'Admis', 'credits_obtenus' => 30, 'credits_total' => 30, 'mention' => 'Assez bien']);
                $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem[$s], 'code' => "UE-$s", 'libelle' => 'UE', 'credits_total' => 30, 'ordre' => 1]);
                $mat = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => "M-$s", 'libelle' => "Matière $s", 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 30, 'ordre' => 1]);
                $this->insererMinimal('notes', ['pv_id' => $pv, 'etudiant_id' => $this->et, 'matiere_id' => $mat, 'note_finale' => 25, 'observation' => 'AC', 'defaillant' => 0]);
                $this->insererMinimal('inscriptions_pedagogiques', ['etudiant_id' => $this->et, 'inscription_id' => $inscription, 'semestre_id' => $sem[$s], 'annee_id' => $annee, 'statut' => 'actif']);
            }
        }

        $page = $this->connecte($this->admin)->get($this->url_dossier())->assertOk();
        $page->assertSee('Année académique 2025-2026')->assertSee('Matière S3')->assertSee('25.00')
            ->assertSee('L1 — Économie')                       // la filière s'affiche (vide dans l'original)
            ->assertSee('AC')->assertSee('✅ Validé', false)->assertSee('(30/30 crédits)', false)
            ->assertSee('✅ Attestation Validation L2', false)->assertSee('type=validation_l2', false);
    }

    public function test_attestations_dut_et_licence_pour_l_iut(): void
    {
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026']);
        $filiere = DB::table('etudiants')->where('id', $this->et)->value('filiere_id');
        $valider = function (string $niveauCode, array $codes) use ($annee, $filiere) {
            $niveau = DB::table('niveaux')->insertGetId(['code' => $niveauCode, 'libelle' => $niveauCode]);
            $promo = $this->creerPromotion($annee, ['filiere_id' => $filiere, 'niveau_id' => $niveau]);
            DB::table('inscriptions')->insert(['etudiant_id' => $this->et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
            foreach ($codes as $code) {
                $sem = DB::table('semestres')->insertGetId(['code' => $code, 'libelle' => "Semestre $code"]);
                $pv = $this->insererMinimal('pv_sessions', ['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale']);
                $this->insererMinimal('resultats_semestre', ['pv_id' => $pv, 'etudiant_id' => $this->et, 'valide' => 1, 'moyenne_generale' => 13]);
            }
        };
        $valider('DUT1', ['S1', 'S2']);
        $this->connecte($this->admin)->get($this->url_dossier())->assertOk()->assertDontSee('Attestation DUT');

        // 4 semestres de DUT : attestation de DUT (avant : aucun bouton, seuls L1/L2/L3 comptaient)
        $valider('DUT2', ['S3', 'S4']);
        $this->get($this->url_dossier())->assertSee('🎓 Attestation DUT', false)->assertSee('type=validation_l2', false)
            ->assertDontSee('Attestation Validation L2')->assertDontSee('Attestation Licence');
        $this->get($this->url("attestations?et_id={$this->et}&type=validation_l2"))->assertOk()->assertSee('DE DIPLOME DE DUT');

        // Licence professionnelle (S5, S6 en LP) : attestation de licence ET de DUT
        $valider('LP', ['S5', 'S6']);
        $this->get($this->url_dossier())->assertSee('🎓 Attestation Licence', false)->assertSee('type=licence', false)->assertSee('🎓 Attestation DUT', false);
    }

    public function test_notes_masquees_sans_role_admin_ou_sc_entite(): void
    {
        // Règles de rôles d'origine : la secrétaire satisfait « sc_entite », pas sc_centrale
        $this->donnerPermissions('sc_centrale', ['etudiants_voir']);
        DB::table('inscriptions')->insert(['etudiant_id' => $this->et, 'promotion_id' => $this->creerPromotion(DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027'])), 'statut' => 'inscrit', 'date_inscription' => '2026-10-01']);
        $this->connecte($this->creerUtilisateur('sc_centrale'))->get($this->url_dossier())->assertOk()
            ->assertDontSee('Aucune note disponible')->assertSee('Inscriptions');
    }

    public function test_lecture_seule_sans_role_secretaire(): void
    {
        $this->donnerPermissions('sc_centrale', ['etudiants_voir']);
        $u = $this->creerUtilisateur('sc_centrale');
        $this->connecte($u)->get($this->url_dossier())->assertOk()->assertDontSee('modal-edit');
        $this->connecte($u)->post($this->url('etudiants/dossier'), ['id' => $this->et, 'nom' => 'PIRATE', 'prenoms' => 'X'])->assertForbidden();
        $this->assertDatabaseHas('etudiants', ['id' => $this->et, 'nom' => 'SALEY']);
    }

    public function test_modification_statut_toujours_valide(): void
    {
        $this->connecte($this->admin)->post($this->url('etudiants/dossier'), ['id' => $this->et, 'nom' => 'SALEY', 'prenoms' => 'Amina', 'statut_etudiant' => '', 'sexe' => 'F'])
            ->assertRedirect($this->url_dossier())->assertSessionHas('succes', 'Dossier mis à jour.');
        $this->assertDatabaseHas('etudiants', ['id' => $this->et, 'statut_etudiant' => 'actif', 'sexe' => 'F']);
    }

    public function test_document_pdf_ajoute_consultable_valide_et_supprime(): void
    {
        $this->connecte($this->admin)->post($this->url('etudiants/dossier/documents'), [
            'id' => $this->et, 'type_doc' => 'cni', 'libelle' => '', 'doc_fichier' => $this->fichier('cni.pdf', "%PDF-1.4\n%contenu"),
        ])->assertSessionHas('succes', 'Document ajouté.');
        $doc = DB::table('documents_etudiants')->where('etudiant_id', $this->et)->first();
        $this->assertSame('cni', $doc->libelle);

        $this->connecte($this->admin)->get($this->url("fichiers/documents/{$doc->id}"))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->connecte($this->admin)->post($this->url('etudiants/dossier/documents/valider'), ['id' => $this->et, 'doc_id' => $doc->id]);
        $this->assertSame(1, (int) DB::table('documents_etudiants')->where('id', $doc->id)->value('valide'));

        $this->connecte($this->admin)->post($this->url('etudiants/dossier/documents/supprimer'), ['id' => $this->et, 'doc_id' => $doc->id]);
        $this->assertDatabaseMissing('documents_etudiants', ['id' => $doc->id]);
        $this->assertFileDoesNotExist(base_path('storage/framework/testing/uploads/documents/'.$doc->fichier));
    }

    public function test_document_inaccessible_sans_connexion(): void
    {
        $doc = DB::table('documents_etudiants')->insertGetId(['etudiant_id' => $this->et, 'type_doc' => 'cni', 'libelle' => 'x', 'fichier' => 'x.pdf']);
        $this->get($this->url("fichiers/documents/$doc"))->assertRedirect($this->url('connexion'));
        $this->get($this->url('fichiers/pieces/cni_1.pdf'))->assertRedirect($this->url('connexion'));
    }

    public function test_faux_pdf_et_type_inconnu_refuses(): void
    {
        $this->connecte($this->admin)->post($this->url('etudiants/dossier/documents'), ['id' => $this->et, 'type_doc' => 'cni', 'doc_fichier' => $this->fichier('cni.pdf', '<?php echo 1;')])
            ->assertSessionHas('erreur', 'Fichier refusé. Formats acceptés : PDF, DOC, DOCX, JPG, PNG (5 Mo maximum).');
        $this->connecte($this->admin)->post($this->url('etudiants/dossier/documents'), ['id' => $this->et, 'type_doc' => 'passeport', 'doc_fichier' => $this->fichier('p.pdf', '%PDF-1.4')])
            ->assertSessionHas('erreur', 'Type de document et fichier requis.');
        $this->assertSame(0, DB::table('documents_etudiants')->count());
    }

    public function test_document_d_un_autre_etudiant_non_modifiable_par_ce_dossier(): void
    {
        $autre = DB::table('etudiants')->insertGetId(['nom' => 'AUTRE', 'prenoms' => 'X']);
        $doc = DB::table('documents_etudiants')->insertGetId(['etudiant_id' => $autre, 'type_doc' => 'cni', 'libelle' => 'x', 'fichier' => 'x.pdf']);

        $this->connecte($this->admin)->post($this->url('etudiants/dossier/documents/supprimer'), ['id' => $this->et, 'doc_id' => $doc]);
        $this->assertDatabaseHas('documents_etudiants', ['id' => $doc]);
    }
}
