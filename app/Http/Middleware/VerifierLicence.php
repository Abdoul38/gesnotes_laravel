<?php

namespace App\Http\Middleware;

use App\Navigation\Menu;
use App\Services\Licence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque l'application si la licence de l'université est invalide ou
 * expirée (ancien index.php). Connexion et déconnexion restent possibles.
 */
class VerifierLicence
{
    public function __construct(private readonly Licence $licence, private readonly Menu $menu) {}

    public function handle(Request $request, Closure $next): Response
    {
        $statut = $this->licence->verifierInstallation();
        if ($statut['valide']) {
            return $next($request);
        }

        $admin = (bool) $request->user()?->estAdmin();

        return response()->view('licence-invalide', [
            'erreur' => $statut['erreur'],
            'lienLicence' => $admin ? $this->menu->lien('parametres_licence') : null,
        ], 403);
    }
}
