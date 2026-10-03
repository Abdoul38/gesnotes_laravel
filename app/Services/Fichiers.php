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

    /**
     * Photo reçue sous forme de contenu brut (image extraite d'un ZIP) :
     * vraie image JPEG, PNG, GIF, WebP ou BMP (formats de l'ancien import),
     * enregistrée avec l'extension de son type RÉEL. Retourne le nom, ou null.
     */
    public function enregistrerPhotoContenu(string $contenu, string $sousDossier, string $prefixe, int $maxOctets = 5 * 1024 * 1024): ?string
    {
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp', IMAGETYPE_BMP => 'bmp'];
        $info = strlen($contenu) <= $maxOctets ? @getimagesizefromstring($contenu) : false;
        if (! $info || ! isset($types[$info[2]])) {
            return null;
        }
        $prefixe = preg_replace('/[^a-z0-9_-]/', '', strtolower($prefixe)) ?: 'fichier';
        $nom = $prefixe.'_'.time().'_'.bin2hex(random_bytes(3)).'.'.$types[$info[2]];
        if (file_put_contents($this->dossier($sousDossier).DIRECTORY_SEPARATOR.$nom, $contenu) === false) {
            return null;
        }

        return $nom;
    }

    /**
     * Pièce d'un dossier de candidature (PDF, JPG, PNG vérifiés par leur
     * contenu ; la photo d'identité : image seulement), rangée dans le
     * dossier privé des pièces (config gesnotes.dossier_pieces_candidats),
     * sous un nom aléatoire. Retourne le nom, ou null si refusée.
     */
    public function enregistrerPiece(UploadedFile $fichier, string $type, int $maxOctets, bool $imageSeulement = false): ?string
    {
        $extension = strtolower($fichier->getClientOriginalExtension());
        $extensions = $imageSeulement ? ['jpg', 'jpeg', 'png'] : ['pdf', 'jpg', 'jpeg', 'png'];
        if (! $this->recevable($fichier, $extension, $extensions, $maxOctets)) {
            return null;
        }
        $conforme = $extension === 'pdf'
            ? str_starts_with((string) file_get_contents($fichier->getRealPath(), false, null, 0, 5), '%PDF')
            : $this->estImage($fichier, $extension);
        $racine = config('gesnotes.dossier_pieces_candidats');
        if (! $conforme || ! $racine) {
            return null;
        }
        if (! is_dir($racine) && ! mkdir($racine, 0755, true) && ! is_dir($racine)) {
            throw new RuntimeException('Impossible de créer le dossier des pièces des candidats.');
        }
        $nom = (preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'piece').'_'.time().'_'.bin2hex(random_bytes(8)).'.'.$extension;
        $fichier->move($racine, $nom);

        return $nom;
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
