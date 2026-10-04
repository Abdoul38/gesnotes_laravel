<?php

// Administration : utilisateurs, rôles, signataires, paramètres,
// sauvegarde, journaux. Chargé dans le groupe auth + licence de
// routes/web.php (la page Licence, elle, reste accessible licence
// invalide : voir web.php).

use App\Http\Controllers\Administration\JournauxController;
use App\Http\Controllers\Administration\ParametresController;
use App\Http\Controllers\Administration\RolesController;
use App\Http\Controllers\Administration\SauvegardeController;
use App\Http\Controllers\Administration\SignatairesController;
use App\Http\Controllers\Administration\UtilisateursController;
use Illuminate\Support\Facades\Route;

Route::middleware(['page:utilisateurs', 'roles:admin'])->controller(UtilisateursController::class)->group(function () {
    Route::get('/utilisateurs', 'index')->name('utilisateurs');
    Route::post('/utilisateurs', 'creer')->name('utilisateurs.creer');
    Route::post('/utilisateurs/modifier', 'modifier')->name('utilisateurs.modifier');
    Route::post('/utilisateurs/supprimer', 'supprimer')->name('utilisateurs.supprimer');
});

Route::middleware(['page:roles', 'roles:admin'])->controller(RolesController::class)->group(function () {
    Route::get('/roles', 'index')->name('roles');
    Route::post('/roles', 'enregistrer')->name('roles.enregistrer');
});

Route::middleware('page:signataires')->controller(SignatairesController::class)->group(function () {
    Route::get('/signataires', 'index')->name('signataires');
    Route::post('/signataires', 'ajouter')->name('signataires.ajouter');
    Route::post('/signataires/modifier', 'modifier')->name('signataires.modifier');
    Route::post('/signataires/activer', 'activer')->name('signataires.activer');
    Route::post('/signataires/supprimer', 'supprimer')->name('signataires.supprimer');
});

// Réglages valables pour toute l'université : administration et secrétariat
Route::middleware(['page:parametres', 'roles:admin,secretaire'])->controller(ParametresController::class)->group(function () {
    Route::get('/parametres', 'index')->name('parametres');
    Route::post('/parametres/general', 'general')->name('parametres.general');
    Route::post('/parametres/regles-pv', 'reglesPv')->name('parametres.regles');
    Route::post('/parametres/annees/verrouiller', 'verrouiller')->name('parametres.verrouiller');
    Route::post('/parametres/annees/deverrouiller', 'deverrouiller')->name('parametres.deverrouiller');
    Route::post('/parametres/signataire', 'signataireFaculte')->name('parametres.signataire');
    Route::post('/parametres/carte', 'carteModele')->name('parametres.carte');
    Route::post('/parametres/couleur', 'couleur')->name('parametres.couleur');
    Route::post('/parametres/logo', 'logo')->name('parametres.logo');
    Route::post('/parametres/signature', 'signature')->name('parametres.signature');
    Route::post('/parametres/signature/supprimer', 'supprimerSignature')->name('parametres.signature.supprimer');
    Route::post('/parametres/armoirie', 'armoirie')->name('parametres.armoirie');
    Route::post('/parametres/visa', 'visa')->name('parametres.visa');
    Route::post('/parametres/signataire-diplome', 'signataireDiplome')->name('parametres.signataire_diplome');
    Route::post('/parametres/filieres', 'filieres')->name('parametres.filieres');
});

Route::middleware(['page:sauvegarde', 'roles:admin'])->controller(SauvegardeController::class)->group(function () {
    Route::get('/sauvegarde', 'index')->name('sauvegarde');
    Route::post('/sauvegarde', 'sauvegarder')->name('sauvegarde.creer');
    Route::post('/sauvegarde/telecharger', 'telecharger')->name('sauvegarde.telecharger');
    Route::post('/sauvegarde/supprimer', 'supprimer')->name('sauvegarde.supprimer');
});

Route::get('/logs', JournauxController::class)->middleware('page:logs')->name('logs');
