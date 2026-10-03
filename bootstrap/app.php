<?php

use App\Http\Middleware\EntetesSecurite;
use App\Http\Middleware\ExigerPermission;
use App\Http\Middleware\ExigerPermissionPage;
use App\Http\Middleware\ExigerRoles;
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
        // API mobile : université résolue par le sous-domaine, sans session ni CSRF
        then: fn () => Illuminate\Support\Facades\Route::middleware([ResoudreUniversite::class, 'throttle:120,1'])
            ->group(__DIR__.'/../routes/api_mobile.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ordre : université (choix de la base) → session → contrôles de
        // session (université, inactivité, cantonnement), avant tout
        // chargement de l'utilisateur par les middlewares de route.
        $middleware->web(prepend: [ResoudreUniversite::class], append: [SessionActive::class]);
        $middleware->append(EntetesSecurite::class);

        $middleware->alias([
            'permission' => ExigerPermission::class,
            'page' => ExigerPermissionPage::class,
            'licence' => VerifierLicence::class,
            'roles' => ExigerRoles::class,
            'parent' => \App\Http\Middleware\ParentConnecte::class,
            'candidat' => \App\Http\Middleware\CandidatConnecte::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('accueil'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
