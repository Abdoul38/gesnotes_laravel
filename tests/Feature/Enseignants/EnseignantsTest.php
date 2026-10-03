<?php

namespace Tests\Feature\Enseignants;

use App\Models\Utilisateur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EnseignantsTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Utilisateur $admin;

    private int $facA;

    private int $facB;

    private int $depA;

    private int $depB;

    private int $annee;

    private int $anneePrec;

    private int $matA;

    private int $matB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        File::deleteDirectory(base_path('storage/framework/testing/uploads'));
        $this->admin = $this->creerUtilisateur('admin');
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $this->facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $this->depA = $this->insererMinimal('departements', ['faculte_id' => $this->facA, 'libelle' => 'Dépt A']);
        $this->depB = $this->insererMinimal('departements', ['faculte_id' => $this->facB, 'libelle' => 'Dépt B']);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->anneePrec = DB::table('annees_academiques')->insertGetId(['libelle' => '2024-2025']);
        $this->matA = $this->matiere($this->facA, $this->depA, 'Droit civil');
        $this->matB = $this->matiere($this->facB, $this->depB, 'Algèbre');
    }

    private function matiere(int $fac, int $dep, string $libelle): int
    {
        $filiere = DB::table('filieres')->insertGetId(['code' => 'F'.uniqid(), 'libelle' => "Filière $libelle", 'departement' => '', 'faculte_id' => $fac, 'departement_id' => $dep]);
        $promo = $this->creerPromotion($this->annee, ['filiere_id' => $filiere]);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S'.substr(uniqid(), -4), 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE'.uniqid(), 'libelle' => 'UE', 'credits_total' => 6, 'ordre' => 1]);

        return DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M'.uniqid(), 'libelle' => $libelle, 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 6, 'ordre' => 1]);
    }

    private function enseignant(?int $fac, string $nom = 'MOUSSA'): int
    {
        return DB::table('enseignants')->insertGetId(['nom' => $nom, 'prenoms' => 'Ali', 'faculte_id' => $fac]);
    }

    private function agent(string $role, array $attributs = []): Utilisateur
    {
        $this->donnerPermissions($role, ['etudiants_voir']);

        return $this->creerUtilisateur($role, $attributs);
    }

    public function test_liste_cloisonnee_et_affectations_par_annee(): void
    {
        $a = $this->enseignant($this->facA, 'ALPHA');
        $this->enseignant($this->facB, 'BRAVO');
        $this->enseignant(null, 'CHARLIE');
        DB::table('affectations')->insert([
            ['enseignant_id' => $a, 'matiere_id' => $this->matA, 'annee_id' => $this->annee, 'type_charge' => 'cours'],
            ['enseignant_id' => $a, 'matiere_id' => $this->matB, 'annee_id' => $this->anneePrec, 'type_charge' => 'td'],
        ]);

        $this->connecte($this->admin)->get($this->url('enseignants'))->assertOk()
            ->assertSee('Enseignants (3)')->assertSee('1 📚', false)->assertSee('Droit civil')->assertDontSee('Algèbre');
        $this->get($this->url('enseignants?aff_annee=0'))->assertSee('2 📚', false)->assertSee('Algèbre');

        $this->connecte($this->agent('sc_entite', ['faculte_id' => $this->facA]))->get($this->url('enseignants'))->assertOk()
            ->assertSee('ALPHA')->assertSee('CHARLIE')->assertDontSee('BRAVO')->assertSee('Verrouillée sur votre propre faculté.');
    }

    public function test_ajout_par_admin_et_par_agent_de_faculte(): void
    {
        $this->connecte($this->admin)->post($this->url('enseignants/enregistrer'), [
            'nom' => 'SANI', 'prenoms' => 'Issa', 'faculte_id' => $this->facA, 'departement_id' => $this->depB, 'date_naissance' => '1980-02-31',
        ])->assertRedirect($this->url('enseignants'))->assertSessionHas('succes', 'Enseignant ajouté.');
        $e = DB::table('enseignants')->where('nom', 'SANI')->first();
        $this->assertSame([$this->facA, null, null], [(int) $e->faculte_id, $e->departement_id, $e->date_naissance]);
        $this->assertDatabaseHas('audit_log', ['action' => 'creer', 'module' => 'enseignant', 'cible_id' => $e->id]);

        $this->connecte($this->agent('sc_entite', ['faculte_id' => $this->facA]))->post($this->url('enseignants/enregistrer'), [
            'nom' => 'IDI', 'prenoms' => 'Hawa', 'faculte_id' => $this->facB, 'departement_id' => $this->depA,
        ]);
        $this->assertDatabaseHas('enseignants', ['nom' => 'IDI', 'faculte_id' => $this->facA, 'departement_id' => $this->depA]);

        $this->connecte($this->admin)->post($this->url('enseignants/enregistrer'), ['nom' => 'X', 'prenoms' => 'Y', 'email' => 'pas-un-email'])
            ->assertSessionHas('erreur', 'Adresse e-mail invalide.');
    }

    public function test_modification_et_photo(): void
    {
        $id = $this->enseignant($this->facA);
        $png = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, base64_decode(self::PNG));

        $this->connecte($this->admin)->post($this->url('enseignants/enregistrer'), [
            'id' => $id, 'nom' => 'MOUSSA', 'prenoms' => 'Ali Kader', 'faculte_id' => $this->facA, 'photo' => new UploadedFile($png, 'moi.png', null, null, true),
        ])->assertRedirect($this->url("enseignants?id=$id"))->assertSessionHas('succes', 'Enseignant mis à jour.');
        $photo = DB::table('enseignants')->where('id', $id)->value('photo');
        $this->assertMatchesRegularExpression('/^ens_\d+_\d+\.png$/', $photo);

        $this->get($this->url("enseignants?id=$id"))->assertOk()->assertSee('Ali Kader')->assertSee("fichiers/photos-enseignants/$photo", false);
        $this->get($this->url("fichiers/photos-enseignants/$photo"))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $faux = tempnam(sys_get_temp_dir(), 'faux');
        file_put_contents($faux, '<?php echo 1;');
        $this->post($this->url('enseignants/enregistrer'), ['id' => $id, 'nom' => 'MOUSSA', 'prenoms' => 'Ali', 'photo' => new UploadedFile($faux, 'x.png', null, null, true)])
            ->assertSessionHas('avertissement');
        $this->assertSame($photo, DB::table('enseignants')->where('id', $id)->value('photo'));
    }

    public function test_autre_faculte_inaccessible(): void
    {
        $b = $this->enseignant($this->facB);
        $agent = $this->agent('sc_entite', ['faculte_id' => $this->facA]);

        $this->connecte($agent)->get($this->url("enseignants?id=$b"))->assertRedirect($this->url('enseignants'));
        $this->post($this->url('enseignants/enregistrer'), ['id' => $b, 'nom' => 'PIRATE', 'prenoms' => 'X'])->assertSessionHas('erreur');
        $this->post($this->url('enseignants/supprimer'), ['id' => $b])->assertSessionHas('erreur');
        $this->post($this->url('enseignants/affecter'), ['enseignant_id' => $b, 'matiere_id' => $this->matA, 'annee_id' => $this->annee])->assertSessionHas('erreur');
        $this->assertSame('MOUSSA', DB::table('enseignants')->where('id', $b)->value('nom'));
        $this->assertSame(0, DB::table('affectations')->count());
    }

    public function test_suppression_bloquee_si_historique(): void
    {
        $id = $this->enseignant($this->facA);
        DB::table('affectations')->insert(['enseignant_id' => $id, 'matiere_id' => $this->matA, 'annee_id' => $this->annee, 'type_charge' => 'cours']);
        $this->creerUtilisateur('enseignant', ['enseignant_id' => $id]);

        $this->connecte($this->admin)->post($this->url('enseignants/supprimer'), ['id' => $id])
            ->assertSessionHas('erreur', "Suppression impossible : MOUSSA Ali a encore 1 affectation(s), 1 compte utilisateur. Retirez-les d'abord.");
        $this->assertDatabaseHas('enseignants', ['id' => $id]);

        $libre = $this->enseignant(null, 'LIBRE');
        $this->post($this->url('enseignants/supprimer'), ['id' => $libre])->assertSessionHas('succes', 'Enseignant supprimé.');
        $this->assertDatabaseMissing('enseignants', ['id' => $libre]);
    }

    public function test_affectations_selon_le_perimetre(): void
    {
        $id = $this->enseignant(null);

        // Admin : toute matière ; mise à jour du type si déjà affectée
        $this->connecte($this->admin)->post($this->url('enseignants/affecter'), ['enseignant_id' => $id, 'matiere_id' => $this->matB, 'annee_id' => $this->annee, 'type_charge' => 'td'])
            ->assertRedirect($this->url("enseignants?id=$id&aff_annee={$this->annee}"))->assertSessionHas('succes');
        $this->post($this->url('enseignants/affecter'), ['enseignant_id' => $id, 'matiere_id' => $this->matB, 'annee_id' => $this->annee, 'type_charge' => 'cours_td']);
        $this->assertSame('cours_td', DB::table('affectations')->value('type_charge'));
        $this->post($this->url('enseignants/affecter'), ['enseignant_id' => $id, 'matiere_id' => 999999, 'annee_id' => $this->annee])
            ->assertSessionHas('erreur', 'Matière ou année introuvable.');

        // Agent de faculté A : pas les matières de la faculté B
        $this->connecte($this->agent('sc_entite', ['faculte_id' => $this->facA]))
            ->post($this->url('enseignants/affecter'), ['enseignant_id' => $id, 'matiere_id' => $this->matB, 'annee_id' => $this->anneePrec])
            ->assertSessionHas('erreur', "Cette matière n'appartient pas à votre faculté.");

        // Chef du département A : seulement ses matières, jamais la fiche
        $chef = $this->agent('chef_departement', ['departement_id' => $this->depA]);
        $this->connecte($chef)->post($this->url('enseignants/affecter'), ['enseignant_id' => $id, 'matiere_id' => $this->matA, 'annee_id' => $this->annee])->assertSessionHas('succes');
        $this->post($this->url('enseignants/affecter'), ['enseignant_id' => $id, 'matiere_id' => $this->matB, 'annee_id' => $this->anneePrec])
            ->assertSessionHas('erreur', "Cette matière n'appartient pas à votre département.");
        $affB = DB::table('affectations')->where('matiere_id', $this->matB)->value('id');
        $this->post($this->url('enseignants/desaffecter'), ['id' => $affB])->assertSessionHas('erreur');
        $this->post($this->url('enseignants/enregistrer'), ['nom' => 'NON', 'prenoms' => 'X'])->assertForbidden();
        $this->assertSame(2, DB::table('affectations')->count());

        $this->connecte($this->admin)->post($this->url('enseignants/desaffecter'), ['id' => $affB, 'retour_id' => $id])
            ->assertRedirect($this->url("enseignants?id=$id"))->assertSessionHas('succes', 'Affectation supprimée.');
    }

    public function test_reconduction_limitee_a_la_faculte(): void
    {
        $id = $this->enseignant(null);
        DB::table('affectations')->insert([
            ['enseignant_id' => $id, 'matiere_id' => $this->matA, 'annee_id' => $this->anneePrec, 'type_charge' => 'cours'],
            ['enseignant_id' => $id, 'matiere_id' => $this->matB, 'annee_id' => $this->anneePrec, 'type_charge' => 'td'],
        ]);

        $this->connecte($this->agent('sc_entite', ['faculte_id' => $this->facA]))
            ->post($this->url('enseignants/reconduire'), ['annee_src_id' => $this->anneePrec, 'annee_dest_id' => $this->annee])
            ->assertSessionHas('succes', "✅ 1 affectation(s) reconduite(s) vers l'année sélectionnée.");
        $this->assertSame([$this->matA], DB::table('affectations')->where('annee_id', $this->annee)->pluck('matiere_id')->all());

        $this->connecte($this->admin)->post($this->url('enseignants/reconduire'), ['annee_src_id' => $this->anneePrec, 'annee_dest_id' => $this->annee])
            ->assertSessionHas('succes', "✅ 2 affectation(s) reconduite(s) vers l'année sélectionnée.");
        $this->post($this->url('enseignants/reconduire'), ['annee_src_id' => $this->annee, 'annee_dest_id' => $this->annee])
            ->assertSessionHas('erreur', 'Veuillez sélectionner deux années différentes.');
        $this->assertSame(4, DB::table('affectations')->count());
    }

    public function test_fiche_detail_et_formulaire_d_affectation(): void
    {
        $id = $this->enseignant($this->facA);
        $this->connecte($this->admin)->get($this->url("enseignants?id=$id"))->assertOk()
            ->assertSee('MOUSSA Ali')->assertSee('🏛️ Faculté A', false)->assertSee('Droit civil (', false)->assertSee('Algèbre (', false)
            ->assertSee('<option value="'.$this->anneePrec.'" selected', false);   // reconduction depuis l'année précédente

        $chef = $this->agent('chef_departement', ['departement_id' => $this->depA]);
        $this->connecte($chef)->get($this->url("enseignants?id=$id"))->assertOk()
            ->assertSee('— matières de votre département', false)->assertSee('Droit civil (', false)->assertDontSee('Algèbre (', false)
            ->assertDontSee('id="btn-modifier-dossier"', false);
    }
}
