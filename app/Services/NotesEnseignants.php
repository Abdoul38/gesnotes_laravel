<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notes saisies par l'enseignant dans son portail, en deux temps :
 *  - « Enregistrer » : les notes sont gardées (notes_enseignants) mais
 *    n'apparaissent pas dans le PV global, ni dans les résultats, relevés,
 *    portails étudiant et parent ;
 *  - « Finaliser » : les notes passent dans le PV (mêmes règles que la saisie
 *    directe, rattrapage compris), les résultats sont recalculés et la
 *    composante est verrouillée pour l'enseignant. Seuls l'administration, le
 *    chef de département ou le doyen la rouvrent ; les notes déjà finalisées
 *    restent alors dans le PV jusqu'à la finalisation suivante.
 *
 * La finalisation est propre à chaque PV (normale, rattrapage) et à chaque
 * composante (examen, TD) : les enseignants de cours et de TD finalisent
 * chacun la leur. matieres.finalisee (suivi du ministère) reste à jour pour
 * la session normale.
 *
 * Reprise de l'existant : une matière déjà finalisée par l'ancienne règle,
 * sans finalisation par composante enregistrée, reste finalisée pour son PV
 * de session normale.
 */
class NotesEnseignants
{
    public const COMPOSANTES = ['exam' => 'Examen', 'cc' => 'TD'];

    public function __construct(private readonly SaisieNotes $saisie, private readonly Resultats $resultats) {}

    public function disponible(): bool
    {
        return Schema::hasTable('notes_enseignants') && Schema::hasTable('notes_finalisations');
    }

    /** Composantes d'une matière : examen, et TD s'il est prévu. */
    public static function composantes(object $mat): array
    {
        return (float) $mat->note_max_cc > 0 ? ['exam', 'cc'] : ['exam'];
    }

    /** Composantes finalisées de la matière dans ce PV. */
    public function finalisees(object $pv, object $mat): array
    {
        $faites = DB::table('notes_finalisations')->where(['pv_id' => $pv->id, 'matiere_id' => $mat->id])->pluck('composante')->all();
        if (! $faites && $pv->type_session === 'normale' && ! empty($mat->finalisee)
            && ! DB::table('notes_enseignants')->where(['pv_id' => $pv->id, 'matiere_id' => $mat->id])->exists()) {
            return self::composantes($mat);   // finalisée avant la finalisation par composante
        }

        return $faites;
    }

    public function finalisee(object $pv, object $mat, string $composante): bool
    {
        return in_array($composante, $this->finalisees($pv, $mat), true);
    }

    /** Notes en attente de finalisation : [étudiant => note|null]. */
    public function brouillons(int $pvId, int $matId, string $composante): Collection
    {
        return DB::table('notes_enseignants')->where(['pv_id' => $pvId, 'matiere_id' => $matId, 'composante' => $composante])
            ->pluck('note', 'etudiant_id')->map(fn ($n) => $n === null ? null : (float) $n);
    }

    /**
     * Garde les notes saisies sans les mettre dans le PV. Case vide : en
     * session normale, pas de note en attente (la note du PV reste) ; au
     * rattrapage, absent.
     */
    public function enregistrer(object $pv, object $mat, string $composante, array $valeurs, int $utilisateur): void
    {
        $lignes = [];
        $vides = [];
        foreach ($valeurs as $et => $v) {
            if ($v === null && $pv->type_session !== 'rattrapage') {
                $vides[] = (int) $et;
            } else {
                $lignes[] = ['pv_id' => $pv->id, 'etudiant_id' => (int) $et, 'matiere_id' => $mat->id, 'composante' => $composante,
                    'note' => $v, 'saisie_par' => $utilisateur, 'maj_le' => now()];
            }
        }
        DB::transaction(function () use ($pv, $mat, $composante, $lignes, $vides) {
            if ($vides) {
                DB::table('notes_enseignants')->where(['pv_id' => $pv->id, 'matiere_id' => $mat->id, 'composante' => $composante])->whereIn('etudiant_id', $vides)->delete();
            }
            foreach (array_chunk($lignes, 500) as $paquet) {
                DB::table('notes_enseignants')->upsert($paquet, ['pv_id', 'matiere_id', 'composante', 'etudiant_id'], ['note', 'saisie_par', 'maj_le']);
            }
        });
    }

    /** Met les notes en attente dans le PV, recalcule les résultats, verrouille les composantes. Retourne le nombre de notes publiées. */
    public function finaliser(object $pv, object $mat, array $composantes, int $utilisateur): int
    {
        return DB::transaction(function () use ($pv, $mat, $composantes, $utilisateur) {
            $nb = 0;
            $deja = $this->finalisees($pv, $mat);
            foreach (array_intersect(self::composantes($mat), $composantes) as $c) {
                if (in_array($c, $deja, true)) {
                    continue;
                }
                $valeurs = $this->brouillons((int) $pv->id, (int) $mat->id, $c)->all();
                if ($valeurs) {
                    app(HistoriqueNotes::class)->suivre((int) $pv->id, 'finalisation par l\'enseignant', function () use ($pv, $mat, $c, $valeurs, $utilisateur) {
                        foreach ($this->saisie->enregistrerComposante($pv, $mat, $c, $valeurs, $utilisateur) as $et) {
                            $this->resultats->recalculer((int) $pv->id, (int) $et);
                        }
                    }, array_keys($valeurs));
                    $nb += count($valeurs);
                }
                DB::table('notes_enseignants')->where(['pv_id' => $pv->id, 'matiere_id' => $mat->id, 'composante' => $c])->delete();
                DB::table('notes_finalisations')->insert(['pv_id' => $pv->id, 'matiere_id' => $mat->id, 'composante' => $c,
                    'finalise_par' => $utilisateur, 'finalise_le' => now()]);
            }
            $this->suivre($pv, $mat, $utilisateur);

            return $nb;
        });
    }

    /** Rouvre les composantes : l'enseignant peut de nouveau saisir (notes du PV inchangées). */
    public function rouvrir(object $pv, object $mat, array $composantes): void
    {
        DB::transaction(function () use ($pv, $mat, $composantes) {
            if (! DB::table('notes_finalisations')->where(['pv_id' => $pv->id, 'matiere_id' => $mat->id])->exists() && $this->finalisees($pv, $mat)) {
                // Finalisée par l'ancienne règle : les autres composantes restent finalisées
                foreach (array_diff(self::composantes($mat), $composantes) as $c) {
                    DB::table('notes_finalisations')->insert(['pv_id' => $pv->id, 'matiere_id' => $mat->id, 'composante' => $c, 'finalise_le' => now()]);
                }
            }
            DB::table('notes_finalisations')->where(['pv_id' => $pv->id, 'matiere_id' => $mat->id])->whereIn('composante', $composantes)->delete();
            $this->suivre($pv, $mat, null);
        });
    }

    /** matieres.finalisee : toutes les composantes finalisées en session normale. */
    private function suivre(object $pv, object $mat, ?int $utilisateur): void
    {
        if ($pv->type_session !== 'normale') {
            return;
        }
        $faites = DB::table('notes_finalisations')->where(['pv_id' => $pv->id, 'matiere_id' => $mat->id])->pluck('composante')->all();
        $complete = ! array_diff(self::composantes($mat), $faites);
        DB::table('matieres')->where('id', $mat->id)->update($complete
            ? ['finalisee' => 1, 'finalisee_par' => $utilisateur, 'finalisee_le' => now()]
            : ['finalisee' => 0, 'finalisee_par' => null, 'finalisee_le' => null]);
        $mat->finalisee = $complete ? 1 : 0;
    }
}
