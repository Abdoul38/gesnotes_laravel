<?php

namespace App\Services;

use App\Support\Classeur;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Export du PV « avec formules » : les cellules du PV deviennent des formules
 * Excel qui reproduisent les règles du moteur (App\Services\Resultats), et se
 * recalculent si l'on modifie une note dans le fichier.
 *
 *  - feuille PV : note de matière (Exam. + TD), total et moyenne des UE,
 *    moyenne générale, mention et rang ; crédits et observation de chaque
 *    matière, crédits, validation et observation de chaque UE, crédits et
 *    décision du semestre ;
 *  - feuille « Calculs » (mêmes lignes) : étapes intermédiaires des règles —
 *    défaillance (examen ou TD absent, note sous la note éliminatoire, en
 *    stricte infériorité), validation (moyenne ≥ seuil), compensation dans
 *    l'UE puis entre UE, crédits.
 *
 * Paramètres de l'année du PV (seuil, note éliminatoire, compensations)
 * écrits dans les formules et rappelés en tête de la feuille « Calculs ».
 *
 * Garde-fou : chaque formule n'est posée que si elle redonne le résultat
 * enregistré ; pour les règles (validations, crédits, décision), la ligne
 * d'un étudiant n'en reçoit que si TOUTE la ligne, calculée en PHP comme le
 * feraient les formules, redonne le PV enregistré. Sinon les valeurs restent
 * (ex. rattrapage avec matières acquises en session normale, note reportée
 * d'une autre année, note fixée lors d'une réclamation).
 */
class PvFormulesExcel
{
    private array $pos = [];

    private array $calc = [];

    private array $p = [];

    private array $exclus = [];

    private Collection $admis;

    private string $plageDec = '';

    private string $plageMoy = '';

    private array $lignesCalculs = [];

    private int $creditsTotal = 0;

    public function __construct(private readonly Resultats $resultats) {}

    public function preparer(object $pv, array $cols, Collection $ues, Collection $etudiants, Collection $resSem): void
    {
        foreach ($cols as $i => $col) {
            $this->pos[self::cle($col)] = self::lettre($i + 1);
        }
        $derniere = 4 + max(1, $etudiants->count());
        $plage = fn (string $cle) => '$'.$this->pos[$cle].'$5:$'.$this->pos[$cle].'$'.$derniere;
        $this->plageDec = $plage('sem_dec');
        $this->plageMoy = $plage('sem_moy');
        $this->admis = $etudiants->map(fn ($et) => $resSem[$et->id] ?? null)->filter(fn ($rs) => $rs && $rs->decision === 'Admis')->pluck('moyenne_generale')->map(fn ($v) => (float) $v);
        $this->creditsTotal = (int) $ues->sum('credits_total');

        $annee = (int) $pv->annee_id;
        $this->p = [
            'seuil' => (float) $this->resultats->regle('seuil_validation', $annee, '10'),
            'elim' => (float) $this->resultats->regle('note_eliminatoire', $annee, '5'),
            'elimUe' => (float) $this->resultats->regle('note_eliminatoire_ue', $annee, '5'),
            'compUe' => $this->resultats->regle('compensation_ue', $annee, 'oui') === 'oui',
            'compSem' => $this->resultats->regle('compensation_semestre', $annee, 'oui') === 'oui',
            'mentions' => array_map(fn ($cle) => (float) $this->resultats->regle($cle, 0, ['mention_tres_bien' => '16', 'mention_bien' => '14', 'mention_assez_bien' => '12'][$cle]),
                ['tb' => 'mention_tres_bien', 'b' => 'mention_bien', 'ab' => 'mention_assez_bien']),
        ];

        // Étudiants que les formules ne peuvent pas suivre : notes reportées, matières acquises en session normale (rattrapage)
        $this->exclus = DB::table('notes')->where('pv_id', $pv->id)->where('reportee', 1)->pluck('etudiant_id')->flip()->all();
        if ($pv->type_session === 'rattrapage' && $pv->pv_normale_id) {
            $this->exclus += DB::table('notes')->where('pv_id', $pv->pv_normale_id)->whereIn('observation', ['AV', 'AC'])->pluck('etudiant_id')->flip()->all();
        }

        // Colonnes de la feuille « Calculs »
        $entetes = [['A', 'N°', '', ''], ['B', 'N° Étudiant', '', ''], ['C', 'Nom & Prénoms', '', '']];
        $col = 4;
        foreach ($ues as $ue) {
            foreach ($ue->matieres as $m) {
                foreach (['def' => 'Défaillante', 'val' => 'Validée', 'comp' => 'Compensée'] as $t => $lib) {
                    $this->calc["$t-{$ue->id}-{$m->id}"] = self::lettre($col++);
                    $entetes[] = [null, $ue->code ?? $ue->libelle, $m->libelle, $lib];
                }
            }
            foreach (['uedef' => 'UE défaillante', 'cintra' => 'Compensation dans l\'UE', 'craw' => 'Crédits (avant comp. semestre)',
                'vraw' => 'Validée (avant comp. semestre)', 'vfin' => 'Validée', 'cfin' => 'Crédits'] as $t => $lib) {
                $this->calc["$t-{$ue->id}"] = self::lettre($col++);
                $entetes[] = [null, $ue->code ?? $ue->libelle, 'UE', $lib];
            }
        }
        foreach (['defg' => 'Défaillant', 'nbnv' => 'UE non validées', 'inter' => 'Compensation entre UE', 'ctot' => 'Crédits du semestre'] as $t => $lib) {
            $this->calc[$t] = self::lettre($col++);
            $entetes[] = [null, 'SEMESTRE', '', $lib];
        }
        $nb = count($entetes);
        $p = $this->p;
        $titre = 'CALCULS INTERMÉDIAIRES (ne pas modifier) — seuil de validation '.$p['seuil'].'/20 ; note éliminatoire : strictement inférieure à '
            .$p['elim'].'/20 (rapportée au barème de chaque matière)'.($p['elimUe'] > 0 ? ' ; moyenne d\'UE éliminatoire : inférieure à '.$p['elimUe'].'/20' : '')
            .' ; compensation dans l\'UE : '.($p['compUe'] ? 'oui' : 'non').' ; entre UE : '.($p['compSem'] ? 'oui' : 'non');
        $this->lignesCalculs = [
            ['height' => 30, 'cells' => [['v' => $titre, 'style' => 1], ...array_fill(0, $nb - 1, ['v' => '', 'style' => 1])]],
            ['height' => 30, 'cells' => array_map(fn ($e) => ['v' => $e[1], 'style' => 2], $entetes)],
            ['height' => 40, 'cells' => array_map(fn ($e) => ['v' => $e[2], 'style' => 3], $entetes)],
            ['height' => 30, 'cells' => array_map(fn ($e) => ['v' => $e[3], 'style' => 4], $entetes)],
        ];
    }

