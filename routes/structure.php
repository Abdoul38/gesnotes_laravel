<?php

// Module « Structure académique » (étape 2). Chargé dans le groupe
// auth + licence de routes/web.php. Chaque page garde la permission de
// son entrée de menu (page:…) et les rôles que l'ancienne page exigeait.

use App\Http\Controllers\Structure\AnneesController;
use App\Http\Controllers\Structure\FacultesController;
use App\Http\Controllers\Structure\FilieresController;
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

// Filières, départements, UE et matières : une seule page à onglets (?tab=)
Route::middleware('page:filieres')->controller(FilieresController::class)->group(function () {
    Route::get('/filieres', 'index')->name('filieres');
    Route::post('/filieres', 'filiereEnregistrer')->name('filieres.enregistrer');
    Route::post('/filieres/supprimer', 'filiereSupprimer')->name('filieres.supprimer');
    Route::post('/departements', 'departementAjouter')->name('departements.ajouter');
    Route::post('/departements/modifier', 'departementModifier')->name('departements.modifier');
    Route::post('/departements/supprimer', 'departementSupprimer')->name('departements.supprimer');
    Route::post('/ue', 'ueEnregistrer')->name('ue.enregistrer');
    Route::post('/ue/supprimer', 'ueSupprimer')->name('ue.supprimer');
    Route::post('/ue/copier', 'ueCopier')->name('ue.copier');
    Route::post('/matieres', 'matiereEnregistrer')->name('matieres.enregistrer');
    Route::post('/matieres/supprimer', 'matiereSupprimer')->name('matieres.supprimer');
    Route::post('/matieres/volume-masse', 'volumeMasse')->name('matieres.volume_masse');
});

// Présence (permission notes_saisir ; périmètre par rôle dans le contrôleur)
Route::middleware('page:presence')->controller(\App\Http\Controllers\Structure\PresenceController::class)->group(function () {
    Route::get('/presence', 'index')->name('presence');
    Route::post('/presence/seances', 'creer')->name('presence.creer');
    Route::post('/presence', 'enregistrer')->name('presence.enregistrer');
    Route::get('/presence/matieres', 'matieres')->name('api_matieres_promotion');
});

// Planning des examens (ancien contrôle de rôle de planning.php)
Route::middleware(['page:planning', 'roles:admin,secretaire,sc_centrale,sc_entite,doyen,chef_departement'])
    ->controller(\App\Http\Controllers\Structure\PlanningController::class)->group(function () {
        Route::get('/planning', 'index')->name('planning');
        Route::get('/planning/disponibilite', 'disponibilite')->name('planning.disponibilite');
        Route::post('/planning', 'sauvegarder')->name('planning.sauvegarder');
    });

// Emploi du temps (lecture : permission ; modification : personnel de scolarité, contrôlé dans le contrôleur)
Route::middleware('page:emploi_temps')->controller(\App\Http\Controllers\Structure\EmploiTempsController::class)->group(function () {
    Route::get('/emploi-du-temps', 'index')->name('emploi_temps');
    Route::get('/emploi-du-temps/disponibilite', 'disponibilite')->name('emploi_temps.disponibilite');
    Route::post('/emploi-du-temps', 'enregistrer')->name('emploi_temps.enregistrer');
    Route::post('/emploi-du-temps/supprimer', 'supprimer')->name('emploi_temps.supprimer');
});
