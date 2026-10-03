<?php

// Portail candidats (étape 10) : comptes et session distincts du personnel
// (garde « candidat »). Chargé hors du groupe auth de routes/web.php.

use App\Http\Controllers\Candidature\ConnexionCandidatController;
use App\Http\Controllers\Candidature\DepotController;
use App\Http\Controllers\Candidature\EspaceCandidatController;
use App\Http\Controllers\Candidature\InscriptionEnLigneController;
use Illuminate\Support\Facades\Route;

Route::prefix('candidature')->middleware('licence')->group(function () {
    Route::controller(ConnexionCandidatController::class)->group(function () {
        Route::get('/', 'accueil')->name('candidature');
        Route::get('/connexion', 'formulaireConnexion')->name('candidature.connexion');
        Route::post('/connexion', 'connecter')->name('candidature.connexion.envoyer');
        Route::get('/compte', 'formulaireCompte')->name('candidature.compte');
        Route::post('/compte', 'creerCompte')->middleware('throttle:10,60')->name('candidature.compte.creer');
        Route::get('/images/slide-{n}.jpg', 'slide')->whereNumber('n')->name('candidature.slide');
    });

    // Inscription académique en ligne : sans compte (matricule ou n° de dossier), paiement KomiPay
    Route::controller(InscriptionEnLigneController::class)->group(function () {
        Route::get('/inscription', 'formulaire')->name('candidature.inscription');
        Route::post('/inscription', 'identifier')->middleware('throttle:30,10')->name('candidature.inscription.identifier');
        Route::get('/inscription/{id}/etape/{etape}', 'etape')->whereNumber(['id', 'etape'])->name('candidature.inscription.etape');
        Route::post('/inscription/{id}/paiement', 'payer')->whereNumber('id')->name('candidature.inscription.payer');
        Route::get('/inscription/{id}/carte', 'carte')->whereNumber('id')->name('candidature.inscription.carte');
        Route::post('/inscription/{id}/carte', 'payerCarte')->whereNumber('id')->middleware('throttle:5,10')->name('candidature.inscription.carte.payer');
        Route::get('/inscription/{id}/statut', 'statut')->whereNumber('id')->middleware('throttle:30,1')->name('candidature.inscription.statut');
        Route::post('/inscription/{id}/confirmation', 'confirmer')->whereNumber('id')->name('candidature.inscription.confirmer');
        Route::get('/inscription/{id}/recu', 'recu')->whereNumber('id')->name('candidature.inscription.recu');
    });

    Route::middleware('candidat')->group(function () {
        Route::post('/deconnexion', [ConnexionCandidatController::class, 'deconnecter'])->name('candidature.deconnexion');
        Route::controller(EspaceCandidatController::class)->group(function () {
            Route::get('/tableau-de-bord', 'tableau')->name('candidature.tableau');
            Route::get('/dossiers', 'dossiers')->name('candidature.dossiers');
            Route::get('/profil', 'profil')->name('candidature.profil');
            Route::post('/profil', 'majProfil')->name('candidature.profil.maj');
            Route::get('/dossiers/{id}/pieces/{type}', 'piece')->whereNumber('id')->whereAlpha('type')->name('candidature.piece');
        });
        Route::controller(DepotController::class)->group(function () {
            Route::post('/dossiers', 'nouveau')->name('candidature.nouveau');
            Route::get('/dossiers/{id}/etape/{etape}', 'etape')->whereNumber(['id', 'etape'])->name('candidature.depot');
            Route::post('/dossiers/{id}/etape/{etape}', 'enregistrer')->whereNumber(['id', 'etape'])->name('candidature.depot.enregistrer');
            Route::post('/dossiers/{id}/soumettre', 'soumettre')->whereNumber('id')->name('candidature.soumettre');
            Route::get('/dossiers/{id}/soumis', 'succes')->whereNumber('id')->name('candidature.succes');
            Route::get('/dossiers/{id}/quitus', 'quitus')->whereNumber('id')->name('candidature.quitus');
        });
    });
});