    /** Feuille « Calculs » (à ajouter après la feuille PV) : [lignes, largeurs]. */
    public function feuilleCalculs(): array
    {
        $nb = count($this->lignesCalculs[1]['cells']);

        return [$this->lignesCalculs, [6, 12, 30, ...array_fill(0, $nb - 3, 9)]];
    }

    /**
     * Remplace les cellules calculables d'une ligne du PV par leur formule.
     *
     * @param  Collection  $notes  notes de l'étudiant par matière
     * @param  Collection  $resUe  résultats d'UE de l'étudiant par UE
     */
    public function ligne(array $cellules, array $cols, Collection $ues, object $et, int $k, Collection $notes, Collection $resUe, ?object $rs): array
    {
        $ligne = 5 + $k;
        $ref = fn (string $cle) => $this->pos[$cle].$ligne;
        $egal = fn ($a, $b) => $a !== '' && $b !== '' && $a !== null && $b !== null && abs((float) $a - (float) $b) < 0.005;
        $formule = fn (int $i, string $f) => ['f' => $f, 'v' => $cellules[$i]['v'], 'style' => $cellules[$i]['style']];
        $valeurs = $ignorees = [];
        foreach ($cols as $i => $col) {
            $valeurs[self::cle($col)] = $cellules[$i]['v'];
            if ($col[0] === 'exam' && $cellules[$i]['style'] === 16) {
                $ignorees[$col[2]->id] = true; // matière optionnelle non choisie
            }
        }

        // 1. Calculs simples : note, total et moyenne d'UE, moyenne générale, mention, rang
        $moyennesUe = [];
        foreach ($cols as $i => $col) {
            [$t, $ue, $m] = $col + [null, null, null];
            $v = $cellules[$i]['v'];
            if ($t === 'note' && $cellules[$i]['style'] !== 16) {
                $note = $notes[$m->id] ?? null;
                $somme = ($note && $note->note_examen !== null && $note->note_cc !== null) ? (float) $note->note_examen + (float) $note->note_cc : '';
                if ($v === '' ? $somme === '' : $egal($somme, $v)) {
                    $cellules[$i] = $formule($i, 'IF(AND(ISNUMBER('.$ref("exam-{$ue->id}-{$m->id}").'),ISNUMBER('.$ref("td-{$ue->id}-{$m->id}").')),'
                        .$ref("exam-{$ue->id}-{$m->id}").'+'.$ref("td-{$ue->id}-{$m->id}").',"")');
                }
            } elseif ($t === 'ue_tot' && ($r = $resUe[$ue->id] ?? null)) {
                $refs = $somme = [];
                foreach ($ue->matieres as $mat) {
                    $cle = self::cleNote($ue, $mat);
                    $refs[] = $ref($cle);
                    $somme[] = (float) ($valeurs[$cle] ?? 0);
                }
                if ($egal(array_sum($somme), $r->total_points)) {
                    $cellules[$i] = $formule($i, 'SUM('.implode(',', $refs).')');
                }
            } elseif ($t === 'ue_moy' && ($r = $resUe[$ue->id] ?? null) && (float) $r->note_max > 0) {
                $moyennesUe[] = $ref("ue_moy-{$ue->id}");
                if ($egal(round((float) $r->total_points / (float) $r->note_max * 20, 2), $r->moyenne_20)) {
                    $cellules[$i] = $formule($i, 'ROUND('.$ref("ue_tot-{$ue->id}").'/'.self::nombre($r->note_max).'*20,2)');
                }
            } elseif ($t === 'sem_moy' && $rs && $resUe->count() === count($moyennesUe)) {
                if ($egal(round($resUe->avg(fn ($r) => (float) $r->moyenne_20), 2), $rs->moyenne_generale)) {
                    $cellules[$i] = $formule($i, 'ROUND(AVERAGE('.implode(',', $moyennesUe).'),2)');
                }
            } elseif ($t === 'sem_men' && $rs) {
                $attendu = $rs->decision === 'Admis' ? $this->resultats->mention((float) $rs->moyenne_generale) : '';
                if ($attendu === (string) $v && ($rs->decision === 'Admis') === (bool) $rs->valide) {
                    [$moy, $s] = [$ref('sem_moy'), $this->p['mentions']];
                    $cellules[$i] = $formule($i, 'IF('.$ref('sem_dec').'="Admis",IF('.$moy.'>='.self::nombre($s['tb']).',"Très bien",IF('.$moy.'>='.self::nombre($s['b'])
                        .',"Bien",IF('.$moy.'>='.self::nombre($s['ab']).',"Assez bien","Passable"))),"")');
                }
            } elseif ($t === 'sem_rang' && $rs) {
                $calcule = $rs->decision === 'Admis' ? $this->admis->filter(fn ($m) => $m > (float) $rs->moyenne_generale + 0.0001)->count() + 1 : null;
                if ($rs->decision === 'Admis' ? (int) $rs->rang === $calcule && (bool) $rs->valide : ! $rs->valide) {
                    $cellules[$i] = ['f' => 'IF('.$ref('sem_dec').'="Admis",COUNTIFS('.$this->plageDec.',"Admis",'.$this->plageMoy.',">"&'.$ref('sem_moy').')+1,"")',
                        'v' => $calcule ?? '', 'style' => $cellules[$i]['style']];
                }
            }
        }

        // 2. Règles du moteur : seulement si toute la ligne, recalculée comme le feraient les formules, redonne le PV
        $calculs = array_fill(0, count($this->lignesCalculs[1]['cells']), ['v' => '', 'style' => 0]);
        $calculs[0] = ['v' => $k + 1, 'style' => 13];
        $calculs[1] = ['v' => (string) $et->numero, 'style' => 0];
        $calculs[2] = ['v' => $et->nom.' '.$et->prenoms, 'style' => 12];
        $simulation = isset($this->exclus[$et->id]) || ! $rs ? null : $this->simuler($ues, $valeurs, $ignorees, $resUe, $rs);
        if ($simulation && $this->concorde($simulation, $valeurs)) {
            [$cellules, $calculs] = $this->formulesRegles($cellules, $calculs, $cols, $ues, $ligne, $simulation);
        }
        $this->lignesCalculs[] = ['height' => 18, 'cells' => $calculs];

        return $cellules;
    }

