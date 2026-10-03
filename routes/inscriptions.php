<?php

// Module « Inscriptions » (étape 4). Chargé dans le groupe auth + licence
// de routes/web.php.

use App\Http\Controllers\Inscriptions\ChoixOptionsController;
use App\Http\Controllers\Inscriptions\InscriptionPedMasseController;
use App\Http\Controllers\Inscriptions\InscriptionScolariteController;
use App\Http\Controllers\Inscriptions\PassageNiveauController;
use App\Http\Controllers\Inscriptions\TransfertNiveauController;
use Illuminate\Support\Facades\Route;

Route::middleware(['page:inscription_scolarite', 'roles:admin,secretaire,sc_centrale,sc_entite'])->controller(InscriptionScolariteController::class)->group(function () {
    Route::get('/inscriptions/scolarite', 'index')->name('inscription_scolarite');
    Route::post('/inscriptions/scolarite', 'inscrire')->name('inscription_scolarite.inscrire');
    Route::post('/inscriptions/scolarite/modifier', 'modifier')->name('inscription_scolarite.modifier');
});

Route::middleware('page:inscription_ped_masse')->controller(InscriptionPedMasseController::class)->group(function () {
    Route::get('/inscriptions/pedagogique/masse', 'index')->name('inscription_ped_masse');
    Route::post('/inscriptions/pedagogique/masse', 'enregistrer')->name('inscription_ped_masse.enregistrer');
});

// Ancien contrôle hasRole('admin') || hasRole('sc_entite') (hiérarchique)
Route::middleware(['page:choix_options', 'roles:admin,secretaire,sc_entite'])->controller(ChoixOptionsController::class)->group(function () {
    Route::get('/inscriptions/options', 'index')->name('choix_options');
    Route::post('/inscriptions/options', 'enregistrer')->name('choix_options.enregistrer');
});

Route::middleware(['page:transfert_niveau', 'roles:admin,secretaire,sc_centrale,sc_entite'])->controller(TransfertNiveauController::class)->group(function () {
    Route::get('/inscriptions/transfert', 'index')->name('transfert_niveau');
    Route::post('/inscriptions/transfert/passage', 'passage')->name('transfert_niveau.passage');
    Route::post('/inscriptions/transfert', 'transferer')->name('transfert_niveau.transferer');
});

Route::middleware('page:passage_niveau')->controller(PassageNiveauController::class)->group(function () {
    Route::get('/inscriptions/passage', 'index')->name('passage_niveau');
    Route::post('/inscriptions/passage', 'enregistrer')->name('passage_niveau.enregistrer');
});
