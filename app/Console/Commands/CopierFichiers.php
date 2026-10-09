<?php

namespace App\Console\Commands;

use App\Support\FichierEnv;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sort les fichiers de l'ancienne application (ANCIENNE_APP_CHEMIN) pour
 * qu'elle puisse être retirée :
 *  - uploads/ (photos, logos, documents…)        → <destination>/uploads
 *  - assets/img/cachet.* et signature.*            → <destination>/uploads (s'ils n'y sont pas)
 *  - candidature/uploads/pieces/ (pièces candidats) → <destination>/pieces-candidats
 *  - candidature/assets/img/ (carrousel du portail) → <destination>/images-portail
 *  - pages/backups/<base>_*.sql (anciennes sauvegardes) → dossier des sauvegardes de leur université
 * puis renseigne DOSSIER_UPLOADS, DOSSIER_PIECES_CANDIDATS et
 * DOSSIER_IMAGES_PORTAIL dans le .env.
 *
 * COPIE seulement : rien n'est supprimé de l'ancienne application (qui peut
 * encore servir) ; un fichier déjà copié à l'identique n'est pas recopié —
 * la commande peut être relancée juste avant la bascule pour prendre les
 * fichiers ajoutés entre-temps. Sans --appliquer : simple constat.
 */
class CopierFichiers extends Command
{
    protected $signature = 'gesnotes:copier-fichiers
        {--destination= : dossier de destination (défaut : storage/app/private/fichiers)}
        {--appliquer : copie les fichiers et met à jour le .env}';

    protected $description = 'Copie les fichiers de l\'ancienne application hors de celle-ci et met le .env à jour';

    private array $totaux = ['copies' => 0, 'identiques' => 0, 'octets' => 0];

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $this->totaux = ['copies' => 0, 'identiques' => 0, 'octets' => 0];
        $ancienne = rtrim(str_replace('\\', '/', (string) config('gesnotes.ancienne_app_chemin')), '/');
        if ($ancienne === '' || ! is_dir($ancienne)) {
            $this->error('ANCIENNE_APP_CHEMIN absent ou introuvable : rien à copier.');

            return self::FAILURE;
        }
        $destination = rtrim(str_replace('\\', '/', (string) ($this->option('destination') ?: storage_path('app/private/fichiers'))), '/');
        if (str_starts_with($destination.'/', $ancienne.'/') || str_starts_with($destination.'/', str_replace('\\', '/', public_path()).'/')) {
            $this->error('La destination doit être hors de l\'ancienne application et hors du dossier public.');

            return self::FAILURE;
        }
        $appliquer = (bool) $this->option('appliquer');
        $this->line(($appliquer ? 'Copie' : 'Constat (rien n\'est copié)')." : $ancienne → $destination");

        $this->dossier("$ancienne/uploads", "$destination/uploads", 'Fichiers déposés (uploads)', $appliquer);
        foreach (['cachet', 'signature'] as $image) {
            foreach (['png', 'jpg'] as $ext) {
                $source = "$ancienne/assets/img/$image.$ext";
                if (is_file($source) && ! is_file("$destination/uploads/$image.$ext") && ! is_file("$ancienne/uploads/$image.$ext")) {
                    $this->fichier($source, "$destination/uploads/$image.$ext", $appliquer);
                    $this->line("  $image.$ext (assets/img) → uploads");
                }
            }
        }
        $this->dossier("$ancienne/candidature/uploads/pieces", "$destination/pieces-candidats", 'Pièces des candidats', $appliquer);
        $this->dossier("$ancienne/candidature/assets/img", "$destination/images-portail", 'Images du portail candidats', $appliquer);
        $this->sauvegardes($ancienne, $registre, $courante, $appliquer);

        $this->newLine();
        $this->line("{$this->totaux['copies']} fichier(s) ".($appliquer ? 'copié(s)' : 'à copier').' ('.round($this->totaux['octets'] / 1048576, 1)." Mo), {$this->totaux['identiques']} déjà à jour.");
        if (! $appliquer) {
            $this->line('Relancer avec --appliquer pour copier et mettre le .env à jour.');

            return self::SUCCESS;
        }
        $env = app()->environmentFilePath();
        foreach (['DOSSIER_UPLOADS' => 'uploads', 'DOSSIER_PIECES_CANDIDATS' => 'pieces-candidats', 'DOSSIER_IMAGES_PORTAIL' => 'images-portail'] as $cle => $sous) {
            FichierEnv::definir($env, $cle, "$destination/$sous");
        }
        if (app()->configurationIsCached()) {
            $this->callSilently('config:clear');
        }
        $this->info("DOSSIER_UPLOADS, DOSSIER_PIECES_CANDIDATS et DOSSIER_IMAGES_PORTAIL pointent maintenant vers $destination (.env mis à jour). L'ancienne application garde ses propres fichiers.");

        return self::SUCCESS;
    }

    private function dossier(string $source, string $cible, string $libelle, bool $appliquer): void
    {
        if (! is_dir($source)) {
            $this->line("  $libelle : dossier source absent ($source)");

            return;
        }
        $avant = $this->totaux;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $this->fichier($f->getPathname(), $cible.'/'.str_replace('\\', '/', substr($f->getPathname(), strlen($source) + 1)), $appliquer);
            }
        }
        $this->line("  $libelle : ".($this->totaux['copies'] - $avant['copies']).' à copier/copié(s), '.($this->totaux['identiques'] - $avant['identiques']).' déjà à jour');
    }

    private function fichier(string $source, string $cible, bool $appliquer): void
    {
        if (is_file($cible) && filesize($cible) === filesize($source) && filemtime($cible) >= filemtime($source)) {
            $this->totaux['identiques']++;

            return;
        }
        $this->totaux['copies']++;
        $this->totaux['octets'] += (int) filesize($source);
        if ($appliquer) {
            if (! is_dir(dirname($cible))) {
                mkdir(dirname($cible), 0750, true);
            }
            copy($source, $cible);
            touch($cible, (int) filemtime($source));
        }
    }

    /** Anciennes sauvegardes (pages/backups, dossier commun) : rangées par université d'après le nom de leur base. */
    private function sauvegardes(string $ancienne, RegistreUniversites $registre, UniversiteCourante $courante, bool $appliquer): void
    {
        $fichiers = glob("$ancienne/pages/backups/*.sql") ?: [];
        if (! $fichiers) {
            return;
        }
        $bases = [];
        foreach ($registre->actives() as $u) {
            try {
                $courante->pour($u, function () use (&$bases, $u) {
                    $bases[DB::connection()->getDatabaseName()] = preg_replace('/[^a-z0-9_-]/', '', strtolower($u->code));
                });
            } catch (Throwable) {
            }
        }
        uksort($bases, fn ($a, $b) => strlen($b) - strlen($a)); // gesnotes_uam avant gesnotes
        $racine = rtrim(str_replace('\\', '/', (string) (config('gesnotes.dossier_sauvegardes') ?: storage_path('app/private/sauvegardes'))), '/');
        foreach ($fichiers as $f) {
            $nom = basename($f);
            $code = null;
            foreach ($bases as $base => $c) {
                if (preg_match('/^'.preg_quote($base, '/').'_\d{4}-\d{2}-\d{2}/', $nom)) {
                    $code = $c;
                    break;
                }
            }
            if ($code === null) {
                $this->line("  Sauvegarde $nom : université non reconnue, laissée en place");

                continue;
            }
            $this->fichier($f, "$racine/$code/$nom", $appliquer);
            $this->line("  Sauvegarde $nom → sauvegardes/$code");
        }
    }
}