    /** Ce que calculeraient les formules (mêmes règles, à partir des valeurs affichées). */
    private function simuler(Collection $ues, array $valeurs, array $ignorees, Collection $resUe, object $rs): ?array
    {
        $p = $this->p;
        $num = fn ($v) => ($v === '' || $v === null) ? null : (float) $v;
        $s = ['mat' => [], 'ue' => []];
        $defg = false;
        $nbnv = 0;
        foreach ($ues as $ue) {
            $r = $resUe[$ue->id] ?? null;
            if (! $r) {
                return null;
            }
            $moy = (float) $r->moyenne_20;
            $uedef = false;
            $sommeVal = 0;
            foreach ($ue->matieres as $m) {
                if (isset($ignorees[$m->id])) {
                    continue; // option non choisie
                }
                $max = (float) $m->note_max_totale;
                if ($max <= 0) {
                    return null;
                }
                $n = $num($valeurs[self::cleNote($ue, $m)] ?? null);
                $def = $num($valeurs["exam-{$ue->id}-{$m->id}"]) === null
                    || ((float) $m->note_max_cc > 0 && $num($valeurs["td-{$ue->id}-{$m->id}"] ?? null) === null)
                    || ($p['elim'] > 0 && $n !== null && $n < $max * $p['elim'] / 20);
                $val = $n !== null && round($n / $max * 20, 4) >= $p['seuil'] && ! $def;
                $s['mat'][$m->id] = ['def' => $def, 'val' => $val, 'n' => $n, 'ue' => $ue->id, 'credits' => (int) $m->credits];
                $uedef = $uedef || $def;
                $sommeVal += $val ? (int) $m->credits : 0;
            }
            $uedef = $uedef || ($p['elimUe'] > 0 && $moy < $p['elimUe']);
            $cintra = $p['compUe'] && ! $uedef && $moy >= $p['seuil'];
            $craw = $cintra ? (int) $ue->credits_total : $sommeVal;
            $vraw = ! $uedef && ($cintra || $craw >= (int) $ue->credits_total);
            $s['ue'][$ue->id] = ['uedef' => $uedef, 'cintra' => $cintra, 'craw' => $craw, 'vraw' => $vraw, 'moy' => $moy, 'total' => (int) $ue->credits_total];
            $defg = $defg || $uedef;
            $nbnv += $vraw ? 0 : 1;
        }
        $inter = $p['compSem'] && ! $defg && (float) $rs->moyenne_generale >= $p['seuil'] && $nbnv === 1;
        $ctot = 0;
        foreach ($s['ue'] as $id => &$u) {
            $u['vfin'] = $u['vraw'] || $inter;
            $u['cfin'] = ($inter && ! $u['vraw']) ? $u['total'] : $u['craw'];
            $ctot += $u['cfin'];
        }
        unset($u);
        foreach ($s['mat'] as &$m) {
            $u = $s['ue'][$m['ue']];
            $m['comp'] = ! $m['val'] && ! $m['def'] && $m['n'] !== null && ($u['cintra'] || ($inter && ! $u['vraw']));
            $m['obs'] = $m['n'] === null ? 'Déf.' : ($m['comp'] ? 'AC' : ($m['val'] ? 'AV' : 'NV'));
        }
        unset($m);

        return $s + ['defg' => $defg, 'nbnv' => $nbnv, 'inter' => $inter, 'ctot' => $ctot,
            'dec' => $defg ? 'Défaillant' : ($ctot >= $this->creditsTotal ? 'Admis' : 'Ajourné')];
    }

