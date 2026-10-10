<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moteur de calcul des résultats — portage À L'IDENTIQUE des fonctions
 * recalculerResultats(), matiereEstDefaillante(), reporterNotes(),
 * recalculerRangs() et getRegleAnnee() de l'ancien includes/functions.php.
 *
 * Toute différence de résultat avec l'ancienne application serait une
 * régression : le service est vérifié par comparaison avec l'ancien code
 * sur une copie des données réelles (commande gesnotes:comparer-resultats)
 * et par les tests tests/Feature/Notes/ResultatsTest.php.
 *
 * Règles (paramètres par université, figeables par année) :
 *  - matière défaillante : examen absent, TD absent s'il est prévu, ou note
 *    sous la note éliminatoire (sur 20) ; une note reportée ou déjà validée
 *    en session normale n'est jamais rejugée ;
 *  - UE défaillante si une matière l'est ou si sa moyenne est sous la note
 *    éliminatoire d'UE ;
 *  - compensation intra-UE : moyenne d'UE ≥ seuil et aucune défaillance ;
 *  - compensation inter-UE : moyenne générale ≥ seuil, aucune défaillance,
 *    exactement une UE non validée ;
 *  - semestre validé : tous les crédits, aucune défaillance ;
 *  - décision d'un semestre non validé avec défaillance : « Défaillant » si
 *    une note manque (case vide), sinon « Elim » (note ou moyenne d'UE
 *    éliminatoire) ; même distinction pour l'observation d'UE (« Déf. » /
 *    « Elim », voir observationUe()).
 */
class Resultats
{
    /** Règles figeables par année, avec leur valeur par défaut. */
    public const REGLES = [
        'seuil_validation' => '10',
        'note_eliminatoire' => '5',
        'note_eliminatoire_ue' => '5',
        'compensation_ue' => 'oui',
        'compensation_semestre' => 'oui',
    ];

    private const MENTIONS = ['mention_tres_bien' => '16', 'mention_bien' => '14', 'mention_assez_bien' => '12'];

    private bool $parametresInitialises = false;

    public function __construct(private readonly Parametres $parametres) {}

    /** Ancien getRegleAnnee() : valeur figée pour l'année, sinon règle globale. */
    public function regle(string $cle, ?int $anneeId, string $defaut): string
    {
        if ($anneeId) {
            $v = DB::table('parametres_annee')->where('cle', $cle)->where('annee_id', $anneeId)->value('valeur');
            if ($v !== null) {
                return (string) $v;
            }
        }

        return $this->parametres->brut($cle, $defaut);
    }

    /** Ancien getMentionConfigurable() (appelé seulement pour un étudiant admis). */
    public function mention(float $moyenne): string
    {
        return match (true) {
            $moyenne >= (float) $this->parametres->brut('mention_tres_bien', '16') => 'Très bien',
            $moyenne >= (float) $this->parametres->brut('mention_bien', '14') => 'Bien',
            $moyenne >= (float) $this->parametres->brut('mention_assez_bien', '12') => 'Assez bien',
            default => 'Passable',
        };
    }

    /**
     * Ancien matiereEstDefaillante().
     *
     * @return array{defaillant: bool, note_finale: ?float, motif: string}
     */
    public static function matiereDefaillante(?array $note, array $matiere, float $seuilEliminatoireSur20, bool $dejaValideeAvant = false): array
    {
        $finale = fn () => isset($note['note_finale']) && $note['note_finale'] !== null ? (float) $note['note_finale'] : null;
        if ($dejaValideeAvant) {
            return ['defaillant' => false, 'note_finale' => $finale(), 'motif' => 'deja_validee'];
        }
        if ($note && ! empty($note['reportee'])) {
            return ['defaillant' => false, 'note_finale' => $finale(), 'motif' => 'reportee'];
        }
        $avecTd = (float) ($matiere['note_max_cc'] ?? 0) > 0;
        if ($note === null || ($note['note_examen'] ?? null) === null) {
            return ['defaillant' => true, 'note_finale' => $finale(), 'motif' => 'examen_manquant'];
        }
        if ($avecTd && ($note['note_cc'] ?? null) === null) {
            return ['defaillant' => true, 'note_finale' => $finale(), 'motif' => 'td_manquant'];
        }
        $seuil = ($matiere['note_max_totale'] > 0 && $seuilEliminatoireSur20 > 0) ? ($matiere['note_max_totale'] * $seuilEliminatoireSur20 / 20) : 0;
        if ($seuil > 0 && $finale() !== null && $finale() < $seuil) {
            return ['defaillant' => true, 'note_finale' => $finale(), 'motif' => 'note_eliminatoire'];
        }

        return ['defaillant' => false, 'note_finale' => $finale(), 'motif' => 'ok'];
    }

