<?php

// Rapports et suivi (pages restées hors des étapes du plan). Chargé dans le
// groupe auth + licence de routes/web.php.

use App\Http\Controllers\Rapports\HistoriqueController;
use App\Http\Controllers\Rapports\RapportsController;
use App\Http\Controllers\Rapports\StatistiquesController;
use App\Http\Controllers\Rapports\SuiviExecutionController;
use Illuminate\Support\Facades\Route;

Route::get('/statistiques', StatistiquesController::class)->middleware('page:stats')->name('stats');

// Ancien contrôle de rôle d'historique.php (l'étudiant passe par son portail)
Route::get('/historique', HistoriqueController::class)
    ->middleware(['page:historique', 'roles:admin,secretaire,sc_centrale,sc_entite,doyen,chef_departement'])->name('historique');

Route::get('/rapports', [RapportsController::class, 'index'])->middleware('page:rapports')->name('rapports');
Route::get('/rapports/export', [RapportsController::class, 'export'])->middleware('page:rapports_export')->name('rapports_export');

Route::middleware('page:suivi_execution')->controller(SuiviExecutionController::class)->group(function () {
    Route::get('/suivi-execution', 'index')->name('suivi_execution');
    Route::post('/suivi-execution', 'heures')->name('suivi_execution.heures');
});