    /** La simulation redonne-t-elle chaque valeur affichée du PV (observations, crédits, validations, décision) ? */
    private function concorde(array $s, array $valeurs): bool
    {
        foreach ($s['mat'] as $id => $m) {
            if (($valeurs["obs_mat-{$m['ue']}-$id"] ?? null) !== $m['obs']
                || (float) ($valeurs["cr_mat-{$m['ue']}-$id"] ?? -1) !== (float) (in_array($m['obs'], ['AV', 'AC'], true) ? $m['credits'] : 0)) {
                return false;
            }
        }
        foreach ($s['ue'] as $id => $u) {
            $obs = $u['uedef'] ? 'Déf.' : (! $u['vfin'] ? 'NV' : ($u['moy'] < $this->p['seuil'] ? 'AC' : 'AV'));
            if (($valeurs["ue_cr-$id"] ?? null) !== $u['cfin'].'/'.$u['total'] || ($valeurs["ue_val-$id"] ?? null) !== ($u['vfin'] ? 'OUI' : 'NON')
                || ($valeurs["ue_obs-$id"] ?? null) !== $obs) {
                return false;
            }
        }

        return ($valeurs['sem_cr'] ?? null) === $s['ctot'].'/'.$this->creditsTotal && ($valeurs['sem_dec'] ?? null) === $s['dec'];
    }

