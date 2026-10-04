<?php

// Module « Finances » (étape 7). Chargé dans le groupe auth + licence de
// routes/web.php.

use App\Http\Controllers\Finances\FinancesController;
use App\Http\Controllers\Finances\PaiementsController;
use App\Http\Controllers\Finances\PaiementsEnLigneController;
use App\Http\Controllers\Finances\RecuInscriptionController;
use App\Http\Controllers\Finances\RecuPaiementController;
use App\Http\Controllers\Finances\TarifsNiveauxController;
use Illuminate\Support\Facades\Route;

Route::get('/paiements', [PaiementsController::class, 'index'])->middleware('page:paiements')->name('paiements');
Route::middleware(['page:paiements', 'permission:paiements_gerer'])->group(function () {
    Route::post('/paiements', [PaiementsController::class, 'enregistrer'])->name('paiements.enregistrer');
    Route::post('/paiements/annuler', [PaiementsController::class, 'annuler'])->name('paiements.annuler');
});
// Reçu : rôles et permission vérifiés dans le contrôleur (l'étudiant y accède pour lui-même)
Route::get('/paiements/recu', RecuPaiementController::class)->name('recu_paiement');
Route::get('/inscriptions/recu', RecuInscriptionController::class)->name('recu_inscription');

Route::get('/finances', [FinancesController::class, 'index'])->middleware('page:finances')->name('finances');
Route::middleware(['page:finances', 'permission:paiements_gerer'])->controller(FinancesController::class)->group(function () {
    Route::post('/finances/tarifs', 'enregistrerTarif')->name('finances.tarif');
    Route::post('/finances/tarifs/supprimer', 'supprimerTarif')->name('finances.tarif.supprimer');
    Route::post('/finances/depenses/categories', 'ajouterCategorie')->name('finances.categorie');
    Route::post('/finances/depenses', 'enregistrerDepense')->name('finances.depense');
    Route::post('/finances/depenses/supprimer', 'supprimerDepense')->name('finances.depense.supprimer');
});

Route::get('/paiements/en-ligne', PaiementsEnLigneController::class)->middleware('page:paiements_en_ligne')->name('paiements_en_ligne');
Route::get('/tarifs-inscription', [TarifsNiveauxController::class, 'index'])->middleware('page:tarifs_niveaux')->name('tarifs_niveaux');
Route::post('/tarifs-inscription', [TarifsNiveauxController::class, 'enregistrer'])->middleware('page:tarifs_niveaux')->name('tarifs_niveaux.enregistrer');

// Litiges KomiPay (ancien requireAuth('admin')) : paiements en ligne sans confirmation
Route::middleware(['page:komipay_litiges', 'roles:admin'])->controller(\App\Http\Controllers\Finances\KomipayLitigesController::class)->group(function () {
    Route::get('/paiements/litiges-komipay', 'index')->name('komipay_litiges');
    Route::post('/paiements/litiges-komipay/verifier', 'reverifier')->name('komipay_litiges.reverifier');
    Route::post('/paiements/litiges-komipay/confirmer', 'confirmer')->name('komipay_litiges.confirmer');
    Route::post('/paiements/litiges-komipay/rejeter', 'rejeter')->name('komipay_litiges.rejeter');
});
