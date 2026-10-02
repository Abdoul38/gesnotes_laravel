<?php

// Module « Structure académique » (étape 2). Chargé dans le groupe
// auth + licence de routes/web.php. Chaque page garde la permission de
// son entrée de menu (page:…) et les rôles que l'ancienne page exigeait.

use App\Http\Controllers\Structure\AnneesController;
use App\Http\Controllers\Structure\NiveauxController;
use Illuminate\Support\Facades\Route;

Route::middleware(['page:annees', 'roles:admin,secretaire,sc_centrale'])->group(function () {
    Route::get('/annees', [AnneesController::class, 'index'])->name('annees');
    Route::post('/annees', [AnneesController::class, 'creer'])->name('annees.creer');
    Route::post('/annees/activer', [AnneesController::class, 'activer'])->name('annees.activer');
    Route::post('/annees/supprimer', [AnneesController::class, 'supprimer'])->name('annees.supprimer');
});

Route::middleware('page:niveaux')->group(function () {
    Route::get('/niveaux', [NiveauxController::class, 'index'])->name('niveaux');
    Route::post('/niveaux', [NiveauxController::class, 'enregistrer'])->name('niveaux.enregistrer');
    Route::post('/niveaux/supprimer', [NiveauxController::class, 'supprimer'])->name('niveaux.supprimer');
});
