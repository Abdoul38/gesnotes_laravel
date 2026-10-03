<?php

namespace App\Services;

use App\Support\Tableur;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement des notes d'un PV, commun à l'import de la scolarité et au
 * portail enseignant : mêmes bornes, même règle de rattrapage (meilleure note
 * finale entre les deux sessions), même lecture des fichiers.
 */
class SaisieNotes
{
    public function __construct(private readonly Pv $pvs) {}

    /** Matières actives du PV (même promotion et même semestre). */
    public function matieres(object $pv): Collection
    {
        return DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
            ->where(['ue.promotion_id' => $pv->promotion_id, 'ue.semestre_id' => $pv->semestre_id, 'm.actif' => 1])
            ->orderBy('ue.ordre')->orderBy('m.ordre')
            ->get(['m.id', 'm.code', 'm.libelle', 'm.note_max_examen', 'm.note_max_cc', 'm.note_max_totale', 'm.finalisee', 'ue.code as ue_code', 'ue.libelle as ue_libelle']);
    }

    /**
     * Enregistre les notes [étudiant][matière] = [examen, TD]. En rattrapage,
     * la meilleure note entre 1ère session et rattrapage est gardée.
     */
    public function enregistrer(object $pv, array $saisies, int $utilisateur): int
    {
        $matieres = $this->matieres($pv)->keyBy('id');
        $normale = ($pv->type_session === 'rattrapage' && $pv->pv_normale_id)
            ? DB::table('notes')->where('pv_id', $pv->pv_normale_id)->get()->groupBy('etudiant_id')->map->keyBy('matiere_id') : collect();
        $lignes = [];
        foreach ($saisies as $et => $mats) {
            foreach ($mats as $mid => [$exam, $td]) {
                $avecTd = (float) $matieres[$mid]->note_max_cc > 0;
                // Note finale seulement si l'examen ET le TD prévu sont saisis
                $finale = ($exam !== null && (! $avecTd || $td !== null)) ? $exam + ($td ?? 0) : null;
                $n1 = $normale[$et][$mid] ?? null;
                if ($n1 && $n1->note_finale !== null) {
                    $garderSession1 = $finale === null ? (float) $n1->note_finale != 0.0 : (float) $n1->note_finale > $finale;
                    if ($garderSession1) {
                        [$exam, $td, $finale] = [$n1->note_examen, $n1->note_cc, (float) $n1->note_finale];
                    }
                }
                $lignes[] = ['pv_id' => $pv->id, 'etudiant_id' => $et, 'matiere_id' => $mid, 'note_examen' => $exam, 'note_cc' => $td,
                    'note_finale' => $finale, 'defaillant' => 0, 'saisie_par' => $utilisateur, 'updated_at' => now()];
            }
        }
        foreach (array_chunk($lignes, 500) as $paquet) {
            DB::table('notes')->upsert($paquet, ['pv_id', 'etudiant_id', 'matiere_id'], ['note_examen', 'note_cc', 'note_finale', 'defaillant', 'saisie_par', 'updated_at']);
        }

        return count($lignes);
    }

    /**
     * Saisie d'UNE composante (examen ou TD) d'une matière, l'autre restant
     * celle déjà enregistrée — l'enseignant de cours et celui de TD saisissent
     * séparément. [étudiant => note], null = absent (rattrapage : matière
     * défaillante). Note finale seulement quand l'examen et le TD prévu sont
     * là ; en rattrapage, la meilleure note finale des deux sessions est
     * gardée, comme à l'import. Retourne les étudiants enregistrés.
     */
    public function enregistrerComposante(object $pv, object $mat, string $type, array $valeurs, int $utilisateur): array
    {
        $existantes = DB::table('notes')->where(['pv_id' => $pv->id, 'matiere_id' => $mat->id])->whereIn('etudiant_id', array_keys($valeurs))->get()->keyBy('etudiant_id');
        $session1 = ($pv->type_session === 'rattrapage' && $pv->pv_normale_id)
            ? DB::table('notes')->where(['pv_id' => $pv->pv_normale_id, 'matiere_id' => $mat->id])->get()->keyBy('etudiant_id') : collect();
        $avecTd = (float) $mat->note_max_cc > 0;
        $lignes = [];
        foreach ($valeurs as $et => $valeur) {
            $n = $existantes[$et] ?? null;
            $exam = $type === 'exam' ? $valeur : ($n?->note_examen !== null ? (float) $n->note_examen : null);
            $td = $type === 'cc' ? $valeur : ($n?->note_cc !== null ? (float) $n->note_cc : null);
            $finale = ($valeur !== null && $exam !== null && (! $avecTd || $td !== null)) ? $exam + ($td ?? 0) : null;
            $n1 = $session1[$et] ?? null;
            if ($finale !== null && $n1 && $n1->note_finale !== null && (float) $n1->note_finale > $finale) {
                [$exam, $td, $finale] = [$n1->note_examen, $n1->note_cc, (float) $n1->note_finale];
            }
            $lignes[] = ['pv_id' => $pv->id, 'etudiant_id' => $et, 'matiere_id' => $mat->id, 'note_examen' => $exam, 'note_cc' => $td,
                'note_finale' => $finale, 'defaillant' => $valeur === null ? 1 : 0, 'saisie_par' => $utilisateur, 'updated_at' => now()];
        }
        foreach (array_chunk($lignes, 500) as $paquet) {
            DB::table('notes')->upsert($paquet, ['pv_id', 'etudiant_id', 'matiere_id'], ['note_examen', 'note_cc', 'note_finale', 'defaillant', 'saisie_par', 'updated_at']);
        }

        return array_keys($valeurs);
    }

