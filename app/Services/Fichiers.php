<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Fichiers déposés (logos, photos, pièces…).
 *
 * Pendant la migration, ils restent dans le dossier uploads/ de l'ancienne
 * application (config gesnotes.dossier_uploads) : les deux versions voient
 * ainsi les mêmes fichiers. Les noms sont toujours générés par le serveur,
 * et le CONTENU est vérifié (pas seulement l'extension).
 */
class Fichiers
{
    public const IMAGES = ['png', 'jpg', 'jpeg'];

    /** Photos : WebP accepté en plus, comme l'ancienne fiche étudiant. */
    public const PHOTOS = ['png', 'jpg', 'jpeg', 'webp'];

    /** Documents d'un dossier étudiant (ancienne liste blanche). */
    public const DOCUMENTS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

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
     * Enregistre une image vérifiée par son contenu. Retourne le nom du
     * fichier, ou null si refusée.
     *
     * @param  string  $prefixe  partie du nom (ex. 'logo_fadeg') — nettoyée
     * @param  list<string>  $extensions  self::IMAGES ou self::PHOTOS
     */
    public function enregistrerImage(UploadedFile $fichier, string $sousDossier, string $prefixe, int $maxOctets = 2 * 1024 * 1024, array $extensions = self::IMAGES): ?string
    {
        $extension = strtolower($fichier->getClientOriginalExtension());
        if (! $this->recevable($fichier, $extension, $extensions, $maxOctets) || ! $this->estImage($fichier, $extension)) {
            return null;
        }

        return $this->deplacer($fichier, $sousDossier, $prefixe, $extension);
    }

    /** Document (PDF, Word, image), vérifié par sa signature de fichier. */
    public function enregistrerDocument(UploadedFile $fichier, string $sousDossier, string $prefixe, int $maxOctets = 5 * 1024 * 1024): ?string
    {
        $extension = strtolower($fichier->getClientOriginalExtension());
        if (! $this->recevable($fichier, $extension, self::DOCUMENTS, $maxOctets)) {
            return null;
        }
        $debut = (string) file_get_contents($fichier->getRealPath(), false, null, 0, 8);
        $conforme = match ($extension) {
            'pdf' => str_starts_with($debut, '%PDF'),
            'doc' => str_starts_with($debut, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"), // format Office 97-2003
            'docx' => str_starts_with($debut, "PK\x03\x04"),                     // archive Office Open XML
            default => $this->estImage($fichier, $extension),
        };

        return $conforme ? $this->deplacer($fichier, $sousDossier, $prefixe, $extension) : null;
    }

    private function recevable(UploadedFile $fichier, string $extension, array $extensions, int $maxOctets): bool
    {
        return $fichier->isValid() && in_array($extension, $extensions, true) && $fichier->getSize() <= $maxOctets;
    }

    private function estImage(UploadedFile $fichier, string $extension): bool
    {
        // Une vraie image PNG, JPEG ou WebP, quelle que soit l'extension
        // annoncée (photo JPEG nommée .png, fréquent sur téléphone).
        // WebP seulement là où l'extension .webp est admise (photos).
        $info = @getimagesize($fichier->getRealPath());
        $types = [IMAGETYPE_PNG, IMAGETYPE_JPEG];
        if ($extension === 'webp') {
            $types[] = IMAGETYPE_WEBP;
        }

        return $info && in_array($info[2], $types, true);
    }

    private function deplacer(UploadedFile $fichier, string $sousDossier, string $prefixe, string $extension): string
    {
        // Le préfixe peut venir d'une saisie (code de faculté…) : seuls
        // lettres, chiffres, - et _ sont gardés, jamais de « ../ ».
        $prefixe = preg_replace('/[^a-z0-9_-]/', '', strtolower($prefixe)) ?: 'fichier';
        $nom = $prefixe.'_'.time().'.'.$extension;
        $fichier->move($this->dossier($sousDossier), $nom);

        return $nom;
    }
}
