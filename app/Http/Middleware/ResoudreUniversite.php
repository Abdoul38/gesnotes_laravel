<?php

namespace App\Http\Middleware;

use App\Universites\RegistreUniversites;
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
            // Ministère et ANAB : migrés à l'étape 12 du plan (voir INVENTAIRE.md)
            abort(404, "L'espace {$universite->sigle} n'est pas encore disponible dans cette version.");
        }

        $this->courante->definir($universite);
        View::share('universite', $universite);

        return $next($request);
    }
}
