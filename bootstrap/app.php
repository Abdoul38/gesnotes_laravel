<?php

use App\Http\Middleware\CandidatConnecte;
use App\Http\Middleware\EntetesSecurite;
use App\Http\Middleware\EspaceConnecte;
use App\Http\Middleware\ExigerPermission;
use App\Http\Middleware\ExigerPermissionPage;
use App\Http\Middleware\ExigerRoles;
use App\Http\Middleware\InvaliderIndicateurs;
use App\Http\Middleware\ParentConnecte;
use App\Http\Middleware\ResoudreUniversite;
use App\Http\Middleware\SessionActive;
use App\Http\Middleware\VerifierLicence;
use App\Services\AlerteErreur;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // API mobile : université résolue par le sous-domaine, sans session ni CSRF
        then: fn () => Route::middleware([ResoudreUniversite::class, 'throttle:120,1', InvaliderIndicateurs::class])
            ->group(__DIR__.'/../routes/api_mobile.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ordre : université (choix de la base) → session → contrôles de
        // session (université, inactivité, cantonnement), avant tout
        // chargement de l'utilisateur par les middlewares de route.
        $middleware->web(prepend: [ResoudreUniversite::class], append: [SessionActive::class, InvaliderIndicateurs::class]);
        $middleware->append(EntetesSecurite::class);

        $middleware->alias([
            'permission' => ExigerPermission::class,
            'page' => ExigerPermissionPage::class,
            'licence' => VerifierLicence::class,
            'roles' => ExigerRoles::class,
            'parent' => ParentConnecte::class,
            'candidat' => CandidatConnecte::class,
            'espace' => EspaceConnecte::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('accueil'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Erreur serveur : alerte par e-mail à l'administrateur (au plus une fois par heure pour la même erreur)
        $exceptions->report(function (Throwable $e) {
            app(AlerteErreur::class)->signaler($e);
        });
    })->create();
