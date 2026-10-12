<?php

namespace App\Services;

use App\Services\Communication\Courriel;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E-mail à l'adresse SAUVEGARDES_AUTO_ALERTE (échec d'une sauvegarde, rappel
 * de copie sur clé USB), envoyé par le serveur SMTP de l'université par défaut.
 * Sans adresse : rien. Une erreur d'envoi est journalisée, jamais relancée.
 */
class AlerteSauvegarde
{
    public function __construct(private readonly RegistreUniversites $registre, private readonly UniversiteCourante $universite) {}

    public function envoyer(string $sujet, string $corpsHtml): bool
    {
        $adresse = config('gesnotes.sauvegardes_auto.alerte');
        if (! filled($adresse)) {
            return false;
        }
        try {
            return (bool) $this->universite->pour($this->registre->parDefaut(),
                fn () => app(Courriel::class)->envoyer($adresse, $sujet.' — '.config('app.name'), $corpsHtml, 'sauvegarde'));
        } catch (Throwable $e) {
            Log::error('Alerte de sauvegarde non envoyée', ['erreur' => $e->getMessage()]);

            return false;
        }
    }
}
