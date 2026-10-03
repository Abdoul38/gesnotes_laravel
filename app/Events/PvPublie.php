<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un PV vient d'être publié. L'ancienne application notifiait alors les
 * étudiants (e-mail, SMS — notifierResultatsPv) ; l'écouteur sera branché
 * avec le module Communication (étape 8).
 */
class PvPublie
{
    use Dispatchable;

    public function __construct(public readonly int $pvId) {}
}
