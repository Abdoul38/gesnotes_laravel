<?php

namespace App\Espaces;

use App\Models\Espaces\CompteAnab;
use App\Models\Espaces\CompteMesr;
use Illuminate\Http\Request;

/**
 * Espaces nationaux servis sur leur propre sous-domaine (ancien index.php,
 * blocs IS_MINISTERE et IS_ANAB) : chacun a sa garde d'authentification,
 * ses comptes et ses rôles. Le code de l'espace est aussi le préfixe du
 * nom de ses routes (ministere.*, anab.*).
 */
final class Espaces
{
    public const LISTE = [
        'ministere' => [
            'garde' => 'mesr', 'modele' => CompteMesr::class, 'protege' => 'mesr',
            'roles' => ['lecteur' => 'Lecteur', 'admin' => 'Admin'],
            'titre' => "Ministère de l'Enseignement Supérieur", 'sous_titre' => 'Tableau de bord national CURSUS',
            'couleur' => '#1A3A5C', 'icone' => '🇳🇪',
        ],
        'anab' => [
            'garde' => 'anab', 'modele' => CompteAnab::class, 'protege' => 'anab',
            'roles' => ['agent' => 'Agent', 'admin' => 'Admin'],
            'titre' => 'ANAB — Portail de Consultation', 'sous_titre' => 'Agence Nationale des Bourses · CURSUS',
            'couleur' => '#7C3AED', 'icone' => '🎓',
        ],
    ];

    /** Code de l'espace de la requête (posé par ResoudreUniversite), ou null. */
    public static function courant(Request $request): ?string
    {
        $code = $request->attributes->get('espace');

        return isset(self::LISTE[$code]) ? $code : null;
    }

    /**
     * Connexion des comptes et du journal des espaces : « espaces » (base
     * propre, ESPACES_BASE) si elle est configurée, sinon la connexion de
     * l'université courante (base des comptes, ancien fonctionnement).
     */
    public static function connexion(): ?string
    {
        return filled(config('gesnotes.espaces_base')) ? 'espaces' : null;
    }

    /** Tables propres aux espaces, déplacées dans leur base. */
    public const TABLES = ['mesr_comptes', 'anab_comptes', 'mesr_logs_consultation'];

    public static function config(string $code): array
    {
        return self::LISTE[$code] + ['code' => $code];
    }
}
