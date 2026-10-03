<?php

namespace App\Http\Controllers;

use App\Services\Identite;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fichiers déposés, servis par l'application (et non plus directement
 * par le serveur web) : on peut ainsi contrôler l'accès page par page.
 * Pendant la migration, ils restent dans les dossiers de l'ancienne
 * application (config gesnotes.dossier_uploads / dossier_pieces_candidats).
 */
class FichierController extends Controller
{
    private const MIME = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'bmp' => 'image/bmp',
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /** Logos (université, facultés) : publics, affichés dès la page de connexion. */
    public function logo(string $fichier): BinaryFileResponse
    {
        return $this->servir(config('gesnotes.dossier_uploads'), 'logos', $fichier, ['png', 'jpg', 'jpeg']);
    }

    /** Modèle Excel de l'import d'étudiants (ancien lien direct uploads/modele_import_etudiants.xlsx). */
    public function modeleImport(): BinaryFileResponse
    {
        $chemin = rtrim((string) config('gesnotes.dossier_uploads'), '/\\').DIRECTORY_SEPARATOR.'modele_import_etudiants.xlsx';
        abort_unless(config('gesnotes.dossier_uploads') && is_file($chemin), 404);

        return response()->download($chemin, 'modele_import_etudiants.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Photos d'étudiants : données personnelles, réservées au personnel (route protégée). */
    public function photo(string $fichier): BinaryFileResponse
    {
        return $this->servir(config('gesnotes.dossier_uploads'), 'photos', $fichier, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'], prive: true);
    }

    /** Photos d'enseignants (ancien uploads/photos_enseignants/, public) : réservées au personnel. */
    public function photoEnseignant(string $fichier): BinaryFileResponse
    {
        return $this->servir(config('gesnotes.dossier_uploads'), 'photos_enseignants', $fichier, ['png', 'jpg', 'jpeg', 'webp'], prive: true);
    }

    /** Document d'un dossier étudiant, désigné par son numéro (jamais par un nom de fichier). */
    public function document(int $id): BinaryFileResponse
    {
        $fichier = DB::table('documents_etudiants')->where('id', $id)->value('fichier');
        abort_unless($fichier, 404);

        return $this->servir(config('gesnotes.dossier_uploads'), 'documents', basename($fichier), ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'], prive: true);
    }

    /** Pièce du dossier de candidature (portail candidats). */
    public function pieceCandidat(string $fichier): BinaryFileResponse
    {
        return $this->servir(config('gesnotes.dossier_pieces_candidats'), '', $fichier, ['pdf', 'jpg', 'jpeg', 'png'], prive: true);
    }

    /**
     * Cachet ou signature de l'autorité : étaient publics dans l'ancienne
     * application (n'importe qui pouvait les récupérer pour fabriquer un
     * faux document). Servis ici aux seuls utilisateurs connectés.
     */
    public function officiel(string $type, Identite $identite): BinaryFileResponse
    {
        $chemin = $identite->cheminImageOfficielle($type);
        abort_unless($chemin, 404);
        $extension = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));

        return new BinaryFileResponse($chemin, 200, [
            'Content-Type' => self::MIME[$extension] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], public: false);
    }

    private function servir(?string $racine, string $sousDossier, string $fichier, array $extensions, bool $prive = false): BinaryFileResponse
    {
        // Nom de fichier simple uniquement : pas de « ../ », pas de sous-dossier.
        abort_unless(preg_match('/^[A-Za-z0-9._-]+$/', $fichier) && ! str_starts_with($fichier, '.'), 404);
        $extension = strtolower(pathinfo($fichier, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, $extensions, true), 404);

        $dossier = $racine ? realpath($racine.($sousDossier !== '' ? '/'.$sousDossier : '')) : false;
        $chemin = $dossier ? realpath($dossier.'/'.$fichier) : false;
        abort_unless($chemin && is_file($chemin) && str_starts_with($chemin, $dossier.DIRECTORY_SEPARATOR), 404);

        // Pas response()->file() : il marque toujours la réponse « public »,
        // ce qui écrasait le « private » des fichiers personnels.
        return new BinaryFileResponse($chemin, 200, [
            // Type déduit de l'extension contrôlée, jamais du contenu envoyé
            'Content-Type' => self::MIME[$extension],
            'X-Content-Type-Options' => 'nosniff',
            // Fichier personnel : jamais en cache partagé (proxy) ni conservé.
            'Cache-Control' => $prive ? 'private, no-store' : 'public, max-age=86400',
        ], public: ! $prive);
    }
}
