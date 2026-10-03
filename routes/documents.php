<?php

// Module « Documents officiels » (étape 6). Chargé dans le groupe auth +
// licence de routes/web.php (la vérification publique est dans web.php).

use App\Http\Controllers\Documents\AttestationReussiteController;
use App\Http\Controllers\Documents\AttestationsController;
use App\Http\Controllers\Documents\CertificatScolariteController;
use App\Http\Controllers\Documents\ConvocationsController;
use App\Http\Controllers\Documents\DiplomeController;
use App\Http\Controllers\Documents\DiplomesController;
use App\Http\Controllers\Documents\RegistreDiplomesController;
use Illuminate\Support\Facades\Route;

// Rôles et permission vérifiés dans les contrôleurs : l'étudiant y accède pour lui-même (ancien routeur)
Route::get('/attestations/reussite', AttestationReussiteController::class)->name('attestation');

$documents = 'roles:admin,secretaire,sc_centrale,sc_entite';
Route::get('/attestations', AttestationsController::class)->name('attestations');
Route::middleware(['page:diplome', $documents])->controller(DiplomeController::class)->group(function () {
    Route::get('/diplomes/document', 'afficher')->name('diplome');
    Route::post('/diplomes/document/master', 'enregistrerMaster')->name('diplome.master');
});
Route::get('/diplomes', DiplomesController::class)->middleware(['page:diplomes', 'permission:pv_voir'])->name('diplomes');
Route::get('/diplomes/registre', RegistreDiplomesController::class)->middleware('page:registre_diplomes')->name('registre_diplomes');
// Certificat : rôles et permission vérifiés dans le contrôleur (étudiant pour lui-même)
Route::get('/certificat-scolarite', CertificatScolariteController::class)->name('certificat_scolarite');
Route::get('/convocations', ConvocationsController::class)->middleware(['page:convocations', $documents])->name('convocations');