    /** Une valeur saisie : null si vide, false si invalide ou hors de 0 à max. */
    public function note(mixed $brut, float $max): float|false|null
    {
        $v = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], trim((string) $brut));
        if ($v === '' || $v === '—' || $v === '-') {
            return null;
        }
        if (! is_numeric($v) || (float) $v < 0 || (float) $v > $max) {
            return false;
        }

        return round((float) $v, 2);
    }

    /** « étudiant-matière » acquis (AV/AC) dans un PV. */
    public function acquises(int $pvId): Collection
    {
        return DB::table('notes')->where('pv_id', $pvId)->whereIn('observation', ['AV', 'AC'])
            ->get(['etudiant_id', 'matiere_id'])->map(fn ($n) => $n->etudiant_id.'-'.$n->matiere_id)->flip();
    }

    /**
     * Rattrapage : un étudiant du PV sans note dans le fichier pour une
     * matière qu'il n'avait pas acquise en 1ère session est absent — note
     * vide, matière défaillante. Retourne les étudiants concernés.
     */
    public function marquerAbsents(object $pv, array $matieres, array $saisies, int $utilisateur): array
    {
        $acquisesS1 = $this->acquises((int) $pv->pv_normale_id);
        $absents = [];
        foreach ($this->pvs->etudiants($pv)->pluck('e.id') as $et) {
            foreach ($matieres as $mid) {
                if (isset($saisies[$et][$mid]) || $acquisesS1->has($et.'-'.$mid)) {
                    continue;
                }
                DB::table('notes')->upsert([['pv_id' => $pv->id, 'etudiant_id' => $et, 'matiere_id' => $mid, 'note_examen' => null, 'note_cc' => null,
                    'note_finale' => null, 'defaillant' => 1, 'saisie_par' => $utilisateur]], ['pv_id', 'etudiant_id', 'matiere_id'],
                    ['note_examen', 'note_cc', 'note_finale', 'defaillant']);
                $absents[$et] = true;
            }
        }

        return array_keys($absents);
    }

    /** @return array{0: ?float, 1: ?float, 2: ?string} examen, TD, erreur */
    public function valeurs(array $ligne, int|string $iExam, int|string|null $iTd, object $mat): array
    {
        $lire = function (int|string|null $i, float $max, string $nom) use ($ligne) {
            if ($i === null) {
                return [null, null];
            }
            $v = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], trim((string) ($ligne[$i] ?? '')));
            if ($v === '' || $v === '—' || $v === '-' || ! is_numeric($v)) {
                return [null, null];
            }
            if ((float) $v < 0 || (float) $v > $max) {
                return [null, "note $nom $v hors de 0 à $max"];
            }

            return [round((float) $v, 2), null];
        };
        [$exam, $e1] = $lire($iExam, (float) $mat->note_max_examen, 'examen');
        [$td, $e2] = $lire($iTd, (float) $mat->note_max_cc, 'TD');

        return [$exam, $td, $e1 ?? $e2];
    }

    /** @return array<int, list<string>> */
    public function lire(string $chemin, string $extension): array
    {
        if ($extension === 'xlsx') {
            return Tableur::xlsx($chemin);
        }
        $texte = Tableur::texteCsv($chemin);
        $lignes = array_values(array_filter(explode("\n", $texte), fn ($l) => trim($l) !== ''));
        $echantillon = implode("\n", array_slice($lignes, 0, 5));
        $sep = substr_count($echantillon, "\t") > max(substr_count($echantillon, ';'), substr_count($echantillon, ',')) ? "\t"
            : (substr_count($echantillon, ',') > substr_count($echantillon, ';') ? ',' : ';');
        $resultat = [];
        foreach ($lignes as $i => $l) {
            // Séparateur autre que la virgule : « 12,5 » est une décimale
            $resultat[$i + 1] = str_getcsv($sep !== ',' ? preg_replace('/(\d),(\d)/', '$1.$2', $l) : $l, $sep, '"', '');
        }

        return $resultat;
    }

    /** Étudiants du PV indexés par matricule. */
    public function parMatricule(object $pv): array
    {
        return $this->pvs->etudiants($pv)->get(['e.id', 'e.numero', 'e.nom'])->keyBy(fn ($e) => (string) $e->numero)->all();
    }

    public function trouver(array $index, string $matricule): ?object
    {
        return $index[$matricule] ?? $index[ltrim($matricule, '0')] ?? $index[str_pad(ltrim($matricule, '0'), 5, '0', STR_PAD_LEFT)] ?? null;
    }
}