    /** Formules des règles (feuille PV et feuille « Calculs »), valeurs déjà calculées comprises. */
    private function formulesRegles(array $cellules, array $calculs, array $cols, Collection $ues, int $ligne, array $s): array
    {
        $p = $this->p;
        $pv = fn (string $cle) => 'PV!'.$this->pos[$cle].$ligne;
        $ca = fn (string $cle) => 'Calculs!'.$this->calc[$cle].$ligne;
        $poser = function (string $cle, string $f, $v) use (&$calculs) {
            $calculs[self::index($this->calc[$cle])] = ['f' => $f, 'v' => $v, 'style' => 0];
        };
        $etape = fn (string $cle) => $this->calc[$cle].$ligne;
        $seuil = self::nombre($p['seuil']);

        foreach ($ues as $ue) {
            $defs = [];
            $vals = [];
            foreach ($ue->matieres as $m) {
                if (! isset($s['mat'][$m->id])) {
                    continue;
                }
                $x = $s['mat'][$m->id];
                $n = $pv(self::cleNote($ue, $m));
                $conditions = ['NOT(ISNUMBER('.$pv("exam-{$ue->id}-{$m->id}").'))'];
                if ((float) $m->note_max_cc > 0) {
                    $conditions[] = 'NOT(ISNUMBER('.$pv("td-{$ue->id}-{$m->id}").'))';
                }
                if ($p['elim'] > 0) {
                    // IF plutôt que AND : Excel évalue tous les arguments d'un AND (erreur si la note est vide)
                    $conditions[] = 'IF(ISNUMBER('.$n.'),'.$n.'<'.self::nombre((float) $m->note_max_totale * $p['elim'] / 20).',FALSE)';
                }
                $poser("def-{$ue->id}-{$m->id}", 'OR('.implode(',', $conditions).')', $x['def']);
                $poser("val-{$ue->id}-{$m->id}", 'IF(ISNUMBER('.$n.'),AND(ROUND('.$n.'/'.self::nombre($m->note_max_totale).'*20,4)>='.$seuil.',NOT('.$etape("def-{$ue->id}-{$m->id}").')),FALSE)', $x['val']);
                $poser("comp-{$ue->id}-{$m->id}", 'IF(ISNUMBER('.$n.'),AND(NOT('.$etape("val-{$ue->id}-{$m->id}").'),NOT('.$etape("def-{$ue->id}-{$m->id}").'),OR('
                    .$etape("cintra-{$ue->id}").',AND('.$etape('inter').',NOT('.$etape("vraw-{$ue->id}").')))),FALSE)', $x['comp']);
                $defs[] = $etape("def-{$ue->id}-{$m->id}");
                $vals[] = $etape("val-{$ue->id}-{$m->id}").'*'.(int) $m->credits;

                // Feuille PV : observation et crédits de la matière
                foreach ($cols as $i => $col) {
                    if (self::cle($col) === "obs_mat-{$ue->id}-{$m->id}") {
                        $cellules[$i] = ['f' => 'IF(NOT(ISNUMBER('.$this->pos[self::cleNote($ue, $m)].$ligne.')),"Déf.",IF('.$ca("comp-{$ue->id}-{$m->id}").',"AC",IF('.$ca("val-{$ue->id}-{$m->id}").',"AV","NV")))',
                            'v' => $cellules[$i]['v'], 'style' => $cellules[$i]['style']];
                    } elseif (self::cle($col) === "cr_mat-{$ue->id}-{$m->id}") {
                        $obs = $this->pos["obs_mat-{$ue->id}-{$m->id}"].$ligne;
                        $cellules[$i] = ['f' => 'IF(OR('.$obs.'="AV",'.$obs.'="AC"),'.(int) $m->credits.',0)', 'v' => $cellules[$i]['v'], 'style' => $cellules[$i]['style']];
                    }
                }
            }
            $u = $s['ue'][$ue->id];
            $moy = $pv("ue_moy-{$ue->id}");
            $total = (int) $ue->credits_total;
            $poser("uedef-{$ue->id}", 'OR('.implode(',', $defs ?: ['FALSE']).($p['elimUe'] > 0 ? ','.$moy.'<'.self::nombre($p['elimUe']) : '').')', $u['uedef']);
            $poser("cintra-{$ue->id}", $p['compUe'] ? 'AND(NOT('.$etape("uedef-{$ue->id}").'),'.$moy.'>='.$seuil.')' : 'FALSE', $u['cintra']);
            $poser("craw-{$ue->id}", 'IF('.$etape("cintra-{$ue->id}").','.$total.','.($vals ? implode('+', $vals) : '0').')', $u['craw']);
            $poser("vraw-{$ue->id}", 'AND(NOT('.$etape("uedef-{$ue->id}").'),OR('.$etape("cintra-{$ue->id}").','.$etape("craw-{$ue->id}").'>='.$total.'))', $u['vraw']);
            $poser("vfin-{$ue->id}", 'OR('.$etape("vraw-{$ue->id}").','.$etape('inter').')', $u['vfin']);
            $poser("cfin-{$ue->id}", 'IF(AND('.$etape('inter').',NOT('.$etape("vraw-{$ue->id}").')),'.$total.','.$etape("craw-{$ue->id}").')', $u['cfin']);

            foreach ($cols as $i => $col) {
                $f = match (self::cle($col)) {
                    "ue_cr-{$ue->id}" => $ca("cfin-{$ue->id}").'&"/'.$total.'"',
                    "ue_val-{$ue->id}" => 'IF('.$ca("vfin-{$ue->id}").',"OUI","NON")',
                    "ue_obs-{$ue->id}" => 'IF('.$ca("uedef-{$ue->id}").',"Déf.",IF(NOT('.$ca("vfin-{$ue->id}").'),"NV",IF('.$this->pos["ue_moy-{$ue->id}"].$ligne.'<'.$seuil.',"AC","AV")))',
                    default => null,
                };
                if ($f) {
                    $cellules[$i] = ['f' => $f, 'v' => $cellules[$i]['v'], 'style' => $cellules[$i]['style']];
                }
            }
        }
        $uedefs = array_map(fn ($ue) => $etape("uedef-{$ue->id}"), $ues->all());
        $poser('defg', 'OR('.implode(',', $uedefs).')', $s['defg']);
        $poser('nbnv', implode('+', array_map(fn ($ue) => 'NOT('.$etape("vraw-{$ue->id}").')', $ues->all())), $s['nbnv']);
        $poser('inter', $p['compSem'] ? 'AND(NOT('.$etape('defg').'),'.$pv('sem_moy').'>='.$seuil.','.$etape('nbnv').'=1)' : 'FALSE', $s['inter']);
        $poser('ctot', implode('+', array_map(fn ($ue) => $etape("cfin-{$ue->id}"), $ues->all())), $s['ctot']);

        foreach ($cols as $i => $col) {
            $f = match (self::cle($col)) {
                'sem_cr' => $ca('ctot').'&"/'.$this->creditsTotal.'"',
                'sem_dec' => 'IF('.$ca('defg').',"Défaillant",IF('.$ca('ctot').'>='.$this->creditsTotal.',"Admis","Ajourné"))',
                default => null,
            };
            if ($f) {
                $cellules[$i] = ['f' => $f, 'v' => $cellules[$i]['v'], 'style' => $cellules[$i]['style']];
            }
        }

        return [$cellules, $calculs];
    }

    private static function cle(array $col): string
    {
        [$t, $ue, $m] = $col + [null, null, null];

        return $t.($ue ? '-'.$ue->id : '').($m ? '-'.$m->id : '');
    }

    /** Cellule qui porte la note de la matière : colonne Note s'il y a un TD, sinon colonne Exam. */
    private static function cleNote(object $ue, object $m): string
    {
        return ((float) $m->note_max_cc > 0 ? 'note' : 'exam')."-{$ue->id}-{$m->id}";
    }

    private static function lettre(int $colonne): string
    {
        return preg_replace('/\d+$/', '', Classeur::ref($colonne, 1));
    }

    private static function index(string $lettres): int
    {
        $n = 0;
        foreach (str_split($lettres) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }

        return $n - 1;
    }

    /** Nombre écrit dans une formule (point décimal, sans zéros inutiles). */
    private static function nombre($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
    }
}
