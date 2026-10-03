<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Parcours validé d'un étudiant : semestres validés par cycle, moyenne et
 * mention d'un document (attestations, diplômes, liste des éligibles).
 *
 * Reprend les règles des anciens attestations.php, diplome.php et
 * diplomes.php, qui en avaient chacun leur version :
 *  - premier cycle (Licence, DUT, LP) et Master séparés : ils réutilisent
 *    les mêmes codes S1… ;
 *  - un semestre compte s'il est validé (résultat enregistré, non
 *    défaillant) ; pour un même semestre, la session normale passe avant le
 *    rattrapage, puis l'année la plus récente ;
 *  - moyenne du document = moyenne des semestres concernés.
 *
 * Différences voulues :
 *  - plus de revérification « en direct » avec une règle de défaillance
 *    propre (même raison que pour les relevés, étape 5) ;
 *  - la liste des éligibles et les documents choisissent le même semestre
 *    (la liste prenait la meilleure moyenne, les documents la session
 *    normale : la moyenne affichée pouvait différer du document) ;
 *  - le diplôme de Licence ne peut plus être complété par des semestres de
 *    Master (diplome.php ne séparait pas les cycles) ;
 *  - mention selon les seuils de l'université (paramètres mention_*, 16 / 14
 *    / 12 par défaut, comme avant).
 */
class Parcours
{
    /** Documents : cycle et semestres requis. */
    public const DOCUMENTS = [
        'validation_l2' => ['premier', ['S1', 'S2', 'S3', 'S4']],
        'licence' => ['premier', ['S1', 'S2', 'S3', 'S4', 'S5', 'S6']],
        'master1' => ['master', ['S1', 'S2']],
        'master_complet' => ['master', ['S1', 'S2', 'S3', 'S4']],
        'dut' => ['premier', ['S1', 'S2', 'S3', 'S4']],
        'master' => ['master', ['S1', 'S2', 'S3', 'S4']],
    ];

    private const NIVEAUX_MASTER = ['M1', 'M2'];

    private const NIVEAUX_DUT = ['DUT1', 'DUT2'];

    public function __construct(private readonly Parametres $parametres) {}

    /**
     * @return array{premier: array<string, object>, master: array<string, object>, niveaux: list<string>}
     */
    public function semestres(int $etudiantId): array
    {
        return $this->semestresDe(collect([$etudiantId]))[$etudiantId] ?? ['premier' => [], 'master' => [], 'niveaux' => []];
    }

    /** Même chose pour plusieurs étudiants en une requête (liste des éligibles). */
    public function semestresDe(Collection $etudiants): array
    {
        $lignes = DB::table('resultats_semestre as rs')
            ->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('niveaux as nv', 'nv.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->whereIn('rs.etudiant_id', $etudiants)->where('rs.valide', 1)->where('rs.defaillant', 0)
            ->whereIn('s.code', ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'])
            ->orderByRaw("CASE ps.type_session WHEN 'normale' THEN 0 ELSE 1 END")->orderByDesc('a.libelle')->orderByDesc('rs.pv_id')
            ->get(['rs.etudiant_id', 'rs.pv_id', 'rs.moyenne_generale', 'rs.credits_obtenus', 's.code as sem_code', 'a.libelle as annee', 'a.id as annee_id',
                'nv.code as niveau', 'nv.libelle as niveau_libelle', 'ps.type_session', 'f.id as filiere_id', 'f.libelle as filiere', 'f.faculte_id',
                'f.domaine', 'f.mention_academique', 'f.type_formation']);

        $parcours = [];
        foreach ($lignes as $l) {
            $p = &$parcours[$l->etudiant_id];
            $p ??= ['premier' => [], 'master' => [], 'niveaux' => []];
            $cycle = in_array($l->niveau, self::NIVEAUX_MASTER, true) ? 'master' : 'premier';
            $p[$cycle][$l->sem_code] ??= $l;   // lignes déjà triées : normale, puis la plus récente
            if ($cycle === 'premier' && ! in_array($l->niveau, $p['niveaux'], true)) {
                $p['niveaux'][] = $l->niveau;
            }
            unset($p);
        }

        return $parcours;
    }

    /**
     * Bilan d'un document, ou null s'il manque un semestre (le semestre
     * manquant est alors renvoyé dans $manquant).
     */
    public function bilan(array $parcours, string $document, ?string &$manquant = null): ?array
    {
        [$cycle, $requis] = self::DOCUMENTS[$document];
        $sems = $parcours[$cycle];
        foreach ($requis as $code) {
            if (! isset($sems[$code])) {
                $manquant = $code;

                return null;
            }
        }
        if ($document === 'dut' && ! array_intersect(self::NIVEAUX_DUT, $parcours['niveaux'])) {
            $manquant = 'DUT';

            return null;
        }
        $retenus = array_map(fn ($c) => $sems[$c], $requis);
        $moyennes = array_filter(array_map(fn ($s) => $s->moyenne_generale, $retenus), fn ($m) => $m !== null);
        $moyenne = $moyennes ? round(array_sum($moyennes) / count($moyennes), 2) : null;
        $dernier = end($retenus);

        return [
            'semestres' => $retenus,
            'moyenne' => $moyenne,
            'mention' => $this->mention($moyenne),
            'dernier' => $dernier,
            'annee' => $dernier->annee,
            'filiere' => $dernier->filiere,
            'professionnelle' => $dernier->type_formation === 'professionnelle',
            'cycle_dut' => (bool) array_intersect(self::NIVEAUX_DUT, $parcours['niveaux']),
        ];
    }

    /** Mention des documents (capitales des anciens documents : « Très Bien »). */
    public function mention(?float $moyenne): string
    {
        return match (true) {
            $moyenne === null => 'Passable',
            $moyenne >= (float) $this->parametres->valeur('mention_tres_bien', '16') => 'Très Bien',
            $moyenne >= (float) $this->parametres->valeur('mention_bien', '14') => 'Bien',
            $moyenne >= (float) $this->parametres->valeur('mention_assez_bien', '12') => 'Assez Bien',
            default => 'Passable',
        };
    }
}
