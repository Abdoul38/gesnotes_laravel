<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un PV vient d'être publié. L'ancienne application notifiait alors les
 * étudiants (e-mail, SMS — notifierResultatsPv) : voir
 * App\Listeners\NotifierPublicationPv.
 */
class PvPublie
{
    use Dispatchable;

    public function __construct(public readonly int $pvId) {}
}
