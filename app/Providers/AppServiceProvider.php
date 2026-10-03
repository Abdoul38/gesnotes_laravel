<?php

namespace App\Providers;

use App\Services\Licence;
use App\Services\Parametres;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegistreUniversites::class, fn ($app) => new RegistreUniversites($app['config']['universites']));

        // « scoped » : remis à zéro à chaque requête (et entre deux tests)
        $this->app->scoped(UniversiteCourante::class);
        $this->app->scoped(Parametres::class);
        $this->app->scoped(\App\Services\Communication\Courriel::class);
        $this->app->scoped(Licence::class, fn ($app) => new Licence(
            (string) $app['config']['licence.secret'],
            $app->make(UniversiteCourante::class),
        ));
    }

    public function boot(): void
    {
        // Hors requête web (Artisan, tests) : université par défaut.
        // En web, c'est le middleware ResoudreUniversite qui décide.
        if ($this->app->runningInConsole()) {
            $this->app->make(UniversiteCourante::class)
                ->definir($this->app->make(RegistreUniversites::class)->parDefaut());
        }
    }
}
