<?php

namespace App\Http\Middleware;

use App\Espaces\Espaces;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Espaces Ministère / ANAB : compte connecté sur la garde de l'espace,
 * toujours actif, moins de N minutes d'inactivité. « espace:admin »
 * réserve en plus la route aux administrateurs de l'espace.
 *
 * Différence voulue : l'ancien code ne vérifiait qu'un drapeau de session
 * posé à la connexion (ni compte désactivé entre-temps, ni expiration —
 * la session ANAB durait 24 h).
 */
class EspaceConnecte
{
    public function handle(Request $request, Closure $next, ?string $niveau = null): Response
    {
        $code = Espaces::courant($request) ?? abort(404);
        $garde = Auth::guard(Espaces::LISTE[$code]['garde']);
        $compte = $garde->user();
        $session = $request->session();
        $cle = "{$code}_activite";
        $expire = time() - (int) $session->get($cle, 0) > config('gesnotes.inactivite_minutes') * 60;

        if (! $compte || ! $compte->actif || $expire) {
            if ($compte) {
                $garde->logout();
                $session->forget($cle);
                $session->regenerate();
            }

            return redirect()->route("$code.connexion")->with('erreur', $compte && $expire ? 'Session expirée : reconnectez-vous.' : 'Veuillez vous connecter.');
        }
        if ($niveau === 'admin' && ! $compte->estAdmin()) {
            return redirect()->route("$code.accueil")->with('erreur', 'Réservé aux administrateurs.');
        }
        $session->put($cle, time());

        return $next($request);
    }
}
