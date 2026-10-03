<?php

// API de l'application mobile (étape 11). Sans session ni jeton CSRF : jeton
// « Bearer » (mobile_tokens). Même adresse que l'ancienne application
// (/api_mobile.php?action=…), et /api/mobile pour les nouvelles versions.

use App\Http\Controllers\Api\ApiMobileController;
use App\Http\Controllers\FichierController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post', 'options'], '/api_mobile.php', ApiMobileController::class)->name('api_mobile');
Route::match(['get', 'post', 'options'], '/api/mobile', ApiMobileController::class)->name('api_mobile.nouvelle');

// Photo de l'étudiant : adresse signée (valable une heure) fournie par profil / carte
Route::get('/api/mobile/photo/{etudiant}', function (int $etudiant, FichierController $fichiers) {
    $photo = (string) DB::table('etudiants')->where('id', $etudiant)->value('photo');
    abort_if($photo === '', 404);

    return $fichiers->photo(basename(preg_replace('#^/?photos/#', '', $photo)));
})->whereNumber('etudiant')->middleware('signed')->name('api_mobile.photo');
