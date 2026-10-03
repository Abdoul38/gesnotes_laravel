<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fichiers déposés, servis par l'application (et non plus directement
 * par le serveur web) : on peut ainsi contrôler l'accès page par page.
 * Pendant la migration, ils restent dans le dossier uploads/ de l'ancienne
 * application (config gesnotes.dossier_uploads).
 */
class FichierController extends Controller
{
    /** Logos (université, facultés) : publics, affichés dès la page de connexion. */
    public function logo(string $fichier): BinaryFileResponse
    {
        return $this->servir('logos', $fichier, ['png', 'jpg', 'jpeg']);
    }

    /** Photos d'étudiants : données personnelles, réservées au personnel (route protégée). */
    public function photo(string $fichier): BinaryFileResponse
    {
        return $this->servir('photos', $fichier, ['png', 'jpg', 'jpeg', 'webp'], prive: true);
    }

    private function servir(string $sousDossier, string $fichier, array $extensions, bool $prive = false): BinaryFileResponse
    {
        // Nom de fichier simple uniquement : pas de « ../ », pas de sous-dossier.
        abort_unless(preg_match('/^[A-Za-z0-9._-]+$/', $fichier) && ! str_starts_with($fichier, '.'), 404);
        abort_unless(in_array(strtolower(pathinfo($fichier, PATHINFO_EXTENSION)), $extensions, true), 404);

        $racine = config('gesnotes.dossier_uploads');
        $dossier = $racine ? realpath($racine.'/'.$sousDossier) : false;
        $chemin = $dossier ? realpath($dossier.'/'.$fichier) : false;
        abort_unless($chemin && is_file($chemin) && str_starts_with($chemin, $dossier.DIRECTORY_SEPARATOR), 404);

        return response()->file($chemin, [
            'X-Content-Type-Options' => 'nosniff',
            // Fichier personnel : jamais en cache partagé (proxy) ni conservé.
            'Cache-Control' => $prive ? 'private, no-store' : 'public, max-age=86400',
        ]);
    }
}
