<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fermeture de la session du personnel (déconnexion, expiration, changement
 * d'université) sans fermer les espaces qui partagent le même cookie :
 * Espace Parents et portail candidats gardent leur connexion.
 *
 * Différence voulue : l'ancienne déconnexion (session_destroy) fermait aussi
 * l'Espace Parents ouvert dans le même navigateur.
 */
final class SessionPersonnel
{
    public static function fermer(Request $request): void
    {
        $session = $request->session();
        $cles = [Auth::guard('parent')->getName(), Auth::guard('candidat')->getName(),
            'parent_universite', 'parent_activite', 'candidat_universite', 'candidat_activite'];
        $autresEspaces = array_filter($session->only($cles), fn ($v) => $v !== null);

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->put($autresEspaces);
        $session->regenerateToken();
    }
}
