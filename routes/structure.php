<?php

// Module « Structure académique » (étape 2). Chargé dans le groupe
// auth + licence de routes/web.php. Chaque page garde la permission de
// son entrée de menu (page:…) et les rôles que l'ancienne page exigeait.

use App\Http\Controllers\Structure\AnneesController;
use App\Http\Controllers\Structure\FacultesController;
use App\Http\Controllers\Structure\NiveauxController;
use App\Http\Controllers\Structure\PromotionsController;
use App\Http\Controllers\Structure\SallesController;
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

Route::middleware('page:facultes')->group(function () {
    Route::get('/facultes', [FacultesController::class, 'index'])->name('facultes');
    Route::post('/facultes', [FacultesController::class, 'enregistrer'])->name('facultes.enregistrer');
    Route::post('/facultes/supprimer', [FacultesController::class, 'supprimer'])->name('facultes.supprimer');
    Route::post('/facultes/basculer', [FacultesController::class, 'basculer'])->name('facultes.basculer');
});

// Ancienne page : permission du menu + permission propre salles_gerer
Route::middleware(['page:salles', 'permission:salles_gerer'])->group(function () {
    Route::get('/salles', [SallesController::class, 'index'])->name('salles');
    Route::post('/salles', [SallesController::class, 'enregistrer'])->name('salles.enregistrer');
    Route::post('/salles/basculer', [SallesController::class, 'basculer'])->name('salles.basculer');
    Route::post('/salles/supprimer', [SallesController::class, 'supprimer'])->name('salles.supprimer');
});

Route::middleware('page:promotions')->group(function () {
    Route::get('/promotions', [PromotionsController::class, 'index'])->name('promotions');
    Route::post('/promotions/generer', [PromotionsController::class, 'generer'])->name('promotions.generer');
    Route::post('/promotions/copier', [PromotionsController::class, 'copier'])->name('promotions.copier');
    Route::post('/promotions/supprimer', [PromotionsController::class, 'supprimer'])->name('promotions.supprimer');
});
