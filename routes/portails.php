<?php

// Module « Portails » (étape 9). Chargé dans le groupe auth + licence de
// routes/web.php.

use App\Http\Controllers\Portails\PortailEtudiantController;
use Illuminate\Support\Facades\Route;

// Portail étudiant : l'étudiant pour lui-même ; le personnel avec la permission (contrôles dans le contrôleur)
Route::get('/portail/etudiant', [PortailEtudiantController::class, 'index'])->name('portail_etudiant');
Route::post('/portail/etudiant/profil', [PortailEtudiantController::class, 'profil'])->name('portail_etudiant.profil');
