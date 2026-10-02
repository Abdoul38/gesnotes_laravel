<?php

namespace Tests\Unit;

use App\Autorisations\Roles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Mêmes réponses que l'ancien hasRole(). */
class RolesTest extends TestCase
{
    public static function cas(): array
    {
        return [
            ['admin', 'secretaire', true],
            ['secretaire', 'admin', false],
            ['etudiant', 'etudiant', true],
            ['admin', 'etudiant', false],          // règle propre : seul un étudiant est « étudiant »
            ['secretaire', 'enseignant', true],
            ['sc_entite', 'enseignant', false],    // niveau 4 > 3, mais règle propre
            ['sc_centrale', 'sc_entite', false],
            ['secretaire', 'sc_centrale', true],
            ['doyen', 'user', true],               // même niveau 2
            ['lecture', 'user', false],
            ['inconnu', 'lecture', false],
            ['lecture', 'role_inconnu', true],     // rôle requis inconnu = niveau 0
        ];
    }

    #[DataProvider('cas')]
    public function test_satisfait(string $utilisateur, string $requis, bool $attendu): void
    {
        $this->assertSame($attendu, Roles::satisfait($utilisateur, $requis));
    }
}
