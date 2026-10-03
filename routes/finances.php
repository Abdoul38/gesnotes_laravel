<?php

// Module « Finances » (étape 7). Chargé dans le groupe auth + licence de
// routes/web.php.

use App\Http\Controllers\Finances\PaiementsController;
use App\Http\Controllers\Finances\RecuPaiementController;
use Illuminate\Support\Facades\Route;

Route::get('/paiements', [PaiementsController::class, 'index'])->middleware('page:paiements')->name('paiements');
Route::middleware(['page:paiements', 'permission:paiements_gerer'])->group(function () {
    Route::post('/paiements', [PaiementsController::class, 'enregistrer'])->name('paiements.enregistrer');
    Route::post('/paiements/supprimer', [PaiementsController::class, 'supprimer'])->name('paiements.supprimer');
});
// Reçu : rôles et permission vérifiés dans le contrôleur (l'étudiant y accède pour lui-même)
Route::get('/paiements/recu', RecuPaiementController::class)->name('recu_paiement');
