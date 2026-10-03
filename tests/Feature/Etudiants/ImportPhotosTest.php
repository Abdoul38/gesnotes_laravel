<?php

namespace Tests\Feature\Etudiants;

use App\Models\Utilisateur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class ImportPhotosTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Utilisateur $admin;

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->dossier = base_path('storage/framework/testing/uploads/photos');
        File::deleteDirectory(dirname($this->dossier));
        $this->admin = $this->creerUtilisateur('admin');
    }

    private function fichier(string $nom, string $contenu): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    private function etudiant(string $numero, string $nom, string $prenoms, ?string $photo = null): int
    {
        return DB::table('etudiants')->insertGetId(['numero' => $numero, 'nom' => $nom, 'prenoms' => $prenoms, 'photo' => $photo]);
    }

    private function importer(array $fichiers)
    {
        return $this->connecte($this->admin)->post($this->url('etudiants/photos/import'), ['photos' => $fichiers]);
    }

    public function test_page_et_statistiques(): void
    {
        $this->etudiant('00001', 'ABDOU', 'Ibrahim', 'x.jpg');
        $this->etudiant('00002', 'SALEY', 'Amina');
        $this->connecte($this->admin)->get($this->url('etudiants/photos/import'))->assertOk()
            ->assertSee('50% des étudiants ont une photo')->assertSee('Sans photo (1)')->assertSee('SALEY Amina');
    }

    public function test_image_par_matricule_avec_ou_sans_zeros_et_ancienne_photo_supprimee(): void
    {
        File::ensureDirectoryExists($this->dossier);
        file_put_contents($this->dossier.'/ancienne.jpg', 'x');
        $a = $this->etudiant('00001', 'ABDOU', 'Ibrahim', 'ancienne.jpg');
        $b = $this->etudiant('00002', 'SALEY', 'Amina');

        $this->importer([$this->fichier('00001.png', base64_decode(self::PNG)), $this->fichier('2.jpg', base64_decode(self::PNG))])
            ->assertOk()->assertSee('2 photo(s) importée(s) avec succès')->assertSee('ABDOU Ibrahim — 00001');

        $photoA = DB::table('etudiants')->where('id', $a)->value('photo');
        $photoB = DB::table('etudiants')->where('id', $b)->value('photo');
        $this->assertMatchesRegularExpression("/^photo_{$a}_\\d+_[0-9a-f]{6}\\.png$/", $photoA);
        // Extension du type réel : un PNG nommé .jpg est enregistré en .png
        $this->assertStringEndsWith('.png', $photoB);
        $this->assertFileExists($this->dossier.'/'.$photoA);
        $this->assertFileDoesNotExist($this->dossier.'/ancienne.jpg');
    }

    public function test_zip_par_nom_et_entrees_ignorees(): void
    {
        $id = $this->etudiant('00007', 'MOUSSA', 'Hadiza Ali');
        $chemin = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($chemin, ZipArchive::OVERWRITE);
        $zip->addEmptyDir('photos');
        $zip->addFromString('photos/Moussa_Hadiza Ali.PNG', base64_decode(self::PNG));
        $zip->addFromString('photos/99999.png', base64_decode(self::PNG));
        $zip->addFromString('photos/lisez-moi.txt', 'texte');
        $zip->close();

        $this->importer([new UploadedFile($chemin, 'lot.zip', null, null, true)])->assertOk()
            ->assertSee('1 photo(s) importée(s) avec succès — 1 ignorée(s)')->assertSee('Aucun étudiant trouvé')
            ->assertDontSee('lisez-moi.txt');
        $this->assertNotNull(DB::table('etudiants')->where('id', $id)->value('photo'));
    }

    public function test_faux_fichier_image_refuse(): void
    {
        $id = $this->etudiant('00001', 'ABDOU', 'Ibrahim');
        $this->importer([$this->fichier('00001.jpg', '<?php echo "x";')])->assertOk()
            ->assertSee("Ce fichier n&#039;est pas une image valide", false)->assertSee('Aucune photo importée');
        $this->assertNull(DB::table('etudiants')->where('id', $id)->value('photo'));
        $this->assertSame([], File::glob($this->dossier.'/*'));
    }

    public function test_correspondance_partielle_ambigue_ou_vide_ignoree(): void
    {
        $this->etudiant('00001', 'ABDOU', 'Ibrahim');
        $this->etudiant('00002', 'ABDOULAYE', 'Sani');
        $this->importer([$this->fichier('abd.png', base64_decode(self::PNG)), $this->fichier('.png', base64_decode(self::PNG))])->assertOk()
            ->assertSee('Plusieurs étudiants correspondent')->assertSee('Aucun étudiant trouvé');
        $this->assertSame(0, DB::table('etudiants')->whereNotNull('photo')->count());
    }

    public function test_format_non_pris_en_charge_et_nom_echappe(): void
    {
        $this->importer([$this->fichier('<b>x</b>.pdf', '%PDF')])->assertOk()
            ->assertSee('Format non pris en charge')->assertDontSee('<b>x</b>', false);
    }

    public function test_sans_fichier(): void
    {
        $this->importer([])->assertOk()->assertSee('Veuillez sélectionner au moins une image ou un fichier ZIP.');
    }

    public function test_permission_requise(): void
    {
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('etudiants/photos/import'))->assertRedirect();
    }
}
