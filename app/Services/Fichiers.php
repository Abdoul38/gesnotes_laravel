<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Fichiers déposés (logos, photos, pièces…).
 *
 * Pendant la migration, ils restent dans le dossier uploads/ de l'ancienne
 * application (config gesnotes.dossier_uploads) : les deux versions voient
 * ainsi les mêmes fichiers. Les noms sont toujours générés par le serveur.
 */
class Fichiers
{
    public const IMAGES = ['png', 'jpg', 'jpeg'];

    public function dossier(string $sousDossier): string
    {
        $racine = config('gesnotes.dossier_uploads')
            ?? throw new RuntimeException('Dossier des fichiers non configuré (ANCIENNE_APP_CHEMIN).');
        $dossier = rtrim($racine, '/\\').DIRECTORY_SEPARATOR.$sousDossier;
        if (! is_dir($dossier) && ! mkdir($dossier, 0755, true) && ! is_dir($dossier)) {
            throw new RuntimeException("Impossible de créer le dossier $sousDossier.");
        }

        return $dossier;
    }

    /**
     * Enregistre une image (PNG/JPG vérifiée par son contenu, pas seulement
     * son extension). Retourne le nom du fichier, ou null si refusée.
     *
     * @param  string  $prefixe  partie du nom (ex. 'logo_fadeg') — nettoyée
     */
    public function enregistrerImage(UploadedFile $fichier, string $sousDossier, string $prefixe, int $maxOctets = 2 * 1024 * 1024): ?string
    {
        $extension = strtolower($fichier->getClientOriginalExtension());
        if (! $fichier->isValid() || ! in_array($extension, self::IMAGES, true) || $fichier->getSize() > $maxOctets) {
            return null;
        }
        $info = @getimagesize($fichier->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return null;
        }

        // Le préfixe peut venir d'une saisie (code de faculté…) : seuls
        // lettres, chiffres, - et _ sont gardés, jamais de « ../ ».
        $prefixe = preg_replace('/[^a-z0-9_-]/', '', strtolower($prefixe)) ?: 'fichier';
        $nom = $prefixe.'_'.time().'.'.$extension;
        $fichier->move($this->dossier($sousDossier), $nom);

        return $nom;
    }
}
