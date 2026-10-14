<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Lignes d'un relevé de notes (releve_notes et aperçu de releves), tirées
 * des notes et observations enregistrées par le moteur de résultats.
 */
class Releves
{
    /**
     * @param  Collection  $ues  Pv::ues()
     * @param  array  $choix  Pv::choixOptions()
     * @param  Collection  $notes  notes de l'étudiant dans le PV, indexées par matiere_id
     * @return array<int, array{ue: object, matieres: Collection}>
     */
    public function lignes(Collection $ues, array $choix, Collection $notes, int $etudiantId): array
    {
        $lignes = [];
        foreach ($ues as $ue) {
            // Une option non choisie par l'étudiant n'apparaît pas sur son relevé
            $mats = $ue->matieres->reject(fn ($m) => (int) $ue->nb_options_a_choisir > 0 && $m->type_matiere === 'optionnelle'
                && ! in_array((int) $m->id, $choix[$etudiantId][$ue->id] ?? [], true));
            $lignes[] = ['ue' => $ue, 'matieres' => $mats->map(function ($m) use ($notes) {
                $n = $notes->get($m->id);
                $obs = in_array($n->observation ?? null, ['AV', 'AC', 'NV', 'Elim', 'Déf.'], true) ? $n->observation : '—';
                $finale = $n?->note_finale ?? ($n && $n->note_examen !== null ? $n->note_examen + ($n->note_cc ?? 0) : null);
                $moy20 = $obs !== 'Déf.' && $finale !== null && $m->note_max_totale > 0 ? round($finale / $m->note_max_totale * 20, 2) : null;

                return ['m' => $m, 'n' => $n, 'obs' => $obs, 'finale' => $finale, 'moy20' => $moy20,
                    'credits' => in_array($obs, ['AV', 'AC'], true) ? (int) $m->credits : 0];
            })->values()];
        }

        return $lignes;
    }
}
