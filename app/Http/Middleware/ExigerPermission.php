<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « permission:code » — rôle (ex. 'admin') ou code de permission
 * (ex. 'paiements_voir'). Refus → tableau de bord avec un message,
 * comme l'ancien routeur (?page=accueil&err=acces_refuse).
 */
class ExigerPermission
{
    public function handle(Request $request, Closure $next, string $roleOuPermission): Response
    {
        if (! $request->user()?->autorise($roleOuPermission)) {
            if ($request->expectsJson()) {
                abort(403, 'Accès refusé');
            }

            return redirect()->route('accueil')->with('erreur', "Accès refusé : vous n'avez pas la permission d'ouvrir cette page.");
        }

        return $next($request);
    }
}
