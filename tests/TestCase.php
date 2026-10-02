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
    /** Tables vidées avant chaque test, dans chaque base de test. */
    protected array $tablesAVider = [
        'utilisateurs', 'parametres', 'role_permissions', 'facultes', 'departements',
        'journal_audit', 'audit_log', 'annees_academiques', 'etudiants', 'filieres',
        'niveaux', 'promotions', 'inscriptions', 'inscriptions_pedagogiques', 'semestres', 'signataires',
        'grille_tarifaire', 'tarifs_niveaux', 'salles', 'permissions',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['uam', 'udh'] as $code) {
            $this->universite($code);
            $base = DB::connection()->getDatabaseName();
            if (! str_contains((string) $base, '_test')) {
                throw new RuntimeException("Les tests refusent de tourner sur « $base » (base non test).");
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($this->tablesAVider as $table) {
                DB::table($table)->truncate();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        // UDH par défaut, comme l'application hors requête web
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
