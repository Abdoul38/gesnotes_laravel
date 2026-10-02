<?php

namespace App\Navigation;

use App\Models\Utilisateur;
use App\Universites\UniversiteCourante;
use Illuminate\Support\Facades\Route;

/**
 * Menu latéral (config/menu.php) filtré selon les permissions de
 * l'utilisateur — même règle que l'ancien render_sidebar().
 */
class Menu
{
    public function __construct(private readonly UniversiteCourante $universite) {}

    /**
     * @return array<string, list<array{page:string, icone:string, libelle:string, url:string, migree:bool}>>
     */
    public function pour(Utilisateur $utilisateur): array
    {
        $sections = [];
        foreach (config('menu') as $section => $items) {
            foreach ($items as $page => [$icone, $libelle, $permission]) {
                if ($permission !== null && ! $utilisateur->aPermission($permission)) {
                    continue;
                }
                $migree = Route::has($page);
                $sections[$section][] = [
                    'page' => $page,
                    'icone' => $icone,
                    'libelle' => $libelle,
                    'url' => $migree ? route($page) : $this->ancienneUrl($page),
                    'migree' => $migree,
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
     * Lien vers une page, où qu'elle soit pendant la migration : la route
     * Laravel du même nom si elle existe, sinon l'ancienne application.
     */
    public function lien(string $page, array $parametres = []): string
    {
        return Route::has($page) ? route($page, $parametres) : $this->ancienneUrl($page, $parametres);
    }

    /** Lien vers la même page dans l'ancienne application. */
    public function ancienneUrl(string $page, array $parametres = []): string
    {
        $base = str_replace('{code}', $this->universite->get()->code, config('gesnotes.ancienne_url'));

        return rtrim($base, '/').'/index.php?'.http_build_query(['page' => $page] + $parametres);
    }
}
