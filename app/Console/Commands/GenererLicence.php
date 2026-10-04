<?php

namespace App\Console\Commands;

use App\Services\Licence;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Émet la licence d'une université (remplace l'ancien outils/generer_licence),
 * signée avec LICENCE_SECRET. La licence porte le code de l'université :
 * elle n'est valable que pour elle.
 *
 * Sans --installer : affiche la clé (à coller dans Paramètres › Licence).
 * Avec : l'enregistre aussi dans la base de cette université (paramètre
 * licence_cle), après confirmation.
 */
class GenererLicence extends Command
{
    protected $signature = 'gesnotes:licence
        {universite : code de l\'université (ex. udo)}
        {--expiration= : date de fin AAAA-MM-JJ (illimitée si absente)}
        {--max=0 : nombre maximal d\'étudiants (0 = illimité)}
        {--installer : enregistre la licence dans la base de l\'université}';

    protected $description = 'Génère (et éventuellement installe) la licence d\'une université';

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante, Licence $licence): int
    {
        $u = $registre->trouver(strtolower((string) $this->argument('universite')));
        if (! $u || ! $u->estUniversite()) {
            $this->error('Université inconnue.');

            return self::FAILURE;
        }
        if (config('licence.secret') === '') {
            $this->error('LICENCE_SECRET est vide dans le .env : impossible de signer une licence.');

            return self::FAILURE;
        }
        $expiration = (string) $this->option('expiration');
        if ($expiration !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiration)) {
            $this->error('Date d\'expiration attendue au format AAAA-MM-JJ.');

            return self::FAILURE;
        }
        $cle = $licence->generer(strtoupper($u->code), $expiration, max(0, (int) $this->option('max')));
        $this->line("Licence {$u->sigle} : $cle");

        if ($this->option('installer')) {
            if (! $this->confirm("Enregistrer cette licence dans la base de {$u->sigle} ?", true)) {
                return self::SUCCESS;
            }
            $courante->pour($u, function () use ($cle, $licence, $u) {
                DB::table('parametres')->upsert([['cle' => 'licence_cle', 'valeur' => $cle, 'label' => 'Clé de licence', 'groupe' => 'licence']], ['cle'], ['valeur']);
                $statut = $licence->verifierInstallation();
                $this->line($statut['valide'] ? "<fg=green>✔</> Licence installée et valide pour {$u->sigle}." : '<fg=red>✘</> Installée mais refusée : '.$statut['erreur']);
            });
        }

        return self::SUCCESS;
    }
}
