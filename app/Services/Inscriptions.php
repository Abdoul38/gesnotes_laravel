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

    /**
     * Décision de passage d'après le niveau actuel et les semestres validés.
     * Retourne [niveau suivant, décision enregistrée, statut affiché].
     *
     * L'ancienne application avait quatre versions de cette règle
     * (enregistrement et affichage, page individuelle et page en masse) qui
     * se contredisaient : une licence validée était affichée « Licence
     * validée » mais enregistrée « redoublement » (page individuelle), un
     * étudiant de LP était déclaré diplômé dès S1 + S2 (validés en DUT1), la
     * page individuelle ignorait les parcours IUT. Règle unique :
     *  - licence (L1, L2, L3, DUT1, DUT2, LP) : S1 à S6 validés → licence validée
     *    (niveau L3, ou LP pour le parcours IUT) ;
     *  - L1 / DUT1 : S1 à S4 → L3 / LP ; S1 + S2 → L2 / DUT2 ; sinon redoublement ;
     *  - L2 / DUT2 : S3 + S4 → L3 / LP ; sinon redoublement ;
     *  - L3, LP : pas de passage automatique (nouveau cycle) ;
     *  - M1 : S1 + S2 → M2 ; M2 : jamais de niveau supérieur (« Master
     *    validé » affiché si S3 + S4, enregistré comme redoublement : la base
     *    n'a pas d'autre valeur) ;
     *  - autre niveau : redoublement.
     *
     * @param  list<string>  $valides
     * @return array{0: string, 1: string, 2: string}
     */
    public static function decisionPassage(string $niveau, array $valides): array
    {
        $v = fn (string ...$s) => ! array_diff($s, $valides);
        $iut = in_array($niveau, ['DUT1', 'DUT2', 'LP'], true);
        $fin = $iut ? 'LP' : 'L3';

        if (in_array($niveau, ['L1', 'L2', 'L3', 'DUT1', 'DUT2', 'LP'], true) && $v('S1', 'S2', 'S3', 'S4', 'S5', 'S6')) {
            return [$fin, 'licence_validee', 'licence_validee'];
        }

        return match ($niveau) {
            'L1', 'DUT1' => match (true) {
                $v('S1', 'S2', 'S3', 'S4') => [$fin, 'passage', 'passage'],
                $v('S1', 'S2') => [$iut ? 'DUT2' : 'L2', 'passage', 'passage'],
                default => [$niveau, 'redoublement', 'redoublement'],
            },
            'L2', 'DUT2' => $v('S3', 'S4') ? [$fin, 'passage', 'passage'] : [$niveau, 'redoublement', 'redoublement'],
            'M1' => $v('S1', 'S2') ? ['M2', 'passage', 'passage'] : ['M1', 'redoublement', 'redoublement'],
            'M2' => ['M2', 'redoublement', $v('S3', 'S4') ? 'master_valide' : 'redoublement'],
            default => [$niveau, 'redoublement', 'redoublement'],
        };
    }

    /** [fond, couleur, libellé] d'un statut de passage, pour l'affichage. */
    public static function styleStatut(?string $statut): array
    {
        return match ($statut) {
            'passage' => ['#dbeafe', '#1e40af', '📈 Passage'],
            'licence_validee' => ['#dcfce7', '#166534', '🎓 Licence validée'],
            'master_valide' => ['#d1fae5', '#065f46', '🎓 Master validé'],
            default => ['#fef3c7', '#92400e', '🔄 Redoublement'],
        };
    }

    /**
     * Filières proposées pour une spécialisation (L3 ou LP) : les filières
     * « filles » de la filière actuelle ayant une promotion à ce niveau, à
     * défaut celles de la même faculté.
     */
    public function filieresSpecialisation(int $filiereId, string $niveau)
    {
        $base = fn () => DB::table('filieres as f2')->join('promotions as p2', 'p2.filiere_id', '=', 'f2.id')
            ->join('niveaux as n2', fn ($j) => $j->on('n2.id', '=', 'p2.niveau_id')->where('n2.code', $niveau))
            ->where('f2.actif', 1)->distinct()->orderBy('f2.libelle')->select('f2.id', 'f2.libelle', 'f2.code');

        $filles = $base()->where('f2.filiere_parente_id', $filiereId)->get();
        if ($filles->isNotEmpty()) {
            return $filles;
        }
        $actuelle = DB::table('filieres')->where('id', $filiereId)->first(['faculte', 'faculte_id']);

        return $base()->when($actuelle?->faculte_id, fn ($q) => $q->where('f2.faculte_id', $actuelle->faculte_id),
            fn ($q) => $q->when($actuelle?->faculte, fn ($q) => $q->where('f2.faculte', $actuelle->faculte)))->get();
    }

    /** Enregistre (ou remplace) une décision de passage — aucune inscription n'est créée. */
    public function enregistrerPassage(int $etudiantId, int $filiereId, int $niveauActuel, int $niveauSuivant, int $anneeDepart, int $anneeArrivee, string $decision): void
    {
        DB::table('passages_niveau')->upsert([[
            'etudiant_id' => $etudiantId, 'filiere_id' => $filiereId, 'niveau_actuel_id' => $niveauActuel,
            'niveau_suivant_id' => $niveauSuivant, 'annee_depart_id' => $anneeDepart, 'annee_arrivee_id' => $anneeArrivee,
            'decision' => $decision, 'date_decision' => now()->toDateString(),
        ]], ['etudiant_id', 'annee_arrivee_id', 'filiere_id'], ['niveau_suivant_id', 'decision', 'date_decision']);
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
