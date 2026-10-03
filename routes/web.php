<?php

use App\Http\Controllers\AccueilController;
use App\Http\Controllers\Auth\ConnexionController;
use App\Http\Controllers\Documents\VerifierDocumentController;
use App\Http\Controllers\FichierController;
use Illuminate\Support\Facades\Route;

// Les noms de route reprennent les noms de page de l'ancienne application
// (?page=accueil → route « accueil ») : le menu s'en sert pour savoir si
// une page est déjà migrée (voir App\Navigation\Menu).

Route::redirect('/', '/accueil');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [ConnexionController::class, 'formulaire'])->name('login');
    Route::post('/connexion', [ConnexionController::class, 'connecter'])->name('login.envoyer');
});

// Vérification publique des documents (cible des QR codes) : sans connexion.
Route::get('/verification', VerifierDocumentController::class)->name('verifier_diplome');

Route::get('/fichiers/logos/{fichier}', [FichierController::class, 'logo'])->name('fichiers.logo');

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [ConnexionController::class, 'deconnecter'])->name('logout');

    Route::middleware('licence')->group(function () {
        // Accueil : sans permission, comme dans l'ancien routeur (sinon un
        // refus de permission, qui ramène à l'accueil, tournerait en boucle).
        Route::get('/accueil', AccueilController::class)->name('accueil');

        require __DIR__.'/structure.php';
        require __DIR__.'/etudiants.php';
        require __DIR__.'/inscriptions.php';
        require __DIR__.'/notes.php';
        require __DIR__.'/documents.php';
        require __DIR__.'/finances.php';
    });
});