    /**
     * Une case de la matière est-elle vide (examen, ou TD s'il est prévu) ?
     * Matière acquise (AV/AC, ex. en session normale) ou note reportée : non.
     * Sans ligne de note (option non choisie) : non — le moteur enregistre une
     * ligne pour chaque matière qu'il prend en compte.
     */
    public static function caseVide(?object $note, object $matiere): bool
    {
        if (! $note || in_array($note->observation ?? null, ['AV', 'AC'], true) || ! empty($note->reportee)) {
            return false;
        }

        return $note->note_examen === null || ((float) $matiere->note_max_cc > 0 && $note->note_cc === null);
    }

    /**
     * Observation d'une UE au PV : « Déf. » si une case est vide dans l'UE
     * (voir caseVide()), « Elim » si l'UE est défaillante pour une note ou une
     * moyenne éliminatoire, sinon NV / AC / AV.
     */
    public static function observationUe(?object $ru, bool $caseVide, float $seuil): string
    {
        return match (true) {
            ! $ru => '—',
            (bool) $ru->defaillant => $caseVide ? 'Déf.' : 'Elim',
            ! $ru->valide => 'NV',
            (float) $ru->moyenne_20 < $seuil => 'AC',
            default => 'AV',
        };
    }

    /** Ancien recalculerResultats() : calcule et enregistre les résultats d'un étudiant pour un PV. */
    public function recalculer(int $pvId, int $etudiantId): void
    {
        try {
            DB::transaction(fn () => $this->calculer($pvId, $etudiantId));
        } catch (Throwable $e) {
            Log::error('recalculerResultats échoué', ['pv_id' => $pvId, 'etudiant_id' => $etudiantId, 'erreur' => $e->getMessage()]);
            throw $e;
        }
    }

