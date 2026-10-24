<?php

// Module « Portails » (étape 9). Chargé dans le groupe auth + licence de
// routes/web.php.

use App\Http\Controllers\Candidature\CandidaturesAdminController;
use App\Http\Controllers\Portails\EmploiTempsEnseignantController;
use App\Http\Controllers\Portails\ExamensEnseignantController;
use App\Http\Controllers\Portails\GestionParentsController;
use App\Http\Controllers\Portails\PortailEnseignantController;
use App\Http\Controllers\Portails\PortailEtudiantController;
use App\Http\Controllers\Portails\RectoratController;
use App\Http\Controllers\Rapports\PilotageController;
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
// Planning des examens de l'enseignant (lecture seule)
Route::get('/portail/enseignant/examens', ExamensEnseignantController::class)->middleware('page:mes_examens')->name('mes_examens');
Route::get('/portail/enseignant/emploi-du-temps', EmploiTempsEnseignantController::class)->middleware('page:mon_emploi_temps')->name('mon_emploi_temps');
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
    Route::get('/rectorat/pilotage', PilotageController::class)->name('rectorat.pilotage');
    Route::get('/rectorat/pv/{id}/impression', 'impressionPv')->whereNumber('id')->name('rectorat.pv_impression');
    Route::post('/rectorat/mot-de-passe', 'motDePasse')->name('rectorat.mot_de_passe');
});

// Administration des candidatures (ancien hasRole admin / secrétaire / sc_centrale)
Route::middleware(['page:portail_candidats_admin', 'roles:admin,secretaire,sc_centrale'])
    ->controller(CandidaturesAdminController::class)->group(function () {
        Route::get('/candidatures', 'index')->name('portail_candidats_admin');
        Route::post('/candidatures', 'creerDossier')->name('portail_candidats_admin.creer');
        Route::post('/candidatures/statut', 'statut')->name('portail_candidats_admin.statut');
        Route::post('/candidatures/note', 'note')->name('portail_candidats_admin.note');
        Route::post('/candidatures/etudiant', 'creerEtudiant')->name('portail_candidats_admin.etudiant');
        Route::post('/candidatures/configuration', 'config')->name('portail_candidats_admin.config');
        Route::post('/candidatures/configuration/restrictions', 'reinitialiserRestrictions')->name('portail_candidats_admin.restrictions');
        Route::get('/candidatures/export', 'export')->name('portail_export');
        Route::get('/candidatures/{id}', 'dossier')->whereNumber('id')->name('dossier_candidat');
        Route::post('/candidatures/{id}', 'modifierDossier')->whereNumber('id')->name('dossier_candidat.modifier');
        Route::get('/candidatures/{id}/quitus', 'quitus')->whereNumber('id')->name('portail_candidats_admin.quitus');
        Route::get('/candidatures/{id}/pieces/{type}', 'piece')->whereNumber('id')->whereAlpha('type')->name('portail_candidats_admin.piece');
    });
