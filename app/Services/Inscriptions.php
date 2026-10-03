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
