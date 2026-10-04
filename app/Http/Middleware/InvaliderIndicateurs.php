<?php

namespace App\Http\Middleware;

use App\Espaces\Espaces;
use App\Services\IndicateursRectorat;
use App\Universites\UniversiteCourante;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Après toute écriture réussie dans une université (formulaire envoyé :
 * notes, heures, PV, inscriptions, passages…), périme les indicateurs en
 * cache du rectorat et du ministère pour cette université. Plus simple et
 * plus sûr que d'y penser dans chaque page qui modifie des données.
 */
class InvaliderIndicateurs
{
    public function __construct(private readonly UniversiteCourante $universite, private readonly IndicateursRectorat $indicateurs) {}

    public function handle(Request $request, Closure $next): Response
    {
        $reponse = $next($request);
        if (! $request->isMethodSafe() && $reponse->getStatusCode() < 400 && $this->universite->estDefinie() && ! Espaces::courant($request)) {
            $this->indicateurs->invalider();
        }

        return $reponse;
    }
}
