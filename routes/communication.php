<?php

// Module « Communication » (étape 8). Chargé dans le groupe auth + licence de
// routes/web.php.

use App\Http\Controllers\Communication\AnnoncesController;
use App\Http\Controllers\Communication\MessagerieController;
use App\Http\Controllers\Communication\NotificationsEmailController;
use App\Http\Controllers\Communication\NotifierPvController;
use App\Http\Controllers\Communication\ParametresCommunicationController;
use App\Http\Controllers\Communication\RelancesController;
use App\Http\Controllers\Communication\TableauNotificationsController;
use Illuminate\Support\Facades\Route;

// Serveur SMTP et passerelle SMS : administrateur seulement (voir le contrôleur)
Route::middleware('roles:admin')->controller(ParametresCommunicationController::class)->group(function () {
    Route::get('/parametres/email', 'email')->name('parametres_email');
    Route::post('/parametres/email', 'enregistrerEmail')->name('parametres_email.enregistrer');
    Route::post('/parametres/email/test', 'testerEmail')->name('parametres_email.tester');
    Route::get('/parametres/sms', 'sms')->name('parametres_sms');
    Route::post('/parametres/sms', 'enregistrerSms')->name('parametres_sms.enregistrer');
    Route::post('/parametres/sms/test', 'testerSms')->name('parametres_sms.tester');
});

Route::middleware(['page:notifier_pv', 'roles:admin,secretaire,sc_centrale,sc_entite'])->controller(NotifierPvController::class)->group(function () {
    Route::get('/notifications/pv', 'index')->name('notifier_pv');
    Route::post('/notifications/pv', 'envoyer')->name('notifier_pv.envoyer');
});

Route::get('/relances', [RelancesController::class, 'index'])->middleware('page:relances')->name('relances');
Route::post('/relances', [RelancesController::class, 'relancer'])->middleware(['page:relances', 'permission:paiements_gerer'])->name('relances.envoyer');

Route::get('/annonces', [AnnoncesController::class, 'index'])->middleware('page:annonces')->name('annonces');
Route::middleware(['page:annonces', 'permission:etudiants_gerer'])->controller(AnnoncesController::class)->group(function () {
    Route::post('/annonces', 'enregistrer')->name('annonces.enregistrer');
    Route::post('/annonces/publication', 'basculerPublication')->name('annonces.publication');
    Route::post('/annonces/supprimer', 'supprimer')->name('annonces.supprimer');
});

Route::middleware(['page:messagerie', 'roles:admin,secretaire,sc_centrale,doyen,chef_departement'])->controller(MessagerieController::class)->group(function () {
    Route::get('/messagerie', 'index')->name('messagerie');
    Route::post('/messagerie', 'envoyer')->name('messagerie.envoyer');
    Route::post('/messagerie/supprimer', 'supprimer')->name('messagerie.supprimer');
});

Route::middleware(['page:notifications_email', 'roles:admin,secretaire,sc_centrale,doyen,chef_departement'])->controller(NotificationsEmailController::class)->group(function () {
    Route::get('/emails-etudiants', 'index')->name('notifications_email');
    Route::post('/emails-etudiants', 'envoyer')->name('notifications_email.envoyer');
});

Route::get('/notifications/vue-ensemble', TableauNotificationsController::class)
    ->middleware(['page:dashboard_notifications', 'roles:admin,secretaire,sc_centrale,sc_entite,doyen,chef_departement'])->name('dashboard_notifications');
