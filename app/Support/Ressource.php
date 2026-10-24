<?php

namespace App\Support;

/**
 * Adresse d'un fichier statique (script, feuille de style) avec un numéro de
 * version tiré de sa date de modification : après une mise à jour, les
 * navigateurs chargent la nouvelle version au lieu de garder l'ancienne en
 * cache (une correction de script ne s'appliquait qu'après Ctrl+F5).
 */
final class Ressource
{
    public static function url(string $chemin): string
    {
        $fichier = public_path($chemin);

        return asset($chemin).(is_file($fichier) ? '?v='.filemtime($fichier) : '');
    }
}
