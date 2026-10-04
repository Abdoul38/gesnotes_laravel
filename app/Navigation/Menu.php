<?php

namespace App\Navigation;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\Route;

/**
 * Menu latéral (config/menu.php) filtré selon les permissions de
 * l'utilisateur — même règle que l'ancien render_sidebar().
 */
class Menu
{
    /**
     * @return array<string, list<array{page:string, icone:string, libelle:string, url:string}>>
     */
    public function pour(Utilisateur $utilisateur): array
    {
        $sections = [];
        foreach (config('menu') as $section => $items) {
            foreach ($items as $page => [$icone, $libelle, $permission]) {
                if ($permission !== null && ! $utilisateur->aPermission($permission)) {
                    continue;
                }
                if (! Route::has($page)) {
                    continue;
                }
                $sections[$section][] = [
                    'page' => $page,
                    'icone' => $icone,
                    'libelle' => $libelle,
                    'url' => route($page),
                ];
            }
        }

        return $sections;
    }

    /** Permission exigée pour une page : celle du menu, ou celle de sa page parente. */
    public static function permissionDe(string $page): ?string
    {
        foreach (config('menu') as $items) {
            if (isset($items[$page])) {
                return $items[$page][2];
            }
        }

        return config('gesnotes.permissions_pages')[$page] ?? null;
    }

    /**
     * Lien vers une page désignée par son ancien nom (?page=…), qui est
     * aussi le nom de sa route. Toutes les pages sont migrées (étape 13) ;
     * un nom inconnu mène au tableau de bord.
     */
    public function lien(string $page, array $parametres = []): string
    {
        return Route::has($page) ? route($page, $parametres) : route('accueil');
    }
}
