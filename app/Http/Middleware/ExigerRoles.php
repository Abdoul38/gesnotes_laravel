<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « roles:admin,secretaire » — l'utilisateur doit avoir EXACTEMENT l'un de
 * ces rôles. Reprend les contrôles in_array($_SESSION['user_role'], [...])
 * que les anciennes pages faisaient en plus de la permission du routeur.
 */
class ExigerRoles
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->role, $roles, true)) {
            if ($request->expectsJson()) {
                abort(403, 'Accès refusé');
            }

            return redirect()->route('accueil')->with('erreur', "Accès réservé à l'administration.");
        }

        return $next($request);
    }
}
