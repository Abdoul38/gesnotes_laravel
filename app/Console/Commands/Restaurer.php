<?php

namespace App\Console\Commands;

use App\Services\SauvegardesAuto;
use App\Universites\RegistreUniversites;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Restauration d'une base à partir des sauvegardes automatiques :
 * complète + dernière différentielle + incrémentielles suivantes, journaux
 * binaires rejoués dans l'ordre, éventuellement jusqu'à une date et heure.
 *
 * Sans --appliquer : le plan seulement (rien n'est modifié).
 * Par défaut la base est restaurée À CÔTÉ (<base>_restauration), pour
 * vérifier ou récupérer des données ; --remplacer restaure dans la base de
 * production elle-même (site à mettre en maintenance avant : php artisan
 * down), puis fait aussitôt une nouvelle sauvegarde complète.
 */
class Restaurer extends Command
{
    protected $signature = 'gesnotes:restaurer
        {--liste : liste des sauvegardes disponibles}
        {--universite= : code de l\'université à restaurer}
        {--espaces : restaurer la base des espaces (ministère, ANAB)}
        {--jusqua= : date et heure à retrouver (ex. "2026-10-12 14:30") ; par défaut la dernière sauvegarde}
        {--journaux-serveur : ajouter les journaux binaires encore sur le serveur (modifications non encore sauvegardées)}
        {--vers= : base de destination (défaut : <base>_restauration)}
        {--remplacer : restaurer dans la base de production elle-même}
        {--appliquer : effectuer la restauration (sinon : plan seulement)}';

    protected $description = 'Restaure une base (complète + différentielle + incrémentielles), éventuellement jusqu\'à une date';

    public function handle(SauvegardesAuto $sauvegardes, RegistreUniversites $registre): int
    {
        try {
            if ($this->option('liste')) {
                return $this->liste($sauvegardes);
            }
            $base = $this->option('espaces') ? config('gesnotes.espaces_base') : $registre->trouver((string) $this->option('universite'))?->base;
            if (! filled($base)) {
                $this->error('Préciser --universite=<code> ou --espaces.');

                return self::FAILURE;
            }
            $jusqua = filled($this->option('jusqua')) ? Carbon::parse($this->option('jusqua')) : null;
            $cible = $this->option('remplacer') ? $base : ($this->option('vers') ?: $base.'_restauration');
            $production = array_keys($sauvegardes->bases());
            if (in_array($cible, $production, true) && ! ($this->option('remplacer') && $cible === $base)) {
                $this->error("$cible est une base de production : --remplacer est nécessaire (et seulement pour la base restaurée).");

                return self::FAILURE;
            }

            $plan = $sauvegardes->plan($base, $jusqua, (bool) $this->option('journaux-serveur'));
            $this->info("Restauration de $base → $cible".($jusqua ? ' (état du '.$jusqua->format('d/m/Y H:i:s').')' : ''));
            $this->line('  1. complète       '.$plan['complete']['id']);
            if ($plan['differentielle']) {
                $this->line('  2. différentielle '.$plan['differentielle']['id']);
            }
            foreach ($plan['incrementielles'] as $i) {
                $this->line('  +  incrémentielle '.$i['id']);
            }
            if ($plan['journaux']) {
                $serveur = count(array_filter($plan['journaux'], fn ($j) => $j['serveur']));
                $this->line('  journaux binaires : '.$plan['journaux'][0]['nom'].' → '.end($plan['journaux'])['nom']
                    .' (depuis la position '.$plan['position']['position'].')'.($serveur ? ", dont $serveur lu(s) sur le serveur" : ''));
            }
            foreach ($plan['avertissements'] as $a) {
                $this->warn('  ! '.$a);
            }
            if (! $this->option('appliquer')) {
                $this->newLine();
                $this->line('Rien n\'a été modifié. Relancer avec --appliquer pour restaurer.');

                return self::SUCCESS;
            }
            if ($cible === $base) {
                $this->warn("  La base de production $base va être remplacée (site en maintenance : php artisan down).");
            }

            $sauvegardes->restaurer($plan, $base, $cible, $jusqua, fn ($etape) => $this->line("  … $etape"));
            $this->info("✔ $cible restaurée.");
            if ($cible === $base) {
                // Nouvelle chaîne : les sauvegardes suivantes partent de la base restaurée
                $this->call('gesnotes:sauvegarder', ['--type' => 'complete', '--sans-purge' => true]);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function liste(SauvegardesAuto $sauvegardes): int
    {
        $lignes = array_map(fn ($m) => [$m['id'], $m['type'], $m['statut'], Carbon::parse($m['fin'])->format('d/m/Y H:i'),
            $m['type'] === 'complete' ? count($m['bases'] ?? []).' base(s)' : count($m['journaux'] ?? []).' journal(aux)',
            ! empty($m['archive']) ? 'archive' : '', $m['erreur'] ?? ''], $sauvegardes->catalogue(true));
        $this->table(['Sauvegarde', 'Type', 'Statut', 'Fin', 'Contenu', '', 'Erreur'], $lignes);
        $copie = $sauvegardes->etat()['hors_serveur'] ?? null;
        $this->line($copie ? 'Dernière copie hors du serveur : '.Carbon::parse($copie['date'])->format('d/m/Y H:i').' — '.$copie['statut'].($copie['erreur'] ? ' ('.$copie['erreur'].')' : '')
            : ($sauvegardes->horsServeurConfiguree() ? 'Aucune copie hors du serveur pour l\'instant.' : 'Copie hors du serveur non configurée.'));
        $usb = $sauvegardes->etat()['support'] ?? null;
        $message = $usb ? 'Dernière copie sur clé USB : '.Carbon::parse($usb['date'])->format('d/m/Y H:i').' — '.$usb['statut'].($usb['erreur'] ? ' ('.$usb['erreur'].')' : '')
            : 'Aucune copie sur clé USB pour l\'instant (gesnotes:copier-usb E:).';
        $ancienne = ! $usb || $usb['statut'] !== 'ok' || Carbon::parse($usb['date'])->lessThan(now()->subDays(CopierUsb::JOURS));
        $ancienne ? $this->warn($message) : $this->line($message);

        return self::SUCCESS;
    }
}
