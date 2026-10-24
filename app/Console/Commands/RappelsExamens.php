<?php

namespace App\Console\Commands;

use App\Services\Communication\AlertesParents;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rappel aux parents des examens du lendemain, dans chaque université
 * (planifiée chaque jour à 18 h quand RAPPELS_EXAMENS=true, sur le serveur
 * de production seulement). Un seul message par examen et par parent.
 */
class RappelsExamens extends Command
{
    protected $signature = 'gesnotes:rappels-examens {--universite=* : code(s) d\'université (toutes les actives par défaut)}';

    protected $description = 'Prévient les parents des examens du lendemain (SMS / e-mail)';

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $codes = $this->option('universite');
        $universites = $codes ? array_filter(array_map(fn ($c) => $registre->trouver($c), $codes)) : $registre->actives();
        foreach ($universites as $u) {
            try {
                $nb = $courante->pour($u, fn () => app(AlertesParents::class)->rappelsExamens());
                $this->line("{$u->sigle} : $nb rappel(s) envoyé(s)");
            } catch (Throwable $e) {
                $this->line("<fg=red>{$u->sigle} : échec — {$e->getMessage()}</>");
            }
        }

        return self::SUCCESS;
    }
}
