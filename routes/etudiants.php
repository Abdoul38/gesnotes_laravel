<?php

// Module « Étudiants et enseignants » (étape 3). Chargé dans le groupe
// auth + licence de routes/web.php.

use App\Http\Controllers\Etudiants\CartesController;
use App\Http\Controllers\Etudiants\DossierEtudiantController;
use App\Http\Controllers\Etudiants\EtudiantsController;
use App\Http\Controllers\Etudiants\FicheEtudiantController;
use App\Http\Controllers\Etudiants\ImportPhotosController;
use App\Http\Controllers\FichierController;
use Illuminate\Support\Facades\Route;

Route::middleware('page:etudiants')->group(function () {
    Route::get('/etudiants', [EtudiantsController::class, 'index'])->name('etudiants');
    Route::post('/etudiants/supprimer', [EtudiantsController::class, 'supprimer'])->name('etudiants.supprimer');
    // Photos : données personnelles, même permission que la liste
    Route::get('/fichiers/photos/{fichier}', [FichierController::class, 'photo'])->name('fichiers.photo');
});

Route::middleware('page:etudiant_form')->group(function () {
    Route::get('/etudiants/fiche', [FicheEtudiantController::class, 'formulaire'])->name('etudiant_form');
    Route::post('/etudiants/fiche', [FicheEtudiantController::class, 'enregistrer'])->name('etudiant_form.enregistrer');
});

Route::get('/api/etudiants', [EtudiantsController::class, 'rechercher'])->middleware('page:api_etudiants')->name('api_etudiants');
Route::get('/api/etudiants/recherche', [EtudiantsController::class, 'rechercherCourt'])->middleware('page:api_recherche_etudiants')->name('api_recherche_etudiants');

Route::middleware('page:dossier_etudiant')->controller(DossierEtudiantController::class)->group(function () {
    Route::get('/etudiants/dossier', 'afficher')->name('dossier_etudiant');
    Route::post('/etudiants/dossier', 'modifier')->name('dossier_etudiant.modifier');
    Route::post('/etudiants/dossier/documents', 'ajouterDocument')->name('dossier_etudiant.ajouter_document');
    Route::post('/etudiants/dossier/documents/valider', 'validerDocument')->name('dossier_etudiant.valider_document');
    Route::post('/etudiants/dossier/documents/supprimer', 'supprimerDocument')->name('dossier_etudiant.supprimer_document');
    // Pièces personnelles : même permission que le dossier
    Route::get('/fichiers/documents/{id}', [FichierController::class, 'document'])->whereNumber('id')->name('fichiers.document');
    Route::get('/fichiers/pieces/{fichier}', [FichierController::class, 'pieceCandidat'])->name('fichiers.piece');
});

// Cartes d'étudiant : l'ancienne page exigeait en plus l'un de ces rôles
Route::middleware(['page:carte_etudiant', 'roles:admin,secretaire,sc_centrale,sc_entite'])->controller(CartesController::class)->group(function () {
    Route::get('/etudiants/cartes', 'selection')->name('carte_etudiant');
    Route::get('/etudiants/cartes/imprimer', 'imprimer')->name('carte_etudiant.imprimer');
});

Route::middleware('page:import_photos')->controller(ImportPhotosController::class)->group(function () {
    Route::get('/etudiants/photos/import', 'formulaire')->name('import_photos');
    Route::post('/etudiants/photos/import', 'importer')->name('import_photos.importer');
});

// Cachet et signature : ne sont plus servis publiquement, seulement aux utilisateurs connectés
Route::get('/fichiers/officiels/{type}', [FichierController::class, 'officiel'])->whereIn('type', ['cachet', 'signature'])->name('fichiers.officiel');
