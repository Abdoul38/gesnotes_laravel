<?php

use App\Http\Controllers\AccueilController;
use App\Http\Controllers\Administration\LicenceController;
use App\Http\Controllers\AnciennesAdressesController;
use App\Http\Controllers\Auth\ConnexionController;
use App\Http\Controllers\Auth\MotDePasseInitialController;
use App\Http\Controllers\Auth\MotDePasseOublieController;
use App\Http\Controllers\Documents\VerifierDocumentController;
use App\Http\Controllers\FichierController;
use App\Http\Controllers\Portails\ConnexionParentController;
use App\Http\Controllers\Portails\PortailParentController;
use Illuminate\Support\Facades\Route;

// Les noms de route reprennent les noms de page de l'ancienne application
// (?page=accueil → route « accueil ») : le menu s'en sert pour savoir si
// une page est déjà migrée (voir App\Navigation\Menu).

// Racine et anciennes adresses (index.php?page=…, portails, fichiers) — étape 13
Route::get('/', [AnciennesAdressesController::class, 'routeur'])->name('anciennes.routeur');
Route::get('/index.php', [AnciennesAdressesController::class, 'routeur'])->name('anciennes.index');
Route::get('/candidature/index.php', [AnciennesAdressesController::class, 'candidature'])->name('anciennes.candidature');
Route::get('/portail2/index.php', [AnciennesAdressesController::class, 'candidature'])->name('anciennes.portail2');
Route::get('/uploads/{dossier}/{fichier}', [AnciennesAdressesController::class, 'fichier'])->whereIn('dossier', ['logos', 'photos'])->name('anciennes.fichier');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [ConnexionController::class, 'formulaire'])->name('login');
    Route::post('/connexion', [ConnexionController::class, 'connecter'])->name('login.envoyer');
    Route::get('/mot-de-passe-oublie', [MotDePasseOublieController::class, 'formulaire'])->name('mot_de_passe_oublie');
    Route::post('/mot-de-passe-oublie', [MotDePasseOublieController::class, 'envoyer'])->name('mot_de_passe_oublie.envoyer');
    Route::get('/reinitialiser-mot-de-passe', [MotDePasseOublieController::class, 'formulaireReinitialisation'])->name('reinitialiser_mot_de_passe');
    Route::post('/reinitialiser-mot-de-passe', [MotDePasseOublieController::class, 'reinitialiser'])->name('reinitialiser_mot_de_passe.envoyer');
});

// Vérification publique des documents (cible des QR codes) : sans connexion.
Route::get('/verification', VerifierDocumentController::class)->name('verifier_diplome');

// Espace Parents : comptes et session distincts du personnel (garde « parent »)
Route::get('/parents/connexion', [ConnexionParentController::class, 'formulaire'])->name('login_parent');
Route::post('/parents/connexion', [ConnexionParentController::class, 'connecter'])->name('login_parent.envoyer');
Route::middleware(['parent', 'licence'])->group(function () {
    Route::get('/parents', [PortailParentController::class, 'index'])->name('portail_parent');
    Route::post('/parents/compte', [PortailParentController::class, 'compte'])->name('portail_parent.compte');
    Route::post('/parents/deconnexion', [ConnexionParentController::class, 'deconnecter'])->name('logout_parent');
});

Route::get('/fichiers/logos/{fichier}', [FichierController::class, 'logo'])->name('fichiers.logo');

require __DIR__.'/candidature.php';
require __DIR__.'/espaces.php';

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [ConnexionController::class, 'deconnecter'])->name('logout');

    // Premier mot de passe d'un étudiant (provisoire ou égal au matricule)
    Route::get('/mot-de-passe-initial', [MotDePasseInitialController::class, 'formulaire'])->name('mot_de_passe_initial');
    Route::post('/mot-de-passe-initial', [MotDePasseInitialController::class, 'enregistrer'])->name('mot_de_passe_initial.envoyer');

    // Licence : hors du contrôle de licence, puisque c'est là qu'on la corrige.
    Route::middleware('roles:admin')->group(function () {
        Route::get('/licence', [LicenceController::class, 'index'])->name('parametres_licence');
        Route::post('/licence', [LicenceController::class, 'activer'])->name('parametres_licence.activer');
    });

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
        require __DIR__.'/communication.php';
        require __DIR__.'/portails.php';
        require __DIR__.'/rapports.php';
        require __DIR__.'/administration.php';
    });
});
