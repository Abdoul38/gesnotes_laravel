<?php

namespace Tests\Feature\Structure;

use App\Models\Utilisateur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FacultesTest extends TestCase
{
    private Utilisateur $admin;

    private string $logos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->logos = base_path('storage/framework/testing/uploads/logos');
        File::deleteDirectory(base_path('storage/framework/testing/uploads'));
    }

    /** Vraie image PNG 1×1 (GD n'est pas installé : pas de UploadedFile::fake()->image()). */
    private function png(string $nom = 'logo.png'): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($chemin, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        return new UploadedFile($chemin, $nom, 'image/png', null, true);
    }

    public function test_creation_avec_logo(): void
    {
        $this->connecte($this->admin)->post($this->url('facultes'), [
            'code' => 'fadeg', 'libelle' => 'Faculté de Droit', 'ville' => 'Tahoua', 'couleur' => '#123ABC', 'logo' => $this->png(),
        ])->assertSessionHas('succes', 'Faculté «FADEG» créée.');

        $f = DB::table('facultes')->where('code', 'FADEG')->first();
        $this->assertSame('#123ABC', $f->couleur);
        $this->assertMatchesRegularExpression('/^logo_fadeg_\d+\.png$/', $f->logo);
        $this->assertFileExists($this->logos.'/'.$f->logo);
        $this->get($this->url('fichiers/logos/'.$f->logo))->assertOk();
    }

    public function test_couleur_invalide_remplacee_par_la_couleur_par_defaut(): void
    {
        $this->connecte($this->admin)->post($this->url('facultes'), [
            'code' => 'FSA', 'libelle' => 'Sciences', 'couleur' => 'red;background:url(x)',
        ]);
        $this->assertSame('#1A3A5C', DB::table('facultes')->where('code', 'FSA')->value('couleur'));
    }

    public function test_code_saisi_ne_peut_pas_sortir_du_dossier_des_logos(): void
    {
        $this->connecte($this->admin)->post($this->url('facultes'), [
            'code' => '../../EVIL', 'libelle' => 'X', 'logo' => $this->png(),
        ]);
        $logo = DB::table('facultes')->value('logo');
        $this->assertMatchesRegularExpression('/^logo_evil_\d+\.png$/', $logo);
        $this->assertFileExists($this->logos.'/'.$logo);
    }

    public function test_faux_png_refuse(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($chemin, '<?php echo "pas une image";');

        $this->connecte($this->admin)->post($this->url('facultes'), [
            'code' => 'FSE', 'libelle' => 'X', 'logo' => new UploadedFile($chemin, 'logo.png', 'image/png', null, true),
        ])->assertSessionHas('erreur', 'Logo refusé : image PNG ou JPG de 2 Mo maximum.');
        $this->assertDatabaseMissing('facultes', ['code' => 'FSE']);
    }

    public function test_modification_garde_le_logo_en_base_et_ignore_le_champ_cache(): void
    {
        $id = DB::table('facultes')->insertGetId(['code' => 'FADEG', 'libelle' => 'Droit', 'logo' => 'logo_fadeg_1.png']);

        $this->connecte($this->admin)->post($this->url('facultes'), [
            'id' => $id, 'code' => 'FADEG', 'libelle' => 'Droit et Économie', 'couleur' => '#000000',
            'logo_actuel' => '../../config/universities.php',
        ])->assertSessionHas('succes', 'Faculté mise à jour.');

        $this->assertDatabaseHas('facultes', ['id' => $id, 'libelle' => 'Droit et Économie', 'logo' => 'logo_fadeg_1.png']);
    }

    public function test_doublon_et_champs_requis(): void
    {
        DB::table('facultes')->insert(['code' => 'FADEG', 'libelle' => 'Droit']);
        $this->connecte($this->admin)->post($this->url('facultes'), ['code' => 'fadeg', 'libelle' => 'Autre'])
            ->assertSessionHas('erreur', 'Le code «FADEG» est déjà utilisé par une autre faculté.');
        $this->connecte($this->admin)->post($this->url('facultes'), ['code' => '', 'libelle' => 'Autre'])
            ->assertSessionHas('erreur', 'Code et libellé requis.');
    }

    public function test_suppression_et_blocages(): void
    {
        $libre = DB::table('facultes')->insertGetId(['code' => 'A', 'libelle' => 'Libre']);
        $this->connecte($this->admin)->post($this->url('facultes/supprimer'), ['id' => $libre])->assertSessionHas('succes', 'Faculté supprimée.');
        $this->assertDatabaseMissing('facultes', ['id' => $libre]);

        $avecAgent = DB::table('facultes')->insertGetId(['code' => 'B', 'libelle' => 'Avec agent']);
        $this->creerUtilisateur('secretaire', ['faculte_id' => $avecAgent]);
        $this->connecte($this->admin)->post($this->url('facultes/supprimer'), ['id' => $avecAgent])
            ->assertSessionHas('erreur', 'Impossible — 1 utilisateur(s) lié(s) à cette faculté.');

        $avecDept = DB::table('facultes')->insertGetId(['code' => 'C', 'libelle' => 'Avec département']);
        DB::table('departements')->insert(['faculte_id' => $avecDept, 'code' => 'D1', 'libelle' => 'Dépt']);
        $this->connecte($this->admin)->post($this->url('facultes/supprimer'), ['id' => $avecDept])
            ->assertSessionHas('erreur', 'Impossible — cette faculté a encore 1 département(s).');
        $this->assertSame(1, DB::table('departements')->where('faculte_id', $avecDept)->count());
    }

    public function test_activation_desactivation(): void
    {
        $id = DB::table('facultes')->insertGetId(['code' => 'A', 'libelle' => 'A', 'actif' => 1]);
        $this->connecte($this->admin)->post($this->url('facultes/basculer'), ['id' => $id])->assertRedirect($this->url('facultes'));
        $this->assertSame(0, (int) DB::table('facultes')->where('id', $id)->value('actif'));
    }

    public function test_page_liste(): void
    {
        DB::table('facultes')->insert(['code' => 'FADEG', 'libelle' => 'Faculté de Droit', 'couleur' => '#ABCDEF']);
        $this->connecte($this->admin)->get($this->url('facultes'))->assertOk()
            ->assertSee('Facultés (1)')->assertSee('Faculté de Droit')->assertSee('#ABCDEF');
    }
}
