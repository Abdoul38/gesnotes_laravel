<?php

namespace App\Http\Middleware;

use App\Universites\UniversiteCourante;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portail candidats : candidat connecté (garde « candidat »), session de la
 * même université, compte actif, moins de N minutes d'inactivité.
 *
 * Différence voulue : l'ancien portail ne vérifiait que la présence de
 * cand_id dans une session PARTAGÉE avec GesNotes (ni expiration, ni compte
 * désactivé entre-temps, ni université).
 */
class CandidatConnecte
{
    public function __construct(private readonly UniversiteCourante $universite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $garde = Auth::guard('candidat');
        $candidat = $garde->user();
        $session = $request->session();
        $expire = time() - (int) $session->get('candidat_activite', 0) > config('gesnotes.inactivite_minutes') * 60;
        if (! $candidat || ! $candidat->actif || $session->get('candidat_universite') !== $this->universite->get()->code || $expire) {
            if ($candidat) {
                $garde->logout();
                $session->forget(['candidat_universite', 'candidat_activite']);
            }

            return redirect()->route('candidature.connexion', $candidat && $expire ? ['expired' => 1] : [])
                ->with('erreur', $candidat && $expire ? 'Session expirée : reconnectez-vous.' : 'Veuillez vous connecter.');
        }
        $session->put('candidat_activite', time());

        return $next($request);
    }
}
