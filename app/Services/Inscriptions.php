<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Règles communes aux inscriptions (scolarité, imports, passage, transfert).
 */
class Inscriptions
{
    /** Modes de paiement admis par la base (inscriptions et paiements). */
    public const MODES = ['especes' => 'Espèces', 'mobile_money' => 'Mobile Money', 'virement' => 'Virement', 'cheque' => 'Chèque'];

    /** Frais d'inscription inscrits au paiement (valeur fixe de l'ancienne application). */
    public const FRAIS = 10000;

    public function __construct(private readonly Maquette $maquette) {}

    /**
     * Mode de paiement saisi → valeur de la base, ou null s'il est inconnu.
     * « MyNITA » et « Amana » (proposés par d'anciens formulaires mais absents
     * de la base, l'enregistrement échouait) sont des paiements mobiles.
     */
    public static function mode(?string $saisie): ?string
    {
        $mode = str_replace([' ', '-'], '_', mb_strtolower(trim((string) $saisie)));
        $mode = match ($mode) {
            'mynita', 'amana', 'mobile', 'mobilemoney' => 'mobile_money',
            'espèces', 'espece', 'espèce' => 'especes',
            'chèque' => 'cheque',
            default => $mode,
        };

        return isset(self::MODES[$mode]) ? $mode : null;
    }

    /** Promotion filière × niveau × année, créée au besoin avec la maquette de la précédente. */
    public function promotion(int $filiereId, int $niveauId, int $anneeId): int
    {
        $id = DB::table('promotions')->where(['filiere_id' => $filiereId, 'niveau_id' => $niveauId, 'annee_id' => $anneeId])->value('id');
        if (! $id) {
            $id = DB::table('promotions')->insertGetId(['filiere_id' => $filiereId, 'niveau_id' => $niveauId, 'annee_id' => $anneeId]);
            $this->maquette->copierUEs($id);
        }

        return (int) $id;
    }

    /** Codes des semestres (S1…) validés par l'étudiant, toutes années confondues. */
    public function semestresValides(int $etudiantId): array
    {
        return DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
            ->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->where('rs.etudiant_id', $etudiantId)->where('rs.valide', 1)->whereIn('ps.type_session', ['normale', 'rattrapage'])
            ->distinct()->orderBy('s.code')->pluck('s.code')->all();
    }

    /**
     * Semestres à suggérer pour l'inscription pédagogique, d'après les
     * semestres validés (règles de l'ancienne inscription pédagogique). En
     * Droit, un étudiant qui n'a validé qu'un semestre de L1 suit aussi S3 et S4.
     *
     * L'inscription en masse avait sa propre copie de ces règles, qui
     * suggérait S1 + S2 à un étudiant ayant validé toute la licence : une
     * seule version maintenant.
     *
     * @param  list<string>  $valides
     * @return list<string>
     */
    public static function semestresSuggeres(array $valides, bool $droit): array
    {
        $v = fn (string $s) => in_array($s, $valides, true);
        if (! $v('S1') && ! $v('S2')) {
            return ['S1', 'S2'];
        }
        if ($v('S1') && ! $v('S2')) {
            return $droit ? ['S2', 'S3', 'S4'] : ['S2', 'S3'];
        }
        if (! $v('S1')) {
            return $droit ? ['S1', 'S3', 'S4'] : ['S1', 'S4'];
        }

        return match (true) {
            ! $v('S3') && ! $v('S4') => ['S3', 'S4'],
            $v('S3') && ! $v('S4') => ['S4'],
            ! $v('S3') && $v('S4') => ['S3'],
            default => ['S5', 'S6'],
        };
    }

    /** Filière de Droit (règles de suggestion propres) : libellé ou code contenant « droit ». */
    public static function estDroit(?string $libelle, ?string $code = null): bool
    {
        return stripos((string) $libelle, 'droit') !== false || stripos((string) $code, 'droit') !== false;
    }

    /** Décision de passage la plus récente de l'étudiant pour l'année d'arrivée. */
    public function passage(int $etudiantId, int $anneeId): ?object
    {
        return DB::table('passages_niveau as pn')->join('niveaux as n', 'n.id', '=', 'pn.niveau_suivant_id')
            ->leftJoin('filieres as f', 'f.id', '=', 'pn.filiere_id')
            ->where('pn.etudiant_id', $etudiantId)->where('pn.annee_arrivee_id', $anneeId)
            ->orderByDesc('pn.date_decision')->orderByDesc('pn.id')
            ->first(['pn.niveau_suivant_id', 'pn.decision', 'pn.filiere_id', 'n.code as niveau_code', 'n.libelle as niveau_lib',
                'f.libelle as filiere_lib', 'f.faculte as filiere_faculte']);
    }
}
