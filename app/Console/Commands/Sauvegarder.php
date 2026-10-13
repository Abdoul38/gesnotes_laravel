<?php

namespace App\Console\Commands;

use App\Services\AlerteSauvegarde;
use App\Services\ChaineJournauxRompue;
use App\Services\SauvegardesAuto;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sauvegarde automatique de toutes les bases (voir App\Services\SauvegardesAuto) :
 *   --type=complete        chaque base (mysqldump), puis suppression des sauvegardes trop anciennes ;
 *   --type=differentielle  journaux binaires depuis la dernière complète ;
 *   --type=incrementielle  journaux binaires depuis la dernière sauvegarde.
 *   --auto                 la sauvegarde qui aurait déjà dû être faite (SauvegardesAuto::aFaire) :
 *                          planifié toutes les 5 minutes, rattrape ce qui a été manqué (veille, arrêt,
 *                          MySQL arrêté) ; chaîne de journaux rompue → complète.
 * Puis copie hors du serveur si elle est configurée (gesnotes:copier-hors-serveur).
 * Serveur MySQL injoignable : panne notée (gesnotes:restaurer --liste) et signalée par e-mail à son retour.
 * En cas d'échec : journal du serveur et, si SAUVEGARDES_AUTO_ALERTE est
 * renseignée, e-mail par le serveur SMTP de l'université par défaut.
 */
class Sauvegarder extends Command
{
    protected $signature = 'gesnotes:sauvegarder
        {--type=complete : complete, differentielle ou incrementielle}
        {--auto : faire la sauvegarde due, le cas échéant (planifié toutes les 5 minutes ; rattrape les créneaux manqués)}
        {--universite=* : complète limitée à ces universités (toutes les actives et les espaces par défaut)}
        {--archive : complète à garder sans limite de durée (ex. fin d\'année après les délibérations)}
        {--sans-purge : ne pas supprimer les anciennes sauvegardes}';

    protected $description = 'Sauvegarde complète, différentielle ou incrémentielle des bases de production';

    public function handle(SauvegardesAuto $sauvegardes): int
    {
        // Serveur MySQL arrêté : panne notée (l'e-mail est impossible, ses réglages sont en base), signalée à son retour
        if ($erreur = $sauvegardes->serveurInjoignable()) {
            if ($sauvegardes->noterPanne($erreur)) {
                Log::error('Sauvegardes : serveur MySQL injoignable', ['erreur' => $erreur]);
            }
            $this->error('Serveur MySQL injoignable : sauvegarde impossible pour l\'instant ('.$erreur.')');

            return self::FAILURE;
        }
        if ($panne = $sauvegardes->finPanne()) {
            $this->signalerFinPanne($panne);
        }

        $auto = (bool) $this->option('auto');
        $type = $auto ? $sauvegardes->aFaire(now()) : (string) $this->option('type');
        if ($type === null) {
            $this->line('Sauvegardes à jour.');

            return self::SUCCESS;
        }
        try {
            $m = $sauvegardes->sauvegarder($type, $this->option('universite'), (bool) $this->option('archive'));
        } catch (ChaineJournauxRompue $e) {
            if (! $auto) {
                return $this->echec($type, $e->getMessage());
            }
            // Mode automatique : la chaîne repart d'une nouvelle complète
            $this->warn("$type impossible ({$e->getMessage()}) : complète à la place.");
            $type = 'complete';
            try {
                $m = $sauvegardes->sauvegarder($type);
            } catch (Throwable $e) {
                return $this->echec($type, $e->getMessage(), $auto);
            }
        } catch (Throwable $e) {
            return $this->echec($type, $e->getMessage(), $auto);
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
                if (! $auto || $sauvegardes->alertePermise('copie hors du serveur')) {
                    $this->alerter("$type (copie hors du serveur)", $e->getMessage(), 'gesnotes:copier-hors-serveur');
                }

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** Échec d'une sauvegarde : journal, message et alerte (en mode automatique, au plus une toutes les 3 h par type). */
    private function echec(string $type, string $erreur, bool $auto = false): int
    {
        Log::error("Sauvegarde $type échouée", ['erreur' => $erreur]);
        $this->error("Sauvegarde $type échouée : $erreur");
        if (! $auto || app(SauvegardesAuto::class)->alertePermise("sauvegarde $type")) {
            $this->alerter($type, $erreur);
        }

        return self::FAILURE;
    }

    private function signalerFinPanne(array $panne): void
    {
        $depuis = Carbon::parse($panne['depuis']);
        $this->warn('Serveur MySQL de nouveau joignable (injoignable depuis le '.$depuis->format('d/m/Y à H:i').', '.$panne['tentatives'].' tentative(s) manquée(s)) : sauvegardes rattrapées.');
        Log::warning('Sauvegardes : serveur MySQL de nouveau joignable', $panne);
        app(AlerteSauvegarde::class)->envoyer('⚠️ Sauvegardes interrompues : serveur MySQL arrêté',
            '<p>Le serveur MySQL était injoignable du <strong>'.$depuis->format('d/m/Y à H:i').'</strong> au <strong>'
            .Carbon::parse($panne['derniere'])->format('d/m/Y à H:i').'</strong> ('.(int) $panne['tentatives'].' tentative(s) de sauvegarde manquée(s)).</p>'
            .'<p>Il répond de nouveau : les sauvegardes manquées sont rattrapées automatiquement, sans perte (les modifications faites entre-temps sont dans la prochaine sauvegarde).</p>'
            .'<p>Erreur : <code>'.e($panne['erreur']).'</code></p>'
            .'<p>Pour éviter cela : faire démarrer MySQL avec Windows (panneau XAMPP en administrateur, case « Svc » de MySQL).</p>');
    }

    private function alerter(string $type, string $erreur, ?string $relancer = null): void
    {
        app(AlerteSauvegarde::class)->envoyer("⚠️ Sauvegarde $type échouée",
            '<p>La sauvegarde <strong>'.e($type).'</strong> du '.now()->format('d/m/Y à H:i').' a échoué :</p><p><code>'.e($erreur).'</code></p>'
            .'<p>Vérifier le serveur, puis relancer : <code>php artisan '.e($relancer ?? 'gesnotes:sauvegarder --type='.$type).'</code></p>');
    }
}
