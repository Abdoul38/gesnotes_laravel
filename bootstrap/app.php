<?php

use App\Http\Middleware\EntetesSecurite;
use App\Http\Middleware\ExigerPermission;
use App\Http\Middleware\ResoudreUniversite;
use App\Http\Middleware\SessionActive;
use App\Http\Middleware\VerifierLicence;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ordre : université (choix de la base) → session → contrôles de
        // session (université, inactivité, cantonnement), avant tout
        // chargement de l'utilisateur par les middlewares de route.
        $middleware->web(prepend: [ResoudreUniversite::class], append: [SessionActive::class]);
        $middleware->append(EntetesSecurite::class);

        $middleware->alias([
            'permission' => ExigerPermission::class,
            'licence' => VerifierLicence::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('accueil'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
