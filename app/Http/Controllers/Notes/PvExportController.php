<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Pv;
use App\Services\PvFormulesExcel;
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
 * Filtres d'Excel sur chaque colonne (ligne 4), dans les deux variantes : les
 * en-têtes de la ligne 4 ne sont plus fusionnés avec la ligne 3.
 *
 * Feuille protégée : seules les cases Examen et TD (fond jaune) sont
 * modifiables, sauf celles d'une matière acquise (AV/AC, ignorées par
 * l'import) ; une note hors de 0 au maximum y est refusée. Feuille « Calculs »
 * très masquée ; la feuille PV peut être copiée (impression), et s'imprime en
 * A4 paysage, sur la largeur d'une page, en-têtes répétés. Mot de passe :
 * PV_EXCEL_MOT_DE_PASSE, sinon aléatoire (jamais communiqué).
 *
 * Variante « avec formules » (?formules=1, en plus de l'export en valeurs) :
 * les résultats deviennent des formules Excel qui reproduisent les règles du
 * moteur et se recalculent si l'on modifie une note dans le fichier, avec une
 * feuille « Calculs » pour les étapes intermédiaires — voir PvFormulesExcel.
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
        // En-tête par UE : ligne 2 = nom de l'UE au-dessus de toutes ses colonnes (matières et résultats),
        // ligne 3 = matières puis « Résultats de l'UE », ligne 4 = en-têtes de colonne (filtres)
        $l2 = $l3 = $l4 = [];
        $ueVue = [];
        foreach ($cols as $i => $col) {
            [$t, $ue, $m] = $col + [null, null, null];
            $premiereColonneUe = $ue && ! isset($ueVue[$ue->id]);
            if ($ue) {
                $ueVue[$ue->id] = true;
            }
            $l2[] = $c(match (true) {
                $i === 0 => 'N°', $i === 1 => 'N° Étudiant', $i === 2 => 'Nom & Prénoms',
                $premiereColonneUe => trim(($ue->code ? $ue->code.' — ' : '').$ue->libelle).' ('.$ue->credits_total.' crédits)',
                $t === 'sem_cr' => 'RÉSULTAT SEMESTRE',
                default => '',
            }, 2);
            $l3[] = $c($i <= 2 ? '' : match ($t) {
                'exam' => $m->libelle, 'ue_tot' => "Résultats de l'UE", 'sem_cr' => 'Crédits ('.$creditsTotal.')', 'sem_moy' => 'Moy. Gén.',
                'sem_dec' => 'Décision', 'sem_men' => 'Mention', 'sem_rang' => 'Rang', default => '',
            }, $i <= 2 ? 4 : 3);
            // Ligne 4 = ligne des filtres : un libellé par colonne. Aucun résumé ne commence par « Exam. », « TD/ »
            // ou « Obs. », que l'import du PV complet lit pour repérer les matières.
            $l4[] = $c(match ($t) {
                'num' => 'N°', 'numero' => 'Matricule', 'nom' => 'Nom & Prénoms',
                'exam' => 'Exam./'.(float) $m->note_max_examen, 'td' => 'TD/'.(float) $m->note_max_cc,
                'note' => 'Note/'.(float) $m->note_max_totale, 'cr_mat' => 'Crédits('.$m->credits.')', 'obs_mat' => 'Obs.',
                'ue_tot' => 'Total/'.(int) $this->maxUe($ue), 'ue_moy' => 'Moy./20', 'ue_cr' => 'Crédits ('.$ue->credits_total.')',
                'ue_val' => 'Validée', 'ue_obs' => 'Résultat UE',
                'sem_cr' => 'Crédits', 'sem_moy' => 'Moyenne', 'sem_dec' => 'Décision', 'sem_men' => 'Mention', 'sem_rang' => 'Rang',
                default => '',
            }, 4);
        }
        $lignes[] = ['height' => 40, 'cells' => $l2];
        $lignes[] = ['height' => 40, 'cells' => $l3];
        $lignes[] = ['height' => 30, 'cells' => $l4];

        $styleObs = fn (?string $o) => ['AV' => 5, 'AC' => 6, 'NV' => 7, 'Déf.' => 8, 'Elim' => 8][$o] ?? 0;
        $formules = $request->boolean('formules');
        $excel = $formules ? app(PvFormulesExcel::class) : null;
        $excel?->preparer($pv, $cols, $ues, $etudiants, $resSem);
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
                    // Comme le moteur : sans note finale enregistrée, la note n'existe que si l'examen ET le TD (s'il est prévu) sont saisis
                    // (avant : l'examen seul s'affichait quand le TD manquait, alors que le moteur traitait la note comme absente)
                    $finale = $note?->note_finale ?? (($note && $note->note_examen !== null && ($note->note_cc !== null || (float) $m->note_max_cc <= 0))
                        ? (float) $note->note_examen + (float) $note->note_cc : null);
                    $saisie = in_array($obs, ['AV', 'AC'], true) ? 0 : 17;
                    $cellules[] = match ($t) {
                        'exam' => $n($note?->note_examen, $saisie), 'td' => $n($note?->note_cc, $saisie), 'note' => $n($finale),
                        'cr_mat' => $n(in_array($obs, ['AV', 'AC'], true) ? $m->credits : 0, 13),
                        'obs_mat' => $c($obs ?: '—', $styleObs($obs)),
                    };

                    continue;
                }
                if ($ue) {
                    $r = $resUe[$et->id][$ue->id] ?? null;
                    $obsUe = Resultats::observationUe($r, $ue->matieres->contains(fn ($m) => Resultats::caseVide($notes[$et->id][$m->id] ?? null, $m)), $seuil);
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
                    'sem_dec' => $c($rs->decision ?? '—', ['Admis' => 14, 'Défaillant' => 8, 'Elim' => 8][$rs->decision ?? ''] ?? 15),
                    'sem_men' => $c($rs?->valide ? $rs->mention : '', 11),
                    'sem_rang' => $c($rs?->valide ? (string) $rs->rang : '', 13),
                };
            }
            if ($excel) {
                $cellules = $excel->ligne($cellules, $cols, $ues, $et, $k, $notes[$et->id] ?? collect(), $resUe[$et->id] ?? collect(), $rs);
            }
            $lignes[] = ['height' => 18, 'cells' => $cellules];
        }

        // Fusions : titre, colonnes fixes, bloc de chaque UE (ligne 2), matières et « Résultats de l'UE » (ligne 3) ;
        // groupes : les colonnes des matières de chaque UE se replient (bouton −/+ d'Excel), ses résultats restent visibles
        $fusions = ['A1:'.Classeur::ref($nb, 1), 'A2:A3', 'B2:B3', 'C2:C3'];
        $groupes = $blocs = $matieres = [];
        $debut = null;
        foreach ($cols as $i => $col) {
            [$t, $ue, $m] = $col + [null, null, null];
            if ($ue) {
                $blocs[$ue->id] ??= ['debut' => $i, 'finMatieres' => $i];
                $blocs[$ue->id]['fin'] = $i;
                if ($m) {
                    $blocs[$ue->id]['finMatieres'] = $i;
                    $matieres[$m->id] ??= ['debut' => $i];
                    $matieres[$m->id]['fin'] = $i;
                }
            }
            $debut ??= $t === 'sem_cr' ? $i : null;
        }
        foreach ($matieres as $mat) {
            $fusions[] = Classeur::ref($mat['debut'] + 1, 3).':'.Classeur::ref($mat['fin'] + 1, 3);
        }
        foreach ($blocs as $b) {
            $fusions[] = Classeur::ref($b['debut'] + 1, 2).':'.Classeur::ref($b['fin'] + 1, 2);
            $fusions[] = Classeur::ref($b['finMatieres'] + 2, 3).':'.Classeur::ref($b['fin'] + 1, 3);
            $groupes[] = [$b['debut'] + 1, $b['finMatieres'] + 1];
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
        if (filled(config('gesnotes.pv_excel_mot_de_passe'))) {
            $classeur->motDePasse((string) config('gesnotes.pv_excel_mot_de_passe'));
        }
        // Notes refusées hors de 0 au maximum de la matière (colonnes Examen et TD)
        $derniere = 4 + max(1, $etudiants->count());
        $validations = [];
        foreach ($cols as $i => $col) {
            [$t, $ue, $m] = $col + [null, null, null];
            if (in_array($t, ['exam', 'td'], true)) {
                $lettre = preg_replace('/\d+$/', '', Classeur::ref($i + 1, 1));
                $validations[] = ['plage' => $lettre.'5:'.$lettre.$derniere, 'min' => 0, 'max' => (float) ($t === 'exam' ? $m->note_max_examen : $m->note_max_cc),
                    'titre' => ($t === 'exam' ? 'Examen' : 'TD').' — '.$m->libelle];
            }
        }
        // Filtres d'Excel sur la ligne 4 (une flèche par colonne), étudiants en dessous
        $classeur->feuille('PV', $lignes, $largeurs, 4, $fusions, 'A4:'.Classeur::ref($nb, $derniere), $groupes,
            ['protegee' => true, 'validations' => $validations, 'impression' => 4]);
        if ($excel) {
            [$lignesCalculs, $largeursCalculs] = $excel->feuilleCalculs();
            $classeur->feuille('Calculs', $lignesCalculs, $largeursCalculs, 4, options: ['masquee' => true]);
        }
        $nom = 'PV_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $pv->semestre.'_'.mb_substr($pv->filiere, 0, 15).'_'.$pv->annee).($formules ? '_formules' : '').'.xlsx';

        return response()->download($classeur->enregistrer(), $nom, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-cache'])
            ->deleteFileAfterSend();
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
        .'<fills count="13"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF1A3A5C"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2C5F9E"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF1A5C3A"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCE9F7"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F5E9"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE3F2FD"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF8E1"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFEBEE"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F5E9"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE0E0E0"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFFDE7"/></patternFill></fill></fills>'
        .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFCCCCCC"/></left><right style="thin"><color rgb="FFCCCCCC"/></right><top style="thin"><color rgb="FFCCCCCC"/></top><bottom style="thin"><color rgb="FFCCCCCC"/></bottom></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="18">'
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
        // 17 : case de saisie d'une note (seule modifiable quand la feuille est protégée)
        .'<xf numFmtId="0" fontId="0" fillId="12" borderId="1" xfId="0" applyProtection="1"><alignment horizontal="center" vertical="center"/><protection locked="0"/></xf>'
        .'</cellXfs></styleSheet>';
}
