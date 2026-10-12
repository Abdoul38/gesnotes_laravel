<?php

namespace App\Console\Commands;

use App\Services\AlerteSauvegarde;
use App\Services\SauvegardesAuto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sauvegarde automatique de toutes les bases (voir App\Services\SauvegardesAuto) :
 *   --type=complete        chaque base (mysqldump), puis suppression des sauvegardes trop anciennes ;
 *   --type=differentielle  journaux binaires depuis la dernière complète ;
 *   --type=incrementielle  journaux binaires depuis la dernière sauvegarde.
 * Puis copie hors du serveur si elle est configurée (gesnotes:copier-hors-serveur).
 * En cas d'échec : journal du serveur et, si SAUVEGARDES_AUTO_ALERTE est
 * renseignée, e-mail par le serveur SMTP de l'université par défaut.
 */
class Sauvegarder extends Command
{
    protected $signature = 'gesnotes:sauvegarder
        {--type=complete : complete, differentielle ou incrementielle}
        {--universite=* : complète limitée à ces universités (toutes les actives et les espaces par défaut)}
        {--archive : complète à garder sans limite de durée (ex. fin d\'année après les délibérations)}
        {--sans-purge : ne pas supprimer les anciennes sauvegardes}';

    protected $description = 'Sauvegarde complète, différentielle ou incrémentielle des bases de production';

    public function handle(SauvegardesAuto $sauvegardes): int
    {
        $type = (string) $this->option('type');
        try {
            $m = $sauvegardes->sauvegarder($type, $this->option('universite'), (bool) $this->option('archive'));
        } catch (Throwable $e) {
            Log::error("Sauvegarde $type échouée", ['erreur' => $e->getMessage()]);
            $this->error("Sauvegarde $type échouée : ".$e->getMessage());
            $this->alerter($type, $e->getMessage());

            return self::FAILURE;
        }

        $this->info("✔ Sauvegarde $type {$m['id']}");
        foreach ($m['bases'] ?? [] as $base => $b) {
            if (isset($b['fichier'])) {
                $this->line(sprintf('  %-28s %8.1f Ko%s', $base, $b['octets'] / 1024, $b['journal'] ? "  (journal {$b['journal']['fichier']}:{$b['journal']['position']})" : ''));
            }
        }
        if (isset($m['journaux'])) {
            $depuis = $type === 'differentielle' ? 'la complète '.$m['complete'] : $m['precedente'];
            $this->line('  '.count($m['journaux'])." journal(aux) binaire(s) : {$m['du']} → {$m['jusqua']} (depuis $depuis)");
        }
        if ($type === 'complete') {
            if (empty(array_filter(array_column($m['bases'], 'journal')))) {
                $this->warn('  Journal binaire désactivé : différentielles et incrémentielles impossibles (voir BASCULE.md).');
            }
            if (! $this->option('sans-purge') && ($supprimees = $sauvegardes->purger())) {
                $this->line('  Anciennes sauvegardes supprimées : '.implode(', ', $supprimees));
            }
        }

        // Copie hors du serveur : une sauvegarde restée sur le serveur disparaît avec lui
        if ($sauvegardes->horsServeurConfiguree()) {
            try {
                $sauvegardes->copierHorsServeur();
                $this->line('  Copiée hors du serveur ('.config('gesnotes.sauvegardes_auto.hors_serveur.destination').')');
            } catch (Throwable $e) {
                Log::error('Copie hors du serveur échouée', ['erreur' => $e->getMessage()]);
                $this->error('  Copie hors du serveur échouée : '.$e->getMessage());
                $this->alerter("$type (copie hors du serveur)", $e->getMessage(), 'gesnotes:copier-hors-serveur');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function alerter(string $type, string $erreur, ?string $relancer = null): void
    {
        app(AlerteSauvegarde::class)->envoyer("⚠️ Sauvegarde $type échouée",
            '<p>La sauvegarde <strong>'.e($type).'</strong> du '.now()->format('d/m/Y à H:i').' a échoué :</p><p><code>'.e($erreur).'</code></p>'
            .'<p>Vérifier le serveur, puis relancer : <code>php artisan '.e($relancer ?? 'gesnotes:sauvegarder --type='.$type).'</code></p>');
    }
}
