<?php

// Module « Notes et PV » (étape 5). Chargé dans le groupe auth + licence
// de routes/web.php.

use App\Http\Controllers\Notes\NotesImportController;
use App\Http\Controllers\Notes\PvController;
use App\Http\Controllers\Notes\PvCreationController;
use App\Http\Controllers\Notes\PvDetailController;
use App\Http\Controllers\Notes\SaisieNotesController;
use Illuminate\Support\Facades\Route;

Route::middleware('page:pv')->controller(PvController::class)->group(function () {
    Route::get('/pv', 'index')->name('pv');
    Route::post('/pv/statut', 'statut')->name('pv.statut');
    Route::post('/pv/titre', 'titre')->name('pv.titre');
});

Route::middleware(['page:pv_creer', 'roles:admin,secretaire,sc_centrale,sc_entite'])->controller(PvCreationController::class)->group(function () {
    Route::get('/pv/nouveau', 'formulaire')->name('pv_creer');
    Route::post('/pv/nouveau', 'enregistrer')->name('pv_creer.enregistrer');
});

Route::middleware('page:pv_detail')->controller(PvDetailController::class)->group(function () {
    Route::get('/pv/{id}', 'afficher')->whereNumber('id')->name('pv_detail');
    Route::post('/pv/{id}/notes', 'sauvegarder')->whereNumber('id')->name('pv_detail.notes');
    Route::post('/pv/{id}/simuler', 'simuler')->whereNumber('id')->name('pv_detail.simuler');
    Route::post('/pv/{id}/action', 'action')->whereNumber('id')->name('pv_detail.action');
    Route::post('/pv/{id}/recalcul', 'file')->whereNumber('id')->name('pv_detail.file');
});

Route::get('/notes', SaisieNotesController::class)->middleware('page:notes')->name('notes');

// Import des notes : droits fins (rôle, faculté, matière affectée) vérifiés dans le contrôleur
Route::middleware('page:notes_importer')->controller(NotesImportController::class)->group(function () {
    Route::get('/notes/import', 'index')->name('notes_importer');
    Route::get('/notes/import/modele', 'modele')->name('notes_importer.modele');
    Route::post('/notes/import/matiere', 'importerMatiere')->name('notes_importer.matiere');
    Route::post('/notes/import/pv', 'importerPv')->name('notes_importer.pv');
});
