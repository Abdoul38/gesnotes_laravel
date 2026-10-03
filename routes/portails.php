<?php

// Module « Portails » (étape 9). Chargé dans le groupe auth + licence de
// routes/web.php.

use App\Http\Controllers\Portails\GestionParentsController;
use App\Http\Controllers\Portails\PortailEnseignantController;
use App\Http\Controllers\Portails\PortailEtudiantController;
use App\Http\Controllers\Portails\RectoratController;
use Illuminate\Support\Facades\Route;

// Portail étudiant : l'étudiant pour lui-même ; le personnel avec la permission (contrôles dans le contrôleur)
Route::get('/portail/etudiant', [PortailEtudiantController::class, 'index'])->name('portail_etudiant');
Route::post('/portail/etudiant/profil', [PortailEtudiantController::class, 'profil'])->name('portail_etudiant.profil');

// Comptes parents (ancien hasRole sc_entite / doyen / chef de département ou plus)
Route::middleware(['page:gestion_parents', 'roles:admin,secretaire,sc_centrale,sc_entite,doyen,chef_departement'])->controller(GestionParentsController::class)->group(function () {
    Route::get('/parents/comptes', 'index')->name('gestion_parents');
    Route::post('/parents/comptes', 'creer')->name('gestion_parents.creer');
    Route::post('/parents/comptes/modifier', 'modifier')->name('gestion_parents.modifier');
    Route::post('/parents/comptes/statut', 'basculer')->name('gestion_parents.basculer');
    Route::post('/parents/comptes/lier', 'lier')->name('gestion_parents.lier');
    Route::post('/parents/comptes/delier', 'delier')->name('gestion_parents.delier');
});

// Portail enseignant (permission notes_saisir ; modes et périmètres dans le contrôleur)
Route::middleware('page:portail_enseignant')->controller(PortailEnseignantController::class)->group(function () {
    Route::get('/portail/enseignant', 'index')->name('portail_enseignant');
    Route::get('/portail/enseignant/modele', 'modele')->name('portail_enseignant.modele');
    Route::post('/portail/enseignant/notes', 'saisir')->name('portail_enseignant.saisir');
    Route::post('/portail/enseignant/import', 'importer')->name('portail_enseignant.importer');
    Route::post('/portail/enseignant/finalisation', 'finalisation')->name('portail_enseignant.finalisation');
    Route::post('/portail/enseignant/heures', 'heures')->name('portail_enseignant.heures');
    Route::post('/portail/enseignant/volume', 'volume')->name('portail_enseignant.volume');
});

// Tableau de bord du rectorat (recteur, admin) — lecture seule
Route::middleware('roles:recteur,admin')->controller(RectoratController::class)->group(function () {
    Route::get('/rectorat', 'index')->name('rectorat');
    Route::get('/rectorat/excel', 'excel')->name('rectorat.excel');
    Route::get('/rectorat/rapport', 'rapport')->name('rectorat.rapport');
    Route::get('/rectorat/pv/{id}/impression', 'impressionPv')->whereNumber('id')->name('rectorat.pv_impression');
    Route::post('/rectorat/mot-de-passe', 'motDePasse')->name('rectorat.mot_de_passe');
});
