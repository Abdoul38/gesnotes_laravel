<?php

namespace App\Autorisations;

/**
 * Hiérarchie des rôles — portage à l'identique de hierarchieRoles() et
 * hasRole() (ancien includes/functions.php).
 */
final class Roles
{
    public const HIERARCHIE = [
        'admin' => 6, 'secretaire' => 5,
        'sc_entite' => 4, 'sc_centrale' => 4,
        'enseignant' => 3,
        'recteur' => 2, 'doyen' => 2, 'chef_departement' => 2, 'user' => 2,
        'etudiant' => 1, 'lecture' => 1,
    ];

    public static function estUnRole(string $nom): bool
    {
        return array_key_exists($nom, self::HIERARCHIE);
    }

    /**
     * L'utilisateur de rôle $roleUtilisateur a-t-il au moins le rôle $roleRequis ?
     * Quatre rôles ont une règle propre ; les autres suivent la hiérarchie.
     */
    public static function satisfait(string $roleUtilisateur, string $roleRequis): bool
    {
        return match ($roleRequis) {
            'etudiant' => $roleUtilisateur === 'etudiant',
            'enseignant' => in_array($roleUtilisateur, ['enseignant', 'secretaire', 'admin'], true),
            'sc_entite' => in_array($roleUtilisateur, ['sc_entite', 'secretaire', 'admin'], true),
            'sc_centrale' => in_array($roleUtilisateur, ['sc_centrale', 'secretaire', 'admin'], true),
            default => (self::HIERARCHIE[$roleUtilisateur] ?? 0) >= (self::HIERARCHIE[$roleRequis] ?? 0),
        };
    }
}
