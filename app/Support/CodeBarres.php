<?php

namespace App\Support;

/**
 * Code-barres Code 128 (jeu B) en SVG vectoriel, sans bibliothèque ni
 * JavaScript — portage à l'identique de code128b_svg() (ancienne carte).
 */
final class CodeBarres
{
    private const MOTIFS = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112'];

    public static function code128b(string $texte, int $zoneCalme = 10): string
    {
        $valeurs = [104];               // caractère de départ (jeu B)
        $somme = 104;
        for ($i = 0, $n = strlen($texte); $i < $n; $i++) {
            $v = ord($texte[$i]) - 32;
            if ($v < 0 || $v > 95) {
                $v = 13;                // caractère hors jeu B → « - »
            }
            $valeurs[] = $v;
            $somme += $v * ($i + 1);
        }
        $valeurs[] = $somme % 103;      // clé de contrôle
        $valeurs[] = 106;               // arrêt

        $modules = '';
        foreach ($valeurs as $v) {
            $barre = true;
            foreach (str_split(self::MOTIFS[$v]) as $largeur) {
                $modules .= str_repeat($barre ? '1' : '0', (int) $largeur);
                $barre = ! $barre;
            }
        }

        $rects = '';
        $n = strlen($modules);
        for ($i = 0; $i < $n;) {
            if ($modules[$i] === '1') {
                $j = $i;
                while ($j < $n && $modules[$j] === '1') {
                    $j++;
                }
                $rects .= "<rect x='".($i + $zoneCalme)."' y='0' width='".($j - $i)."' height='1'/>";
                $i = $j;
            } else {
                $i++;
            }
        }
        $largeurTotale = $n + 2 * $zoneCalme;

        return "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 $largeurTotale 1' preserveAspectRatio='none' shape-rendering='crispEdges' fill='#000'>$rects</svg>";
    }
}
