<?php

namespace App\Http\Middleware;

use App\Navigation\Menu;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * « page:annees » — exige la permission de la page dans le menu (ou de sa
 * page parente), exactement comme l'ancien routeur. Une seule source :
 * config/menu.php et config/gesnotes.php (permissions_pages).
 */
class ExigerPermissionPage
{
    public function __construct(private readonly ExigerPermission $permission) {}

    public function handle(Request $request, Closure $next, string $page): Response
    {
        $code = Menu::permissionDe($page)
            ?? throw new RuntimeException("Aucune permission connue pour la page « $page ».");

        return $this->permission->handle($request, $next, $code);
    }
}
