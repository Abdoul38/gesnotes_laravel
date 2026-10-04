<?php

namespace App\Services;

use App\Universites\UniversiteCourante;
use Illuminate\Support\Facades\DB;

/**
 * Éléments d'identité des documents imprimés : logos, cachet, signature,
 * signataire. Portage de getLogosFaculte() et getSignatairePourFaculte()
 * (ancien includes/functions.php), et de la recherche du cachet et de la
 * signature faite dans chaque page de document.
 */
class Identite
{
    /** Emplacements essayés, dans l'ordre, par l'ancienne application. */
    private const IMAGES_OFFICIELLES = [
        'cachet' => ['assets/img/cachet.png', 'assets/img/cachet.jpg', 'uploads/cachet.png', 'uploads/cachet.jpg'],
        'signature' => ['assets/img/signature.png', 'assets/img/signature.jpg', 'uploads/signature.png', 'uploads/signature.jpg'],
    ];

    private array $logos = [];

    public function __construct(
        private readonly Parametres $parametres,
        private readonly Fichiers $fichiers,
        private readonly UniversiteCourante $universite,
    ) {}

    /**
     * ['gauche' => logo université, 'droite' => logo faculté (sinon université)].
     * Logo université : celui des Paramètres, sinon celui de l'université
     * courante, sinon le logo UDH (l'original prenait toujours celui de l'UDH).
     */
    public function logos(?int $faculteId = null): array
    {
        return $this->logos[$faculteId ?? 0] ??= (function () use ($faculteId) {
            $gauche = $this->logoTeleverse($this->parametres->brut('logo_universite'))
                ?? $this->logoPublic($this->universite->get()->logo)
                ?? asset('assets/img/logo_udh.png');
            $droite = $faculteId
                ? ($this->logoTeleverse((string) DB::table('facultes')->where('id', $faculteId)->value('logo')) ?? $gauche)
                : $gauche;

            return ['gauche' => $gauche, 'droite' => $droite];
        })();
    }

    /** Chemin du cachet ou de la signature de l'autorité, ou null. */
    public function cheminImageOfficielle(string $type): ?string
    {
        if (! isset(self::IMAGES_OFFICIELLES[$type])) {
            return null;
        }
        // Signature : d'abord celle définie dans Paramètres > Général
        if ($type === 'signature') {
            $fichier = basename(trim($this->parametres->brut('signature_autorite')));
            $chemin = $fichier !== '' ? $this->fichiers->dossier('logos').DIRECTORY_SEPARATOR.$fichier : null;
            if ($chemin && is_file($chemin)) {
                return $chemin;
            }
        }
        $uploads = config('gesnotes.dossier_uploads') ? rtrim(config('gesnotes.dossier_uploads'), '/\\') : null;
        if (! $uploads) {
            return null;
        }
        // Après la bascule : cachet.png / signature.png directement dans DOSSIER_UPLOADS ;
        // pendant la migration : emplacements de l'ancienne application (parent de uploads/).
        foreach (self::IMAGES_OFFICIELLES[$type] as $relatif) {
            foreach ([$uploads.DIRECTORY_SEPARATOR.basename($relatif), dirname($uploads).DIRECTORY_SEPARATOR.$relatif] as $chemin) {
                if (is_file($chemin)) {
                    return $chemin;
                }
            }
        }

        return null;
    }

    /** URL protégée du cachet ou de la signature, ou null s'il n'y en a pas. */
    public function urlImageOfficielle(string $type): ?string
    {
        return $this->cheminImageOfficielle($type) ? route('fichiers.officiel', $type) : null;
    }

    /** Ancien getSignatairePourFaculte() : signataire de la faculté, sinon global, sinon Paramètres. */
    public function signataire(?int $faculteId): array
    {
        $sig = $faculteId ? DB::table('signataires')->where('faculte_id', $faculteId)->where('actif', 1)->first() : null;
        $sig ??= DB::table('signataires')->where('actif', 1)->whereNull('faculte_id')->first();

        return $sig
            ? ['titre' => $sig->titre, 'nom' => $sig->nom, 'qualite' => $sig->qualite ?? '']
            : ['titre' => $this->parametres->brut('signataire_titre', 'Le Doyen/pi'), 'nom' => $this->parametres->brut('signataire_nom'), 'qualite' => $this->parametres->brut('signataire_qualite')];
    }

    /** Signataire des diplômes (Paramètres › Visas des diplômes), sinon celui de la faculté. */
    public function signataireDiplome(?int $faculteId): array
    {
        $titre = trim($this->parametres->brut('signataire_diplome_titre'));
        $nom = trim($this->parametres->brut('signataire_diplome_nom'));

        return $titre !== '' && $nom !== ''
            ? ['titre' => $titre, 'nom' => $nom, 'qualite' => trim($this->parametres->brut('signataire_diplome_qualite'))]
            : $this->signataire($faculteId);
    }

    /** Image téléversée désignée par un paramètre (ex. armoirie_niger), ou null. */
    public function imageParametre(string $cle): ?string
    {
        return $this->logoTeleverse($this->parametres->brut($cle));
    }

    private function logoTeleverse(string $fichier): ?string
    {
        $fichier = basename(trim($fichier));

        return ($fichier !== '' && is_file($this->fichiers->dossier('logos').DIRECTORY_SEPARATOR.$fichier))
            ? route('fichiers.logo', $fichier) : null;
    }

    private function logoPublic(string $fichier): ?string
    {
        return ($fichier !== '' && is_file(public_path('assets/img/'.basename($fichier)))) ? asset('assets/img/'.basename($fichier)) : null;
    }
}
