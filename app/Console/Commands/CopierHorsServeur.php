<?php

namespace App\Console\Commands;

use App\Services\SauvegardesAuto;
use Illuminate\Console\Command;
use Throwable;

/**
 * Copie des sauvegardes automatiques hors du serveur (rclone, destination
 * chiffrée, ex. Google Drive), faite aussi après chaque gesnotes:sauvegarder.
 * --recuperer : rapatrie les sauvegardes de la destination dans le dossier
 * local (serveur perdu), avant gesnotes:restaurer.
 */
class CopierHorsServeur extends Command
{
    protected $signature = 'gesnotes:copier-hors-serveur
        {--recuperer : rapatrier les sauvegardes depuis la destination}';

    protected $description = 'Copie les sauvegardes automatiques hors du serveur (ou les rapatrie avec --recuperer)';

    public function handle(SauvegardesAuto $sauvegardes): int
    {
        try {
            if ($this->option('recuperer')) {
                $sauvegardes->recupererHorsServeur();
                $this->info('✔ Sauvegardes rapatriées dans '.$sauvegardes->dossier().' — voir gesnotes:restaurer --liste');

                return self::SUCCESS;
            }
            $sauvegardes->copierHorsServeur();
            $this->info('✔ Sauvegardes copiées vers '.config('gesnotes.sauvegardes_auto.hors_serveur.destination'));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
