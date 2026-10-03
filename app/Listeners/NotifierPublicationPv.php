<?php

namespace App\Listeners;

use App\Events\PvPublie;
use App\Services\Communication\Notifications;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publication d'un PV → étudiants et parents prévenus (ancien appel à
 * notifierResultatsPv() dans pv.php).
 *
 * Différences voulues :
 *  - une seule fois par PV : dépublier puis republier renvoyait tous les
 *    messages (le renvoi volontaire se fait par « Notifier un PV ») ;
 *  - envoi après la réponse au navigateur (il se faisait pendant la requête,
 *    qui pouvait expirer sur une grande promotion) ; un échec est journalisé
 *    sans bloquer la publication.
 */
class NotifierPublicationPv
{
    public function __construct(private readonly Notifications $notifications) {}

    public function handle(PvPublie $evenement): void
    {
        if ($this->notifications->pvDejaNotifie($evenement->pvId)) {
            return;
        }
        $notifications = $this->notifications;
        dispatch(function () use ($notifications, $evenement) {
            try {
                // Revérifié au moment de l'envoi : deux publications rapprochées ne notifient qu'une fois
                if (! $notifications->pvDejaNotifie($evenement->pvId)) {
                    $notifications->notifierPv($evenement->pvId);
                }
            } catch (Throwable $e) {
                Log::error('Notification de publication du PV échouée', ['pv_id' => $evenement->pvId, 'erreur' => $e->getMessage()]);
            }
        })->afterResponse();
    }
}
