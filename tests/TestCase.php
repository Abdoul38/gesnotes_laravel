<?php

namespace Tests;

use App\Models\Utilisateur;
use App\Services\Licence;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
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
    private function viderBase(): void
    {
        $base = (string) DB::connection()->getDatabaseName();
        if (! str_contains($base, '_test')) {
            throw new RuntimeException("Les tests refusent de tourner sur « $base » (base non test).");
        }
        self::$tables[$base] ??= DB::table('information_schema.tables')
            ->where('table_schema', $base)->where('table_type', 'BASE TABLE')
            ->pluck('table_name')->all();

        $sql = implode(' UNION ALL ', array_map(fn ($t) => "(SELECT '$t' AS t FROM `$t` LIMIT 1)", self::$tables[$base]));
        $remplies = array_column(DB::select($sql), 't');
        if (! $remplies) {
            return;
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($remplies as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
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

    protected function url(string $chemin, string $code = 'udh'): string
    {
        return "http://$code.localhost/".ltrim($chemin, '/');
    }
}
