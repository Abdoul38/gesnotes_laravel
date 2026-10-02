<?php

namespace App\Support;

use DateTime;

/** Fonctions d'affichage reprises de l'ancien includes/functions.php. */
final class Format
{
    /**
     * Ancien formatDateFR() : accepte plusieurs formats, corrige les années
     * sur deux chiffres (0025 → 2025), renvoie « — » si vide et la valeur
     * brute si elle n'est pas reconnue. Le résultat est échappé par Blade.
     */
    public static function dateFR(?string $date): string
    {
        if (! $date) {
            return '—';
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'] as $format) {
            $d = DateTime::createFromFormat($format, $date);
            if ($d && $d->format($format) === $date) {
                $annee = (int) $d->format('Y');
                if ($annee < 100) {
                    $annee += 2000;
                    $d->setDate($annee, (int) $d->format('m'), (int) $d->format('d'));
                }
                if ($annee >= 1900 && $annee <= 2100) {
                    return $d->format('d/m/Y');
                }
            }
        }

        return $date;
    }
}
