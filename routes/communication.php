<?php

// Module « Communication » (étape 8). Chargé dans le groupe auth + licence de
// routes/web.php.

use App\Http\Controllers\Communication\ParametresCommunicationController;
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