    private function calculer(int $pvId, int $etudiantId): void
    {
        $this->initialiserParametres();

        $anneePv = (int) DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->where('ps.id', $pvId)->value('p.annee_id') ?: null;
        $seuil = (float) $this->regle('seuil_validation', $anneePv, '10');
        $elimMatiere = (float) $this->regle('note_eliminatoire', $anneePv, '5');
        $elimUe = (float) $this->regle('note_eliminatoire_ue', $anneePv, '5');
        $compUe = $this->regle('compensation_ue', $anneePv, 'oui') === 'oui';
        $compSemestre = $this->regle('compensation_semestre', $anneePv, 'oui') === 'oui';

        // 1. UE et matières du PV
        $ues = [];
        $lignes = DB::select("
            SELECT ue.id AS ue_id, ue.credits_total, ue.code AS ue_code, ue.nb_options_a_choisir,
                   m.id AS matiere_id, m.note_max_totale, m.note_max_cc, m.credits AS m_credits,
                   m.code AS m_code, m.coefficient AS m_coef, COALESCE(m.type_matiere,'obligatoire') AS type_matiere
            FROM pv_sessions pv
            JOIN unites_enseignement ue ON ue.promotion_id = pv.promotion_id AND ue.semestre_id = pv.semestre_id
            JOIN matieres m ON m.ue_id = ue.id AND m.actif = 1
            WHERE pv.id = ?
            ORDER BY ue.ordre, m.ordre", [$pvId]);
        foreach ($lignes as $r) {
            $ues[$r->ue_id] ??= ['ue_id' => $r->ue_id, 'credits_total' => $r->credits_total, 'nb_options_a_choisir' => (int) ($r->nb_options_a_choisir ?? 0), 'matieres' => []];
            $ues[$r->ue_id]['matieres'][] = [
                'matiere_id' => $r->matiere_id, 'note_max_totale' => (float) $r->note_max_totale,
                'note_max_cc' => (float) ($r->note_max_cc ?? 0), 'm_credits' => (int) $r->m_credits,
                'type_matiere' => $r->type_matiere ?? 'obligatoire',
            ];
        }

        // 2. Notes de l'étudiant
        $notes = [];
        foreach (DB::select('SELECT matiere_id, note_finale, note_examen, note_cc, defaillant, observation, reportee FROM notes WHERE pv_id = ? AND etudiant_id = ?', [$pvId, $etudiantId]) as $n) {
            $notes[$n->matiere_id] = (array) $n;
        }

        // 2b. Rattrapage : les matières AV/AC de la session normale sont conservées
        $validesNormale = [];
        $pv = DB::table('pv_sessions')->where('id', $pvId)->first(['type_session', 'pv_normale_id', 'promotion_id', 'semestre_id']);
        if ($pv && $pv->type_session === 'rattrapage' && $pv->pv_normale_id) {
            foreach (DB::select("SELECT matiere_id, observation, note_finale, note_examen, note_cc FROM notes WHERE pv_id = ? AND etudiant_id = ? AND observation IN ('AV','AC')", [$pv->pv_normale_id, $etudiantId]) as $n) {
                $validesNormale[$n->matiere_id] = $n->observation;
                if (! isset($notes[$n->matiere_id]) || $notes[$n->matiere_id]['note_finale'] === null) {
                    $notes[$n->matiere_id] = (array) $n;
                }
            }
        }

        // 3. Calcul par UE
        $choix = [];
        if ($pv) {
            foreach (DB::table('choix_options_etudiants')->where(['etudiant_id' => $etudiantId, 'promotion_id' => $pv->promotion_id, 'semestre_id' => $pv->semestre_id])->get(['ue_id', 'matiere_id']) as $c) {
                $choix[$c->ue_id][] = $c->matiere_id;
            }
        }
        $totalPts = 0.0;
        $nbUe = 0;
        $totalCredits = 0;
        $defaillantGlobal = false;
        $caseVide = false; // une note manque : « Défaillant » plutôt que « Elim »
        $resUe = [];

        foreach ($ues as $ue) {
            $pts = 0.0;
            $max = 0.0;
            $ueDef = false;
            $mats = [];
            $aOptions = $ue['nb_options_a_choisir'] > 0;

            foreach ($ue['matieres'] as $mat) {
                // Matière optionnelle non choisie : ignorée
                if ($aOptions && $mat['type_matiere'] === 'optionnelle' && ! in_array($mat['matiere_id'], $choix[$ue['ue_id']] ?? [])) {
                    continue;
                }
                $note = $notes[$mat['matiere_id']] ?? null;
                $val = null;
                $avecTd = $mat['note_max_cc'] > 0;
                if ($note) {
                    if ($note['note_finale'] !== null) {
                        $val = (float) $note['note_finale'];
                    } elseif ($note['note_examen'] !== null && (! $avecTd || $note['note_cc'] !== null)) {
                        // note_finale calculée seulement si examen ET TD (s'il est prévu) sont saisis
                        $val = (float) ($note['note_examen'] ?? 0) + (float) ($note['note_cc'] ?? 0);
                        DB::update('UPDATE notes SET note_finale = ? WHERE pv_id = ? AND etudiant_id = ? AND matiere_id = ?', [$val, $pvId, $etudiantId, $mat['matiere_id']]);
                    }
                }
                $dejaValidee = isset($validesNormale[$mat['matiere_id']]);
                $etat = self::matiereDefaillante($note, $mat, $elimMatiere, $dejaValidee);
                $def = $etat['defaillant'];
                $moy20 = ($val !== null && $mat['note_max_totale'] > 0) ? round(($val / $mat['note_max_totale']) * 20, 4) : null;
                $mats[$mat['matiere_id']] = [
                    'note_max' => $mat['note_max_totale'], 'm_credits' => $mat['m_credits'],
                    'obs_origine' => $dejaValidee ? $validesNormale[$mat['matiere_id']] : null,
                    'defaillant' => $def, 'moy20' => $moy20,
                    'valide' => $dejaValidee || ($moy20 !== null && $moy20 >= $seuil && ! $def),
                    'compense' => false,
                ];
                if ($val !== null) {
                    $pts += $val;
                }
                $max += $mat['note_max_totale'];
                if ($def) {
                    $ueDef = true;
                    $caseVide = $caseVide || in_array($etat['motif'], ['examen_manquant', 'td_manquant'], true);
                }
            }
            if ($ueDef) {
                $defaillantGlobal = true;
            }

            $moyUe = $max > 0 ? round(($pts / $max) * 20, 2) : 0.0;
            if ($elimUe > 0 && $moyUe < $elimUe) {
                $ueDef = true;
                $defaillantGlobal = true;
            }

            $compIntra = false;
            if ($compUe && ! $ueDef && $moyUe >= $seuil) {
                foreach ($mats as &$m) {
                    if (! $m['defaillant'] && ! $m['valide'] && $m['moy20'] !== null) {
                        $m['compense'] = true;
                    }
                }
                unset($m);
                $compIntra = true;
            }

            $credits = 0;
            if ($ueDef) {
                // Les matières acquises restent capitalisées, sans compensation
                foreach ($mats as $m) {
                    $credits += $m['valide'] ? $m['m_credits'] : 0;
                }
                $ueValide = false;
            } elseif ($compIntra) {
                $credits = $ue['credits_total'];
                $ueValide = true;
            } else {
                foreach ($mats as $m) {
                    $credits += ($m['valide'] || $m['compense']) ? $m['m_credits'] : 0;
                }
                $ueValide = $credits >= $ue['credits_total'];
            }

            $totalPts += $moyUe;
            $nbUe++;
            $totalCredits += $ue['credits_total'];
            $resUe[$ue['ue_id']] = ['ue_id' => $ue['ue_id'], 'credits_total' => $ue['credits_total'], 'pts' => $pts, 'max' => $max,
                'moy' => $moyUe, 'credits' => $credits, 'valide' => $ueValide, 'defaillant' => $ueDef, 'mats' => $mats];
        }

        // 4. Moyenne générale
        $moyGen = $nbUe > 0 ? round($totalPts / $nbUe, 2) : 0.0;

        // 5. Compensation inter-UE
        if ($compSemestre && ! $defaillantGlobal && $moyGen >= $seuil) {
            $nonValidees = array_filter($resUe, fn ($r) => ! $r['valide']);
            if (count($nonValidees) === 1) {
                $cle = array_key_first($nonValidees);
                $resUe[$cle]['valide'] = true;
                $resUe[$cle]['credits'] = $resUe[$cle]['credits_total'];
                foreach ($resUe[$cle]['mats'] as &$m) {
                    if (! $m['valide'] && $m['moy20'] !== null) {
                        $m['compense'] = true;
                    }
                }
                unset($m);
            }
        }

        // 6. Décision
        $creditsObtenus = array_sum(array_column($resUe, 'credits'));
        $valide = ! $defaillantGlobal && $creditsObtenus >= $totalCredits;
        $nonValide = $defaillantGlobal ? ($caseVide ? 'Défaillant' : 'Elim') : 'Ajourné';
        $mention = $valide ? $this->mention($moyGen) : $nonValide;
        $decision = $valide ? 'Admis' : $nonValide;

        // 7. Observations par matière (insertion si absente : matière exemptée en rattrapage)
        foreach ($resUe as $r) {
            foreach ($r['mats'] as $matId => $m) {
                $obs = match (true) {
                    ! empty($m['obs_origine']) => $m['obs_origine'],
                    $m['moy20'] === null => 'Déf.',
                    $m['compense'] => 'AC',
                    $m['valide'] => 'AV',
                    default => 'NV',
                };
                $n = $notes[$matId] ?? null;
                DB::statement('INSERT INTO notes (pv_id, etudiant_id, matiere_id, note_examen, note_cc, note_finale, observation)
                    VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE observation = VALUES(observation)',
                    [$pvId, $etudiantId, $matId, $n['note_examen'] ?? null, $n['note_cc'] ?? null, $n['note_finale'] ?? null, $obs]);
            }
        }

        // 8. Résultats d'UE et de semestre
        foreach ($resUe as $r) {
            DB::statement('INSERT INTO resultats_ue (pv_id, etudiant_id, ue_id, total_points, note_max, moyenne_20, credits_obtenus, valide, defaillant)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE total_points=VALUES(total_points), note_max=VALUES(note_max), moyenne_20=VALUES(moyenne_20),
                    credits_obtenus=VALUES(credits_obtenus), valide=VALUES(valide), defaillant=VALUES(defaillant)',
                [$pvId, $etudiantId, $r['ue_id'], $r['pts'], $r['max'], $r['moy'], $r['credits'], $r['valide'] ? 1 : 0, $r['defaillant'] ? 1 : 0]);
        }
        DB::statement('INSERT INTO resultats_semestre (pv_id, etudiant_id, moyenne_generale, credits_obtenus, credits_total, valide, defaillant, mention, decision)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE moyenne_generale=VALUES(moyenne_generale), credits_obtenus=VALUES(credits_obtenus),
                credits_total=VALUES(credits_total), valide=VALUES(valide), defaillant=VALUES(defaillant), mention=VALUES(mention), decision=VALUES(decision)',
            [$pvId, $etudiantId, $moyGen, $creditsObtenus, $totalCredits, $valide ? 1 : 0, $defaillantGlobal ? 1 : 0, $mention, $decision]);

        $this->recalculerRangs($pvId);
    }

    /** Ancien recalculerRangs() : rang des admis non défaillants, par moyenne décroissante. */
    public function recalculerRangs(int $pvId): void
    {
        $rang = 1;
        foreach (DB::select('SELECT id FROM resultats_semestre WHERE pv_id = ? AND valide = 1 AND defaillant = 0 ORDER BY moyenne_generale DESC', [$pvId]) as $r) {
            DB::update('UPDATE resultats_semestre SET rang = ? WHERE id = ?', [$rang++, $r->id]);
        }
    }

    /**
     * Ancien reporterNotes() : reporte vers un nouveau PV les notes validées
     * d'années précédentes (même filière et niveau), retrouvées par CODE ou
     * libellé de matière. Ne remplace jamais une note saisie à la main.
     */
    public function reporterNotes(int $etudiantId, int $nouveauPv): int
    {
        $pv = DB::selectOne('SELECT ps.semestre_id, ps.promotion_id, p.annee_id, p.filiere_id, p.niveau_id
            FROM pv_sessions ps JOIN promotions p ON p.id = ps.promotion_id WHERE ps.id = ?', [$nouveauPv]);
        if (! $pv) {
            return 0;
        }
        $matieres = DB::select('SELECT m.id AS matiere_id, m.code AS mat_code, m.libelle AS mat_lib, m.note_max_totale, ue.id AS ue_id, ue.code AS ue_code
            FROM unites_enseignement ue JOIN matieres m ON m.ue_id = ue.id AND m.actif = 1
            WHERE ue.promotion_id = ? AND ue.semestre_id = ?', [$pv->promotion_id, $pv->semestre_id]);

        $nb = 0;
        foreach ($matieres as $mat) {
            if (DB::table('notes')->where(['pv_id' => $nouveauPv, 'etudiant_id' => $etudiantId, 'matiere_id' => $mat->matiere_id, 'reportee' => 0])->exists()) {
                continue;
            }
            $ancienne = DB::selectOne("
                SELECT n.note_examen, n.note_cc, n.note_finale, n.reportee AS src_reportee, n.observation AS src_observation,
                       ps2.id AS pv_id, p2.annee_id, COALESCE(rs_ue.valide, 0) AS ue_valide, m2.note_max_cc,
                       (n.note_finale / NULLIF(m2.note_max_totale, 0) * 20) AS moy20
                FROM notes n
                JOIN pv_sessions ps2 ON ps2.id = n.pv_id AND ps2.type_session IN ('normale','rattrapage')
                JOIN promotions p2 ON p2.id = ps2.promotion_id AND p2.filiere_id = ? AND p2.niveau_id = ? AND p2.annee_id != ?
                JOIN matieres m2 ON m2.id = n.matiere_id AND (m2.code = ? OR m2.libelle = ?)
                JOIN unites_enseignement ue2 ON ue2.id = m2.ue_id AND (ue2.code = ? OR ue2.semestre_id = ?)
                LEFT JOIN resultats_ue rs_ue ON rs_ue.pv_id = ps2.id AND rs_ue.etudiant_id = n.etudiant_id AND rs_ue.ue_id = ue2.id
                WHERE n.etudiant_id = ? AND n.note_finale IS NOT NULL AND n.reportee = 0
                ORDER BY rs_ue.valide DESC, n.note_finale DESC, p2.annee_id DESC
                LIMIT 1", [$pv->filiere_id, $pv->niveau_id, $pv->annee_id, $mat->mat_code, $mat->mat_lib, $mat->ue_code, $pv->semestre_id, $etudiantId]);
            if (! $ancienne) {
                continue;
            }
            // Une ancienne note incomplète (TD vide) n'est jamais reportée
            $source = ['note_examen' => $ancienne->note_examen, 'note_cc' => $ancienne->note_cc, 'note_finale' => $ancienne->note_finale, 'reportee' => $ancienne->src_reportee];
            if (self::matiereDefaillante($source, ['note_max_cc' => $ancienne->note_max_cc, 'note_max_totale' => $mat->note_max_totale], 0)['defaillant']) {
                continue;
            }
            if (! ($ancienne->ue_valide || $ancienne->moy20 >= 10)) {
                continue;
            }
            DB::statement('INSERT INTO notes (pv_id, etudiant_id, matiere_id, note_examen, note_cc, note_finale, observation, reportee, pv_origine_id, annee_origine_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                ON DUPLICATE KEY UPDATE note_finale=VALUES(note_finale), note_examen=VALUES(note_examen), note_cc=VALUES(note_cc),
                    observation=VALUES(observation), reportee=1, pv_origine_id=VALUES(pv_origine_id), annee_origine_id=VALUES(annee_origine_id)',
                [$nouveauPv, $etudiantId, $mat->matiere_id, $ancienne->note_examen, $ancienne->note_cc, $ancienne->note_finale,
                    in_array($ancienne->src_observation, ['AV', 'AC'], true) ? $ancienne->src_observation : 'AV', $ancienne->pv_id, $ancienne->annee_id]);
            $nb++;
        }

        return $nb;
    }

    /**
     * L'ancien recalcul créait dans `parametres` les règles absentes, avec
     * leur valeur par défaut (une fois par requête) : repris pour que l'écran
     * des paramètres continue de les afficher.
     */
    private function initialiserParametres(): void
    {
        if ($this->parametresInitialises) {
            return;
        }
        $existantes = DB::table('parametres')->whereIn('cle', array_keys(self::REGLES + self::MENTIONS))->pluck('cle')->all();
        $manquantes = array_diff_key(self::REGLES + self::MENTIONS, array_flip($existantes));
        foreach ($manquantes as $cle => $valeur) {
            DB::table('parametres')->insertOrIgnore(['cle' => $cle, 'valeur' => $valeur]);
        }
        $this->parametresInitialises = true;
    }
}
