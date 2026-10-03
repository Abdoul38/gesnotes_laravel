<?php

// Module « Inscriptions » (étape 4). Chargé dans le groupe auth + licence
// de routes/web.php.

use App\Http\Controllers\Inscriptions\InscriptionScolariteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['page:inscription_scolarite', 'roles:admin,secretaire,sc_centrale,sc_entite'])->controller(InscriptionScolariteController::class)->group(function () {
    Route::get('/inscriptions/scolarite', 'index')->name('inscription_scolarite');
    Route::post('/inscriptions/scolarite', 'inscrire')->name('inscription_scolarite.inscrire');
    Route::post('/inscriptions/scolarite/modifier', 'modifier')->name('inscription_scolarite.modifier');
});
