<?php

// Module « Étudiants et enseignants » (étape 3). Chargé dans le groupe
// auth + licence de routes/web.php.

use App\Http\Controllers\Etudiants\EtudiantsController;
use App\Http\Controllers\Etudiants\FicheEtudiantController;
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
