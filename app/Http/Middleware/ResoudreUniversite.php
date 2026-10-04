<?php

namespace App\Http\Middleware;

use App\Espaces\Espaces;
use App\Universites\RegistreUniversites;
use App\Universites\Universite;
use App\Universites\UniversiteCourante;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Choisit l'université d'après le sous-domaine et branche sa base.
 * Domaine nu → page de présentation.
 */
class ResoudreUniversite
{
    public function __construct(
        private readonly RegistreUniversites $registre,
        private readonly UniversiteCourante $courante,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $universite = $this->registre->resoudre($request->getHost(), $request->query('uni'));

        if ($universite === null) {
            return response()->view('presentation', ['universites' => $this->registre->actives()]);
        }

        if (! $universite->estUniversite()) {
            return $this->espace($request, $next, $universite);
        }
        // Les routes des espaces nationaux n'existent pas sur le domaine d'une université
        if ($request->routeIs(...array_map(fn ($e) => $e.'.*', array_keys(Espaces::LISTE)))) {
            abort(404);
        }

        $this->courante->definir($universite);
        View::share('universite', $universite);

        return $next($request);
    }

    /**
     * Ministère et ANAB : seules leurs routes sont servies sur leur
     * sous-domaine (toute autre adresse ramène à leur accueil). La base
     * branchée est celle qui contient leurs comptes ; les tableaux de bord
     * lisent ensuite chaque université tour à tour.
     */
    private function espace(Request $request, Closure $next, Universite $espace): Response
    {
        if (! isset(Espaces::LISTE[$espace->espace])) {
            abort(404);
        }
        $comptes = $this->registre->trouver((string) config('gesnotes.espaces_comptes')) ?? $this->registre->parDefaut();
        $this->courante->definir($comptes);
        $request->attributes->set('espace', $espace->espace);
        View::share('espace', Espaces::config($espace->espace));

        if (! $request->routeIs($espace->espace.'.*')) {
            return redirect()->route($espace->espace.'.accueil');
        }

        return $next($request);
    }
}
