<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sauvegardes automatiques (SAUVEGARDES_AUTO=true ; le Planificateur de tâches Windows lance
// « php artisan schedule:run » chaque minute, voir BASCULE.md). Toutes les 5 minutes, la sauvegarde due
// est faite : complète chaque dimanche 2 h, différentielle chaque nuit 2 h, incrémentielle chaque heure
// de 7 h à 20 h — et rattrapée dès que possible si l'ordinateur dormait ou si MySQL était arrêté.
if (config('gesnotes.sauvegardes_auto.planifiees')) {
    Schedule::command('gesnotes:sauvegarder --auto')->everyFiveMinutes()->withoutOverlapping(180);
    // Rappel par e-mail chaque lundi tant que la copie sur clé USB a plus de 28 jours
    Schedule::command('gesnotes:copier-usb --rappel')->weeklyOn(1, '09:00');
}

// Rappel aux parents des examens du lendemain (SMS / e-mail), chaque jour à 18 h — sur le serveur de
// production seulement (RAPPELS_EXAMENS=true dans son .env), jamais depuis un PC de test.
if (config('gesnotes.rappels_examens')) {
    Schedule::command('gesnotes:rappels-examens')->dailyAt('18:00')->withoutOverlapping(60);
}
