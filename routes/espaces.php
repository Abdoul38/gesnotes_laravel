<?php

// Espaces nationaux (étape 12) : Ministère sur ministere.<domaine>, ANAB
// sur anab.<domaine>. ResoudreUniversite ne sert sur chaque sous-domaine
// que les routes de son espace (préfixe du nom : ministere.*, anab.*).

use App\Http\Controllers\Espaces\AnabController;
use App\Http\Controllers\Espaces\ComptesEspaceController;
use App\Http\Controllers\Espaces\ConnexionEspaceController;
use App\Http\Controllers\Espaces\MinistereController;
use Illuminate\Support\Facades\Route;

foreach (['ministere', 'anab'] as $espace) {
    Route::prefix($espace)->name("$espace.")->group(function () use ($espace) {
        Route::get('/connexion', [ConnexionEspaceController::class, 'formulaire'])->name('connexion');
        Route::post('/connexion', [ConnexionEspaceController::class, 'connecter'])->middleware('throttle:30,1')->name('connexion.envoyer');

        Route::middleware('espace')->group(function () use ($espace) {
            Route::post('/deconnexion', [ConnexionEspaceController::class, 'deconnecter'])->name('deconnexion');
            Route::get('/mot-de-passe', [ConnexionEspaceController::class, 'formulaireMotDePasse'])->name('mot_de_passe');
            Route::post('/mot-de-passe', [ConnexionEspaceController::class, 'changerMotDePasse'])->name('mot_de_passe.envoyer');

            if ($espace === 'ministere') {
                Route::get('/', [MinistereController::class, 'index'])->name('accueil');
                Route::get('/export', [MinistereController::class, 'excel'])->name('excel');
                Route::get('/rapport', [MinistereController::class, 'rapport'])->name('rapport');
                Route::get('/pv/{code}/{id}/imprimer', [MinistereController::class, 'impressionPv'])->whereAlpha('code')->whereNumber('id')->name('pv.imprimer');
            } else {
                Route::get('/', [AnabController::class, 'index'])->name('accueil');
            }
        });

        Route::middleware('espace:admin')->controller(ComptesEspaceController::class)->group(function () {
            Route::get('/comptes', 'index')->name('comptes');
            Route::post('/comptes', 'creer')->name('comptes.creer');
            Route::post('/comptes/basculer', 'basculer')->name('comptes.basculer');
            Route::post('/comptes/mot-de-passe', 'reinitialiser')->name('comptes.reinitialiser');
        });
    });
}
