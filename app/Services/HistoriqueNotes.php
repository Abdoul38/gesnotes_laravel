<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Traçabilité des modifications de notes dans les journaux d'activité :
 * pour chaque note DÉJÀ saisie qui change (examen, TD, note finale,
 * absence) ou qui est supprimée, une ligne « ancienne → nouvelle valeur »
 * avec l'étudiant, la matière, le PV et l'origine (saisie du PV, import,
 * portail enseignant, réclamation…). Les premières saisies (case vide →
 * note) ne sont pas détaillées : elles restent résumées par l'action
 * d'origine.
 */
class HistoriqueNotes
{
    private const CHAMPS = ['note_examen' => 'Examen', 'note_cc' => 'TD', 'note_finale' => 'Note finale'];

    public function __construct(private readonly Journal $journal) {}

    /**
     * Exécute $operation et journalise les notes du PV qu'elle a modifiées.
     *
     * @param  list<int>|null  $etudiants  limiter la comparaison à ces étudiants (null : tout le PV)
     */
    public function suivre(int $pvId, string $origine, callable $operation, ?array $etudiants = null): mixed
    {
        $avant = $this->etat($pvId, $etudiants);
        $resultat = $operation();
        $this->journaliser($pvId, $origine, $avant, $this->etat($pvId, $etudiants));

        return $resultat;
    }

    private function etat(int $pvId, ?array $etudiants): Collection
    {
        return DB::table('notes')->where('pv_id', $pvId)->when($etudiants !== null, fn ($q) => $q->whereIn('etudiant_id', $etudiants ?: [0]))
            ->get(['etudiant_id', 'matiere_id', 'note_examen', 'note_cc', 'note_finale', 'defaillant'])
            ->keyBy(fn ($n) => $n->etudiant_id.'-'.$n->matiere_id);
    }

    private function journaliser(int $pvId, string $origine, Collection $avant, Collection $apres): void
    {
        $changements = [];
        foreach ($avant as $cle => $a) {
            if ($a->note_examen === null && $a->note_cc === null && $a->note_finale === null && ! $a->defaillant) {
                continue;   // case vide : première saisie, pas une modification
            }
            $n = $apres->get($cle);
            $details = [];
            foreach (self::CHAMPS as $champ => $libelle) {
                $v1 = $this->valeur($a->$champ);
                $v2 = $n ? $this->valeur($n->$champ) : null;
                // Note finale recalculée seule (examen et TD inchangés, elle était vide) : conséquence, pas une modification
                if ($v1 !== $v2 && ! ($champ === 'note_finale' && $v1 === null)) {
                    $details[] = "$libelle ".($v1 ?? '—').' → '.($v2 ?? '—');
                }
            }
            if ($n && (bool) $a->defaillant !== (bool) $n->defaillant) {
                $details[] = $n->defaillant ? 'marqué absent' : 'n\'est plus absent';
            }
            // Ligne supprimée, ou vidée (le recalcul des résultats peut la recréer sans valeur)
            if (! $n || ($n->note_examen === null && $n->note_cc === null && $n->note_finale === null && ! $n->defaillant)) {
                $details = ['note supprimée ('.implode(', ', array_filter(array_map(
                    fn ($c, $l) => $a->$c !== null ? "$l ".$this->valeur($a->$c) : null, array_keys(self::CHAMPS), self::CHAMPS))).')'];
            }
            if ($details) {
                $changements[] = [(int) $a->etudiant_id, (int) $a->matiere_id, $details];
            }
        }
        if (! $changements) {
            return;
        }
        $etudiants = DB::table('etudiants')->whereIn('id', array_column($changements, 0))->get(['id', 'nom', 'prenoms', 'numero'])->keyBy('id');
        $matieres = DB::table('matieres')->whereIn('id', array_column($changements, 1))->pluck('libelle', 'id');
        $pv = (string) DB::table('pv_sessions')->where('id', $pvId)->value('libelle');
        foreach ($changements as [$et, $mat, $details]) {
            $e = $etudiants->get($et);
            $qui = $e ? trim("{$e->nom} {$e->prenoms}").($e->numero ? " ({$e->numero})" : '') : "étudiant #$et";
            $this->journal->action('modifier_note', 'notes',
                "Note modifiée — $qui — ".($matieres[$mat] ?? "matière #$mat")." — $pv : ".implode(' ; ', $details)." [$origine]", $et);
        }
    }

    /** 12.50 → « 12,5 » ; null reste null. */
    private function valeur(mixed $v): ?string
    {
        return $v === null ? null : str_replace('.', ',', rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'));
    }
}
