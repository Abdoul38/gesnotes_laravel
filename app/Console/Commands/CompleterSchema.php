<?php

namespace App\Console\Commands;

use App\Support\SchemaReference;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Crée dans chaque base d'université les tables du schéma de référence qui
 * y manquent (l'ancienne application en créait certaines seulement quand
 * une page s'en servait la première fois, ex. mobile_reset_codes).
 *
 * Sans --appliquer : simple liste. Avec : uniquement des
 * CREATE TABLE IF NOT EXISTS — aucune table existante n'est modifiée ni
 * supprimée. Une table qui en remplace une ancienne reçoit, à sa création
 * seulement, une copie des données de celle-ci (REPRISES), laissée intacte.
 */
class CompleterSchema extends Command
{
    /** Copie des données d'une ancienne table dans la nouvelle, juste après sa création. */
    private const REPRISES = [
        // Planning des examens : par PV avant, par promotion, semestre et session maintenant
        'planning_examens' => "INSERT IGNORE INTO planning_examens (promotion_id, semestre_id, session, matiere_id, date_examen, heure_debut, heure_fin,
                salle, salle_id, surveillant_1, surveillant_2, notes)
            SELECT ps.promotion_id, ps.semestre_id, ps.type_session, ep.matiere_id, ep.date_examen, ep.heure_debut, ep.heure_fin,
                ep.salle, ep.salle_id, ep.surveillant_1, ep.surveillant_2, ep.notes
            FROM examens_planning ep JOIN pv_sessions ps ON ps.id = ep.pv_id WHERE ps.type_session IN ('normale', 'rattrapage')",
    ];

    protected $signature = 'gesnotes:completer-schema
        {--universite=* : code(s) d\'université (toutes les actives par défaut)}
        {--appliquer : crée vraiment les tables manquantes}';

    protected $description = 'Liste (ou crée avec --appliquer) les tables du schéma de référence absentes des bases d\'université';

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $creations = SchemaReference::creations();
        $codes = $this->option('universite');
        $universites = $codes ? array_filter(array_map(fn ($c) => $registre->trouver($c), $codes)) : $registre->actives();
        if (! $universites) {
            $this->error('Aucune université trouvée.');

            return self::FAILURE;
        }
        $echecs = 0;
        foreach ($universites as $u) {
            try {
                $courante->pour($u, function () use ($u, $creations, &$echecs) {
                    $base = DB::connection()->getDatabaseName();
                    $existantes = array_map(fn ($t) => $t->n, DB::select('SELECT table_name AS n FROM information_schema.tables WHERE table_schema = DATABASE()'));
                    $manquantes = array_values(array_diff(array_keys($creations), $existantes));
                    if (! $manquantes) {
                        $this->line("<fg=green>✔</> {$u->sigle} ($base) : complète");

                        return;
                    }
                    $this->line("<fg=yellow>!</> {$u->sigle} ($base) : manquantes — ".implode(', ', $manquantes));
                    if (! $this->option('appliquer')) {
                        return;
                    }
                    foreach ($manquantes as $table) {
                        try {
                            DB::statement(preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $creations[$table]));
                            $this->line("    créée : $table");
                            if (isset(self::REPRISES[$table])) {
                                $this->line('      reprise : '.DB::affectingStatement(self::REPRISES[$table]).' ligne(s)');
                            }
                        } catch (Throwable $e) {
                            $echecs++;
                            $this->line("    <fg=red>échec : $table</> — ".$e->getMessage());
                        }
                    }
                });
            } catch (Throwable $e) {
                $echecs++;
                $this->line("<fg=red>✘</> {$u->sigle} : base injoignable — ".$e->getMessage());
            }
        }
        if (! $this->option('appliquer')) {
            $this->newLine();
            $this->line('Rien n\'a été modifié. Relancer avec --appliquer pour créer les tables manquantes (après une sauvegarde).');
        }

        return $echecs ? self::FAILURE : self::SUCCESS;
    }
}
