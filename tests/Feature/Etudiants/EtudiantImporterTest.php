<?php

namespace Tests\Feature\Etudiants;

use App\Models\Utilisateur;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EtudiantImporterTest extends TestCase
{
    private Utilisateur $admin;

    private int $filiere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'FSEJ']);
    }

    private function importer($fichier, ?int $filiere = null)
    {
        return $this->connecte($this->admin)->from($this->url('etudiants/importer'))
            ->post($this->url('etudiants/importer'), ['fichier_import' => $fichier, 'filiere_id_import' => $filiere ?? $this->filiere]);
    }

    public function test_page(): void
    {
        $this->connecte($this->admin)->get($this->url('etudiants/importer'))->assertOk()
            ->assertSee('<optgroup label="FSEJ">', false)->assertSee('Économie')->assertSee('fichiers/modele-import-etudiants', false);
    }

    public function test_modele_excel_classique(): void
    {
        $aide = array_fill(1, 7, ['⚠ Instructions']);
        $fichier = $this->fichierXlsx($aide + [
            8 => ['élodie', 'marie-claire', 'f', 37391, 'Tahoua', '', '90000001', 'Pas-un-email', '', '', '', '', 'D', '21', 'Passable', 'Lycée', '', 'd123456', 'ABC'],
            9 => ['MOUSSA', 'Ali', 'M', '15/05/2002', 'Agadez'],
        ]);

        $this->importer($fichier)->assertRedirect($this->url('etudiants'))
            ->assertSessionHas('succes', '2 étudiant(s) importé(s) avec succès (format classique).');

        $e = DB::table('etudiants')->where('nom', 'ÉLODIE')->first();
        $this->assertSame('Marie-Claire', $e->prenoms);
        $this->assertSame('F', $e->sexe);
        $this->assertSame('2002-05-15', $e->date_naissance);       // date numérique Excel
        $this->assertNull($e->email);                               // e-mail invalide laissé vide
        $this->assertNull($e->bac_annee);                           // « 21 » refusé au lieu de faire échouer l'import
        $this->assertSame('D123456', $e->numero_dossier);
        $this->assertSame('abc', $e->numero_depot);
        $this->assertNull($e->numero);
        $this->assertSame($this->filiere, (int) $e->filiere_id);
        $this->assertSame('Nigérienne', $e->nationalite);
        $this->assertDatabaseHas('audit_log', ['action' => 'importer', 'module' => 'etudiant']);
    }

    public function test_export_portail_excel_avec_titre(): void
    {
        $entetes = ['N° Dossier', 'N° Dépôt', 'Nom', 'Prénom', 'Date Naissance', 'Lieu Naissance', 'Nationalité', 'Sexe',
            'Téléphone', 'Email', 'Adresse', 'Nom Père', 'Nom Mère', 'Tél. Urgence', 'Type BAC', 'Lieu BAC', 'Année BAC', 'Mention BAC', 'Établissement BAC', 'Statut'];
        $fichier = $this->fichierXlsx([
            1 => ['Candidatures — Université Djibo Hamani de Tahoua'],
            2 => ['Généré le 01/10/2026 — 2 dossier(s)'],
            4 => $entetes,
            5 => ['d0001', 'DEP1', 'SALEY', 'Amina', '06/05/2004', 'Tahoua', 'Malienne', 'Féminin', '90', 'amina@exemple.ne', 'Quartier Nord', 'Saley Père', 'Saley Mère', '91', 'A4', 'Tahoua', '2023', 'Bien', 'Lycée X', 'accepte'],
            6 => ['d0002', '', 'ZAKARI', 'Ali', '', '', '', 'Masculin'],
        ]);

        $this->importer($fichier)->assertSessionHas('succes', '2 étudiant(s) importé(s) avec succès (format Portail).');
        $e = DB::table('etudiants')->where('nom', 'SALEY')->first();
        $this->assertSame(['F', 'Malienne', 'Quartier Nord', 'Saley Père', 'A4', '2023', 'Bien', 'Lycée X', 'actif', 'D0001', 'dep1'],
            [$e->sexe, $e->nationalite, $e->adresse, $e->nom_pere, $e->bac_serie, (string) $e->bac_annee, $e->bac_mention, $e->etablissement_origine, $e->statut_etudiant, $e->numero_dossier, $e->numero_depot]);
        $this->assertSame('M', DB::table('etudiants')->where('nom', 'ZAKARI')->value('sexe'));
    }

    public function test_csv_avec_en_tete_windows_1252_et_doublons(): void
    {
        DB::table('etudiants')->insert(['nom' => 'ABDOU', 'prenoms' => 'Ibrahim', 'date_naissance' => '2002-05-15']);
        $csv = mb_convert_encoding("Nom;Prénoms;Sexe;Date\n# commentaire\nabdou;ibrahim;M;15/05/2002\nGARBA;Aïcha;F;31/02/2003\n;sans nom\n", 'Windows-1252', 'UTF-8');

        $this->importer($this->fichierTexte($csv))
            ->assertSessionHas('succes', '1 étudiant(s) importé(s) avec succès (format classique) — 2 doublon(s) ignoré(s).');
        $this->assertSame(['ABDOU', 'GARBA'], DB::table('etudiants')->orderBy('nom')->pluck('nom')->all());
        $this->assertSame('Aïcha', DB::table('etudiants')->where('nom', 'GARBA')->value('prenoms'));
        $this->assertNull(DB::table('etudiants')->where('nom', 'GARBA')->value('date_naissance'));   // 31/02 refusé
    }

    public function test_csv_portail_ancienne_disposition(): void
    {
        $csv = "N° Dossier,N° Dépôt,Nom,Prénom,Date Naissance,Lieu Naissance,Nationalité,Genre,Téléphone,Email\nD9,X,IDI,Hawa,2001-01-02,Niamey,,F,,\n";
        $this->importer($this->fichierTexte($csv))->assertSessionHas('succes');
        $this->assertDatabaseHas('etudiants', ['nom' => 'IDI', 'sexe' => 'F', 'date_naissance' => '2001-01-02', 'numero_dossier' => 'D9']);
    }

    public function test_erreurs(): void
    {
        $this->importer($this->fichierTexte(''), 0)->assertRedirect($this->url('etudiants/importer'))
            ->assertSessionHas('erreurs_import', ['Aucun fichier valide fourni ou fichier vide.', 'Veuillez sélectionner une filière.']);
        DB::table('filieres')->where('id', $this->filiere)->update(['actif' => 0]);
        $this->importer($this->fichierTexte("A;B\n"))->assertSessionHas('erreurs_import', ['Filière introuvable.']);
        $this->importer($this->fichierTexte('pas un xlsx', 'liste.xlsx'))->assertSessionHas('erreurs_import');
        $this->assertSame(0, DB::table('etudiants')->count());
    }

    public function test_modele_a_telecharger(): void
    {
        $dossier = base_path('storage/framework/testing/uploads');
        File::ensureDirectoryExists($dossier);
        File::delete($dossier.'/modele_import_etudiants.xlsx');
        $this->connecte($this->admin)->get($this->url('fichiers/modele-import-etudiants'))->assertNotFound();
        file_put_contents($dossier.'/modele_import_etudiants.xlsx', 'PK');
        $this->get($this->url('fichiers/modele-import-etudiants'))->assertOk()->assertDownload('modele_import_etudiants.xlsx');
        File::delete($dossier.'/modele_import_etudiants.xlsx');
    }

    public function test_lecture_des_dates(): void
    {
        $this->assertSame('2002-05-15', Format::dateImport('15/05/2002'));
        $this->assertSame('2002-05-15', Format::dateImport('15-05-02'));
        $this->assertSame('1985-05-15', Format::dateImport('15.05.85'));
        $this->assertSame('2002-05-15', Format::dateImport('2002/05/15'));
        $this->assertSame('2002-05-15', Format::dateImport('37391'));
        $this->assertNull(Format::dateImport('31/02/2002'));
        $this->assertNull(Format::dateImport('demain'));
        $this->assertNull(Format::dateImport(''));
    }
}
