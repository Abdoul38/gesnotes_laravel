<?php

namespace Tests;

use App\Models\Utilisateur;
use App\Services\Licence;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Les tests utilisent UNIQUEMENT les bases gesnotes_test_udh et
 * gesnotes_test_uam (voir phpunit.xml), créées par :
 *   php artisan gesnotes:preparer-bases-test
 */
abstract class TestCase extends BaseTestCase
{
    /** @var array<string, list<string>> tables de chaque base de test */
    private static array $tables = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Base propre aux espaces Ministère / ANAB (ESPACES_BASE de phpunit.xml)
        if (config('gesnotes.espaces_base')) {
            $this->viderBase(DB::connection('espaces'));
        }
        foreach (['uam', 'udh'] as $code) {
            $this->universite($code);
            $this->viderBase();
        }
        // UDH par défaut, comme l'application hors requête web
    }

    /**
     * Vide toutes les tables qui contiennent des lignes (une requête pour
     * les trouver, puis DELETE) : plus rapide que TRUNCATE partout, et
     * aucune liste à tenir à jour quand un module ajoute des tables.
     */
    private function viderBase(?Connection $connexion = null): void
    {
        $connexion ??= DB::connection();
        $base = (string) $connexion->getDatabaseName();
        if (! str_contains($base, '_test')) {
            throw new RuntimeException("Les tests refusent de tourner sur « $base » (base non test).");
        }
        self::$tables[$base] ??= $connexion->table('information_schema.tables')
            ->where('table_schema', $base)->where('table_type', 'BASE TABLE')
            ->pluck('table_name')->all();

        $sql = implode(' UNION ALL ', array_map(fn ($t) => "(SELECT '$t' AS t FROM `$t` LIMIT 1)", self::$tables[$base]));
        $remplies = array_column($connexion->select($sql), 't');
        if (! $remplies) {
            return;
        }
        $connexion->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($remplies as $table) {
            $connexion->table($table)->delete();
        }
        $connexion->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** Bascule la connexion vers la base de test de l'université $code. */
    protected function universite(string $code): void
    {
        app(UniversiteCourante::class)->definir(app(RegistreUniversites::class)->trouver($code));
    }

    protected function creerUtilisateur(string $role = 'admin', array $attributs = []): Utilisateur
    {
        $u = new Utilisateur;
        $u->forceFill($attributs + [
            'nom' => 'TEST', 'prenoms' => 'Utilisateur', 'email' => $role.'@test.local',
            'role' => $role, 'actif' => 1,
        ]);
        $u->mot_de_passe = $attributs['mot_de_passe'] ?? 'MotDePasse1';
        $u->save();

        return $u->refresh();
    }

    /** Licence valide pour l'université courante. */
    protected function installerLicence(string $institution = 'UDH', string $expiration = '', int $max = 0): void
    {
        DB::table('parametres')->updateOrInsert(['cle' => 'licence_cle'], [
            'valeur' => app(Licence::class)->generer($institution, $expiration, $max),
        ]);
    }

    protected function donnerPermissions(string $role, array $codes): void
    {
        foreach ($codes as $code) {
            DB::table('role_permissions')->insertOrIgnore(['role' => $role, 'permission_code' => $code]);
        }
    }

    /** Hash produit comme par l'ancienne application (password_hash, coût 10). */
    protected function hashAncien(string $motDePasse): string
    {
        return password_hash($motDePasse, PASSWORD_DEFAULT);
    }

    /** Promotion complète (filière + niveau créés au besoin) pour une année. */
    protected function creerPromotion(int $anneeId, array $attributs = []): int
    {
        $filiere = $attributs['filiere_id'] ?? DB::table('filieres')->insertGetId(['code' => 'F'.uniqid(), 'libelle' => 'Filière test', 'departement' => 'Dépt test']);
        $niveau = $attributs['niveau_id'] ?? DB::table('niveaux')->insertGetId(['code' => substr('N'.uniqid(), -10), 'libelle' => 'Niveau test']);

        return DB::table('promotions')->insertGetId(['filiere_id' => $filiere, 'niveau_id' => $niveau, 'annee_id' => $anneeId]);
    }

    /** Requêtes suivantes faites par $u, avec une session valide sur l'université $code. */
    protected function connecte(Utilisateur $u, string $code = 'udh'): static
    {
        return $this->actingAs($u)->withSession(['universite' => $code, 'derniere_activite' => time()]);
    }

    /**
     * Insère une ligne en complétant les colonnes NOT NULL sans défaut par
     * des valeurs factices (tests qui n'ont besoin que d'une ligne liée).
     */
    protected function insererMinimal(string $table, array $valeurs): int
    {
        $colonnes = DB::select("SELECT column_name AS c, data_type AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND is_nullable = 'NO' AND column_default IS NULL AND extra NOT LIKE '%auto_increment%'", [$table]);
        foreach ($colonnes as $col) {
            $valeurs[$col->c] ??= match (true) {
                str_contains($col->t, 'int'), str_contains($col->t, 'decimal'), str_contains($col->t, 'float') => 1,
                str_contains($col->t, 'date'), str_contains($col->t, 'time') => '2026-01-01',
                str_contains($col->t, 'enum') => DB::scalar("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(column_type, '''', 2), '''', -1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$table, $col->c]),
                default => 'x'.uniqid(),
            };
        }

        return DB::table($table)->insertGetId($valeurs);
    }

    /**
     * Fichier .xlsx minimal déposé par un formulaire. $lignes : numéro de
     * ligne Excel => cellules ; texte en chaînes partagées, nombres tels quels.
     */
    protected function fichierXlsx(array $lignes, string $nom = 'import.xlsx'): \Illuminate\Http\UploadedFile
    {
        $partagees = [];
        $xml = '';
        foreach ($lignes as $numero => $cellules) {
            $xml .= '<row r="'.$numero.'">';
            foreach (array_values($cellules) as $i => $valeur) {
                $ref = chr(65 + $i % 26);
                $ref = ($i >= 26 ? chr(64 + intdiv($i, 26)) : '').$ref.$numero;
                if (is_int($valeur)) {
                    $xml .= "<c r=\"$ref\"><v>$valeur</v></c>";
                } elseif ($valeur !== '') {
                    $partagees[] = $valeur;
                    $xml .= "<c r=\"$ref\" t=\"s\"><v>".(count($partagees) - 1).'</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $sst = implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>', $partagees));
        $chemin = \App\Support\FichierTemporaire::chemin('xlsx', '.xlsx');
        $zip = new \ZipArchive;
        $zip->open($chemin, \ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$sst.'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
        $zip->close();

        return new \Illuminate\Http\UploadedFile($chemin, $nom, null, null, true);
    }

    protected function fichierTexte(string $contenu, string $nom = 'import.csv'): \Illuminate\Http\UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($chemin, $contenu);

        return new \Illuminate\Http\UploadedFile($chemin, $nom, null, null, true);
    }

    protected function url(string $chemin, string $code = 'udh'): string
    {
        return "http://$code.localhost/".ltrim($chemin, '/');
    }
}
