<?php

namespace App\Support;

use RuntimeException;

/**
 * Écrit une variable dans un fichier .env (remplace la ligne existante,
 * même commentée, sinon l'ajoute à la fin). Valeurs simples uniquement :
 * lettres, chiffres et - _ . : / \ (chemins compris ; pas d'espace ni de
 * guillemets à gérer).
 */
final class FichierEnv
{
    public static function definir(string $chemin, string $cle, string $valeur): void
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $cle) || ! preg_match('#^[A-Za-z0-9_.:/\\\\-]*$#', $valeur)) {
            throw new RuntimeException("Variable ou valeur refusée pour le .env : $cle");
        }
        $contenu = is_file($chemin) ? (string) file_get_contents($chemin) : '';
        $ligne = "$cle=$valeur";
        $motif = '/^[ \t]*#?[ \t]*'.preg_quote($cle, '/').'=.*$/m';
        $contenu = preg_match($motif, $contenu)
            ? (string) preg_replace($motif, $ligne, $contenu, 1)
            : rtrim($contenu, "\r\n").($contenu === '' ? '' : "\n").$ligne."\n";
        if (file_put_contents($chemin, $contenu) === false) {
            throw new RuntimeException("Écriture impossible : $chemin");
        }
    }
}
