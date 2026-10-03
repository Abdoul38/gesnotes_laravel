<?php

// Module « Portails » (étape 9). Chargé dans le groupe auth + licence de
// routes/web.php.

use App\Http\Controllers\Portails\GestionParentsController;
use App\Http\Controllers\Portails\PortailEtudiantController;
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
