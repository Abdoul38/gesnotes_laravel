<?php

namespace App\Console\Commands;

use App\Services\AlerteSauvegarde;
use App\Services\SauvegardesAuto;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Copie mensuelle des sauvegardes sur une clé USB (ou un disque externe),
 * à débrancher et ranger ensuite : hors de portée d'un piratage du serveur
 * ou du compte Google. Chiffrée (remote « usb » de rclone, mêmes mots de
 * passe que la copie en ligne) et relue après la copie.
 *   gesnotes:copier-usb E:              copie (invite de commandes administrateur)
 *   gesnotes:copier-usb E: --recuperer  rapatriement avant une restauration
 *   gesnotes:copier-usb --rappel        e-mail si la dernière copie date de plus de 28 jours (planifié)
 */
class CopierUsb extends Command
{
    public const JOURS = 28;

    protected $signature = 'gesnotes:copier-usb
        {support? : lettre du lecteur de la clé (ex. E:) ou dossier sur le support}
        {--recuperer : rapatrier les sauvegardes depuis le support}
        {--rappel : prévenir par e-mail si la dernière copie est trop ancienne}
        {--meme-disque : accepter un dossier sur le même disque que les sauvegardes (essais)}';

    protected $description = 'Copie chiffrée des sauvegardes sur une clé USB (ou les rapatrie avec --recuperer)';

    public function handle(SauvegardesAuto $sauvegardes, AlerteSauvegarde $alerte): int
    {
        if ($this->option('rappel')) {
            return $this->rappel($sauvegardes, $alerte);
        }
        $support = (string) $this->argument('support');
        if ($support === '') {
            $this->error('Préciser la lettre du lecteur de la clé USB, ex. : php artisan gesnotes:copier-usb E:');

            return self::FAILURE;
        }
        try {
            if ($this->option('recuperer')) {
                $sauvegardes->recupererDepuisSupport($support);
                $this->info('✔ Sauvegardes rapatriées depuis '.$support.' — voir gesnotes:restaurer --liste');

                return self::SUCCESS;
            }
            $this->line("Copie vers $support (chiffrée), puis relecture complète…");
            $r = $sauvegardes->copierSurSupport($support, (bool) $this->option('meme-disque'));
            $this->info(sprintf('✔ Sauvegardes copiées et vérifiées sur %s (%.1f Mo).', $r['dossier'], $r['octets'] / 1048576));
            $this->line('  Éjecter la clé (« Retirer le périphérique en toute sécurité »), puis la ranger hors du bâtiment du serveur.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function rappel(SauvegardesAuto $sauvegardes, AlerteSauvegarde $alerte): int
    {
        $derniere = $sauvegardes->etat()['support'] ?? null;
        $date = ($derniere['statut'] ?? null) === 'ok' ? Carbon::parse($derniere['date']) : null;
        if ($date && $date->greaterThan(now()->subDays(self::JOURS))) {
            $this->line('Dernière copie sur clé USB : '.$date->format('d/m/Y').' — pas de rappel.');

            return self::SUCCESS;
        }
        $depuis = $date ? 'La dernière copie date du '.$date->format('d/m/Y').'.' : 'Aucune copie sur clé USB n\'a encore été faite.';
        $alerte->envoyer('💾 Copie mensuelle sur clé USB à faire',
            '<p>'.e($depuis).'</p><p>Brancher la clé USB des sauvegardes, puis dans une invite de commandes <strong>administrateur</strong> :</p>'
            .'<p><code>cd C:\\xampp00\\gesnotes-laravel &amp;&amp; php artisan gesnotes:copier-usb E:</code></p>'
            .'<p>(remplacer E: par la lettre de la clé), puis éjecter la clé et la ranger hors du bâtiment du serveur.</p>');
        $this->warn('Rappel envoyé : '.$depuis);

        return self::SUCCESS;
    }
}
