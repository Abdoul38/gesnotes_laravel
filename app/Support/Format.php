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

    /**
     * Date lue dans un fichier d'import → « AAAA-MM-JJ », ou null.
     * Reprend normalizeDate() des anciens imports : JJ/MM/AAAA, JJ-MM-AAAA,
     * JJ.MM.AAAA, AAAA-MM-JJ, AAAA/MM/JJ et années sur 2 chiffres
     * (≤ 30 → 20xx, sinon 19xx). En plus : les dates numériques d'Excel
     * (cellule au format date) et le refus des dates impossibles (31/02,
     * que l'ancien code transformait en 3 mars).
     */
    public static function dateImport(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);
        if (preg_match('/^\d{5}(\.0+)?$/', $valeur)) {
            // Numéro de série Excel : jours écoulés depuis le 30/12/1899
            $d = (new DateTime('1899-12-30'))->modify('+'.(int) $valeur.' days');
            [$a, $m, $j] = array_map('intval', explode('-', $d->format('Y-m-d')));
        } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})$/', $valeur, $p)) {
            [$j, $m, $a] = [(int) $p[1], (int) $p[2], (int) $p[3]];
            if (strlen($p[3]) === 2) {
                $a += $a <= 30 ? 2000 : 1900;
            }
        } elseif (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $valeur, $p)) {
            [$a, $m, $j] = [(int) $p[1], (int) $p[2], (int) $p[3]];
        } else {
            return null;
        }

        return $a >= 1900 && $a <= 2100 && checkdate($m, $j, $a) ? sprintf('%04d-%02d-%02d', $a, $m, $j) : null;
    }
}
