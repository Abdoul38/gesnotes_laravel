<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Pv;
use App\Services\Resultats;
use App\Support\Classeur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export du PV en Excel — portage de pages/notes_pv/pv_exporter.php. Même
 * disposition (4 lignes d'en-tête, colonnes Exam./TD/Note/Crédits/Obs. par
 * matière, puis Total/Moy./Crédits/Valid./Obs. par UE, puis le semestre) :
 * le fichier se réimporte par « Import PV complet ».
 *
 * Accès (repris) : admin, secrétaire, sc_centrale ; sc_entite et doyen de la
 * faculté du PV ; chef de département de son département.
 *
 * Différence voulue : le fichier reprend les résultats ENREGISTRÉS par le
 * moteur. L'ancien export redéterminait les défaillances avec une note
 * éliminatoire de 5/20 écrite en dur (sans tenir compte du paramètre de
 * l'université ni de son blocage par année) : l'Excel pouvait contredire le PV.
 * Les matricules restent du texte (zéros conservés).
 *
 * Variante « avec formules » (?formules=1, en plus de l'export en valeurs) :
 * note de matière (Exam. + TD), total et moyenne des UE, moyenne générale,
 * mention et rang deviennent des formules Excel, qui se recalculent si l'on
 * modifie une note dans le fichier. Une cellule ne reçoit sa formule que si
 * celle-ci redonne exactement le résultat enregistré (sinon, par exemple
 * une note fixée lors d'une réclamation, la valeur reste). Validations,
 * observations, crédits et décisions restent des valeurs : ils dépendent
 * des règles du moteur (note éliminatoire, compensation, options…).
 */
class PvExportController extends Controller
{
    public function __invoke(Request $request, Pv $pvs, Resultats $resultats): BinaryFileResponse|RedirectResponse
    {
        $u = $request->user();
        $pv = $pvs->trouver((int) $request->query('id'));
        $ok = $pv && match ($u->role) {
            'admin', 'secretaire', 'sc_centrale' => true,
            'sc_entite', 'doyen' => $u->faculteId() > 0 && (int) $pv->faculte_id === $u->faculteId(),
            'chef_departement' => $u->departementId() > 0 && (int) $pv->departement_id === $u->departementId(),
            default => false,
        };
        if (! $ok) {
            return redirect()->route('pv')->with('erreur', 'Accès refusé — ce PV ne relève pas de votre périmètre.');
        }

        $seuil = (float) $resultats->regle('seuil_validation', (int) $pv->annee_id, '10');
        $ues = $pvs->ues($pv);
        $etudiants = $pvs->etudiants($pv)->get();
        $notes = DB::table('notes')->where('pv_id', $pv->id)->get()->groupBy('etudiant_id')->map->keyBy('matiere_id');
        $resUe = DB::table('resultats_ue')->where('pv_id', $pv->id)->get()->groupBy('etudiant_id')->map->keyBy('ue_id');
        $resSem = DB::table('resultats_semestre')->where('pv_id', $pv->id)->get()->keyBy('etudiant_id');
        $choix = $pvs->choixOptions($pv);
        $creditsTotal = $ues->sum('credits_total');

        // Colonnes
        $cols = [['num'], ['numero'], ['nom']];
        foreach ($ues as $ue) {
            foreach ($ue->matieres as $m) {
                $cols[] = ['exam', $ue, $m];
                if ((float) $m->note_max_cc > 0) {
                    $cols[] = ['td', $ue, $m];
                    $cols[] = ['note', $ue, $m];
                }
                $cols[] = ['cr_mat', $ue, $m];
                $cols[] = ['obs_mat', $ue, $m];
            }
            foreach (['ue_tot', 'ue_moy', 'ue_cr', 'ue_val', 'ue_obs'] as $t) {
                $cols[] = [$t, $ue];
            }
        }
        foreach (['sem_cr', 'sem_moy', 'sem_dec', 'sem_men', 'sem_rang'] as $t) {
            $cols[] = [$t];
        }
        $nb = count($cols);
        $c = fn ($v, int $s = 0) => ['v' => $v === null ? '' : $v, 'style' => $s];
        $n = fn ($v, int $s = 0) => ['v' => ($v === null || $v === '') ? '' : (float) $v, 'style' => $s];

        $titre = 'RÉSULTATS DES EXAMENS — '.$pv->sem_libelle.' — '.$pv->filiere.' '.$pv->niveau.' — Année '.$pv->annee.($request->boolean('formules') ? ' (avec formules)' : '');
        $lignes = [['height' => 30, 'cells' => [$c($titre, 1), ...array_fill(0, $nb - 1, $c('', 1))]]];
        $l2 = $l3 = $l4 = [];
        foreach ($cols as $i => $col) {
            [$t, $ue, $m] = $col + [null, null, null];
            $l2[] = $c(match (true) {
                $i === 0 => 'N°', $i === 1 => 'N° Étudiant', $i === 2 => 'Nom & Prénoms',
                $t === 'exam' => $ue->libelle,
                $t === 'ue_tot' => 'Total/'.(int) $this->maxUe($ue),
                $t === 'ue_moy' => 'Moy./20', $t === 'ue_cr' => 'Crédits ('.$ue->credits_total.')',
                $t === 'ue_val' => 'Valid. UE', $t === 'ue_obs' => 'Obs. UE', $t === 'sem_cr' => 'RÉSULTAT SEMESTRE',
                default => '',
            }, 2);
            $l3[] = $c($i <= 2 ? '' : match ($t) {
                'exam' => $m->libelle, 'sem_cr' => 'Crédits ('.$creditsTotal.')', 'sem_moy' => 'Moy. Gén.',
                'sem_dec' => 'Décision', 'sem_men' => 'Mention', 'sem_rang' => 'Rang', default => '',
            }, $i <= 2 ? 4 : 3);
            $l4[] = $c($i <= 2 ? '' : match ($t) {
                'exam' => 'Exam./'.(float) $m->note_max_examen, 'td' => 'TD/'.(float) $m->note_max_cc,
                'note' => 'Note/'.(float) $m->note_max_totale, 'cr_mat' => 'Crédits('.$m->credits.')', 'obs_mat' => 'Obs.', default => '',
            }, 4);
        }
        $lignes[] = ['height' => 40, 'cells' => $l2];
        $lignes[] = ['height' => 40, 'cells' => $l3];
        $lignes[] = ['height' => 30, 'cells' => $l4];

        $styleObs = fn (?string $o) => ['AV' => 5, 'AC' => 6, 'NV' => 7, 'Déf.' => 8][$o] ?? 0;
        $formules = $request->boolean('formules');
        $outilsFormules = $formules ? $this->outilsFormules($cols, $etudiants, $resSem, $resultats) : null;
        foreach ($etudiants as $k => $et) {
            $rs = $resSem[$et->id] ?? null;
            $cellules = [];
            foreach ($cols as $col) {
                [$t, $ue, $m] = $col + [null, null, null];
                if ($m) {
                    if ($ue->nb_options_a_choisir > 0 && $m->type_matiere === 'optionnelle' && ! in_array((int) $m->id, $choix[$et->id][$ue->id] ?? [], true)) {
                        $cellules[] = $c('', 16);

                        continue;
                    }
                    $note = $notes[$et->id][$m->id] ?? null;
                    $obs = $note?->observation;
                    $finale = $note?->note_finale ?? (($note && $note->note_examen !== null) ? (float) $note->note_examen + (float) $note->note_cc : null);
                    $cellules[] = match ($t) {
                        'exam' => $n($note?->note_examen), 'td' => $n($note?->note_cc), 'note' => $n($finale),
                        'cr_mat' => $n(in_array($obs, ['AV', 'AC'], true) ? $m->credits : 0, 13),
                        'obs_mat' => $c($obs ?: '—', $styleObs($obs)),
                    };

                    continue;
                }
                if ($ue) {
                    $r = $resUe[$et->id][$ue->id] ?? null;
                    $obsUe = ! $r ? '—' : ($r->defaillant ? 'Déf.' : (! $r->valide ? 'NV' : ((float) $r->moyenne_20 < $seuil ? 'AC' : 'AV')));
                    $cellules[] = match ($t) {
                        'ue_tot' => $n($r?->total_points),
                        'ue_moy' => $n($r?->moyenne_20, $r && (float) $r->moyenne_20 >= $seuil ? 9 : 10),
                        'ue_cr' => $c(($r->credits_obtenus ?? 0).'/'.$ue->credits_total, 11),
                        'ue_val' => $c(! $r ? '—' : ($r->valide ? 'OUI' : 'NON'), ! $r ? 0 : ($r->defaillant ? 8 : ($r->valide ? 5 : 7))),
                        'ue_obs' => $c($obsUe, $styleObs($obsUe)),
                    };

                    continue;
                }
                $cellules[] = match ($t) {
                    'num' => $n($k + 1, 13),
                    'numero' => $c((string) $et->numero),
                    'nom' => $c($et->nom.' '.$et->prenoms, 12),
                    'sem_cr' => $c(($rs->credits_obtenus ?? 0).'/'.$creditsTotal, 11),
                    'sem_moy' => $n($rs?->moyenne_generale, $rs?->valide ? 9 : 10),
                    'sem_dec' => $c($rs->decision ?? '—', ['Admis' => 14, 'Défaillant' => 8][$rs->decision ?? ''] ?? 15),
                    'sem_men' => $c($rs?->valide ? $rs->mention : '', 11),
                    'sem_rang' => $c($rs?->valide ? (string) $rs->rang : '', 13),
                };
            }
            if ($formules) {
                $cellules = $this->ajouterFormules($cellules, $cols, 5 + $k, $notes[$et->id] ?? collect(), $resUe[$et->id] ?? collect(), $resSem[$et->id] ?? null, $outilsFormules, $resultats);
            }
            $lignes[] = ['height' => 18, 'cells' => $cellules];
        }

        // Fusions : titre, colonnes fixes, UE (ligne 2), matières (ligne 3), résumés (lignes 3-4)
        $fusions = ['A1:'.Classeur::ref($nb, 1), 'A2:A4', 'B2:B4', 'C2:C4'];
        $debut = null;
        foreach ($cols as $i => $col) {
            [$t, $ue, $m] = $col + [null, null, null];
            if ($t === 'exam') {
                $finUe = $finMat = $i;
                for ($j = $i + 1; $j < $nb && in_array($cols[$j][0], ['td', 'note', 'cr_mat', 'obs_mat'], true) && $cols[$j][1]->id === $ue->id; $j++) {
                    $finUe = $j;
                    $finMat = ($cols[$j][2]->id === $m->id) ? $j : $finMat;
                }
                $fusions[] = Classeur::ref($i + 1, 2).':'.Classeur::ref($finUe + 1, 2);
                $fusions[] = Classeur::ref($i + 1, 3).':'.Classeur::ref($finMat + 1, 3);
            }
            if (str_starts_with($t, 'ue_') || str_starts_with($t, 'sem_')) {
                $fusions[] = Classeur::ref($i + 1, 3).':'.Classeur::ref($i + 1, 4);
            }
            $debut ??= $t === 'sem_cr' ? $i : null;
        }
        $fusions[] = Classeur::ref($debut + 1, 2).':'.Classeur::ref($nb, 2);
        // Une « fusion » d'une seule cellule (A3:A3) rendrait le fichier invalide
        $fusions = array_values(array_unique(array_filter($fusions, function ($zone) {
            [$debut, $fin] = explode(':', $zone);

            return $debut !== $fin;
        })));

        $largeurs = [6, 12, 30];
        foreach (array_slice($cols, 3) as [$t]) {
            $largeurs[] = match (true) {
                in_array($t, ['obs_mat', 'ue_val', 'ue_obs'], true) => 6,
                in_array($t, ['ue_moy', 'sem_moy', 'sem_dec'], true) => 9,
                in_array($t, ['exam', 'td', 'note'], true) => 8,
                default => 7,
            };
        }
        $classeur = new Classeur(self::STYLES);
        $classeur->feuille('PV', $lignes, $largeurs, 4, $fusions);
        $nom = 'PV_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $pv->semestre.'_'.mb_substr($pv->filiere, 0, 15).'_'.$pv->annee).($formules ? '_formules' : '').'.xlsx';

        return response()->download($classeur->enregistrer(), $nom, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-cache'])
            ->deleteFileAfterSend();
    }

    /** Lettres des colonnes, plages du semestre et seuils de mention, communs à toutes les lignes. */
    private function outilsFormules(array $cols, $etudiants, $resSem, Resultats $resultats): array
    {
        $pos = [];
        foreach ($cols as $i => $col) {
            [$t, $ue, $m] = $col + [null, null, null];
            $pos[$t.($ue ? '-'.$ue->id : '').($m ? '-'.$m->id : '')] = preg_replace('/\d+$/', '', Classeur::ref($i + 1, 1));
        }
        $derniere = 4 + max(1, $etudiants->count());
        $plage = fn (string $cle) => '$'.$pos[$cle].'$5:$'.$pos[$cle].'$'.$derniere;
        // Moyennes des admis (rang : nombre d'admis ayant une meilleure moyenne + 1)
        $admis = $etudiants->map(fn ($et) => $resSem[$et->id] ?? null)->filter(fn ($rs) => $rs && $rs->decision === 'Admis')->pluck('moyenne_generale')->map(fn ($v) => (float) $v);

        return ['pos' => $pos, 'dec' => $plage('sem_dec'), 'moy' => $plage('sem_moy'), 'admis' => $admis,
            'seuils' => array_map(fn ($cle) => (float) $resultats->regle($cle, 0, ['mention_tres_bien' => '16', 'mention_bien' => '14', 'mention_assez_bien' => '12'][$cle]),
                ['tb' => 'mention_tres_bien', 'b' => 'mention_bien', 'ab' => 'mention_assez_bien'])];
    }

    /** Remplace les cellules calculables par leur formule, si elle redonne la valeur enregistrée. */
    private function ajouterFormules(array $cellules, array $cols, int $ligne, $notes, $resUe, ?object $rs, array $o, Resultats $resultats): array
    {
        $ref = fn (string $cle) => $o['pos'][$cle].$ligne;
        $egal = fn ($a, $b) => $a !== '' && $b !== '' && $a !== null && $b !== null && abs((float) $a - (float) $b) < 0.005;
        $formule = fn (int $i, string $f) => ['f' => $f, 'v' => $cellules[$i]['v'], 'style' => $cellules[$i]['style']];
        $valeurs = [];
        foreach ($cols as $i => $col) {
            $valeurs[implode('-', array_filter([$col[0], isset($col[1]) ? $col[1]->id : null, isset($col[2]) ? $col[2]->id : null]))] = $cellules[$i]['v'];
        }
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
                    $cle = ((float) $mat->note_max_cc > 0 ? 'note' : 'exam')."-{$ue->id}-{$mat->id}";
                    $refs[] = $ref($cle);
                    $somme[] = (float) ($valeurs[$cle] ?? 0);
                }
                if ($egal(array_sum($somme), $r->total_points)) {
                    $cellules[$i] = $formule($i, 'SUM('.implode(',', $refs).')');
                }
            } elseif ($t === 'ue_moy' && ($r = $resUe[$ue->id] ?? null) && (float) $r->note_max > 0) {
                $moyennesUe[] = $ref("ue_moy-{$ue->id}");
                if ($egal(round((float) $r->total_points / (float) $r->note_max * 20, 2), $r->moyenne_20)) {
                    $cellules[$i] = $formule($i, 'ROUND('.$ref("ue_tot-{$ue->id}").'/'.(float) $r->note_max.'*20,2)');
                }
            } elseif ($t === 'sem_moy' && $rs && $resUe->count() === count($moyennesUe)) {
                $moy = round($resUe->avg(fn ($r) => (float) $r->moyenne_20), 2);
                if ($egal($moy, $rs->moyenne_generale)) {
                    $cellules[$i] = $formule($i, 'ROUND(AVERAGE('.implode(',', $moyennesUe).'),2)');
                }
            } elseif ($t === 'sem_men' && $rs) {
                $attendu = $rs->decision === 'Admis' ? $resultats->mention((float) $rs->moyenne_generale) : '';
                if ($attendu === (string) $v && ($rs->decision === 'Admis') === (bool) $rs->valide) {
                    $moy = $ref('sem_moy');
                    $s = $o['seuils'];
                    $cellules[$i] = $formule($i, 'IF('.$ref('sem_dec').'="Admis",IF('.$moy.'>='.$s['tb'].',"Très bien",IF('.$moy.'>='.$s['b'].',"Bien",IF('
                        .$moy.'>='.$s['ab'].',"Assez bien","Passable"))),"")');
                }
            } elseif ($t === 'sem_rang' && $rs) {
                $calcule = $rs->decision === 'Admis' ? $o['admis']->filter(fn ($m) => $m > (float) $rs->moyenne_generale + 0.0001)->count() + 1 : null;
                if ($rs->decision === 'Admis' ? (int) $rs->rang === $calcule && (bool) $rs->valide : ! $rs->valide) {
                    $cellules[$i] = ['f' => 'IF('.$ref('sem_dec').'="Admis",COUNTIFS('.$o['dec'].',"Admis",'.$o['moy'].',">"&'.$ref('sem_moy').')+1,"")',
                        'v' => $calcule ?? '', 'style' => $cellules[$i]['style']];
                }
            }
        }

        return $cellules;
    }

    /** Total maximum d'une UE (les options ne comptent que pour le nombre à choisir). */
    private function maxUe(object $ue): float
    {
        $max = 0.0;
        $refOption = 0.0;
        foreach ($ue->matieres as $m) {
            if ($ue->nb_options_a_choisir > 0 && $m->type_matiere === 'optionnelle') {
                $refOption = $refOption ?: (float) $m->note_max_totale;

                continue;
            }
            $max += (float) $m->note_max_totale;
        }

        return $max + ($ue->nb_options_a_choisir > 0 ? $refOption * $ue->nb_options_a_choisir : 0);
    }

    /** Les 17 styles de l'ancien export (mêmes couleurs). */
    private const STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<fonts count="8"><font><sz val="10"/><name val="Arial"/></font><font><sz val="11"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><b/><name val="Arial"/></font><font><sz val="10"/><b/><color rgb="FF1B5E20"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><b/><color rgb="FF0D47A1"/><name val="Arial"/></font><font><sz val="10"/><b/><color rgb="FFE65100"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><b/><color rgb="FFB71C1C"/><name val="Arial"/></font><font><sz val="10"/><b/><color rgb="FF1B5E20"/><name val="Arial"/></font></fonts>'
        .'<fills count="12"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF1A3A5C"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2C5F9E"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF1A5C3A"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCE9F7"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F5E9"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE3F2FD"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF8E1"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFEBEE"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F5E9"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE0E0E0"/></patternFill></fill></fills>'
        .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFCCCCCC"/></left><right style="thin"><color rgb="FFCCCCCC"/></right><top style="thin"><color rgb="FFCCCCCC"/></top><bottom style="thin"><color rgb="FFCCCCCC"/></bottom></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="17">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="2" fillId="5" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="3" fillId="6" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="5" fillId="8" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="6" fillId="9" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="2" fontId="3" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="2" fontId="5" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0"><alignment horizontal="left" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="7" fillId="10" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="5" fillId="8" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="0" fillId="11" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
        .'</cellXfs></styleSheet>';
}
