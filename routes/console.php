<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sauvegardes automatiques (SAUVEGARDES_AUTO=true ; le Planificateur de tâches Windows lance
// « php artisan schedule:run » chaque minute, voir BASCULE.md) : complète le dimanche, différentielle
// les autres nuits, incrémentielle chaque heure pendant la journée de travail
if (config('gesnotes.sauvegardes_auto.planifiees')) {
    Schedule::command('gesnotes:sauvegarder --type=complete')->weeklyOn(0, '02:00')->withoutOverlapping(120);
    Schedule::command('gesnotes:sauvegarder --type=differentielle')->days([1, 2, 3, 4, 5, 6])->at('02:00')->withoutOverlapping(120);
    Schedule::command('gesnotes:sauvegarder --type=incrementielle')->hourly()->between('07:00', '20:00')->withoutOverlapping(60);
}
