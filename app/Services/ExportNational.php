<?php

namespace App\Services;

use App\Support\Classeur;

/**
 * Classeur Excel du tableau de bord national (espace ministère) :
 *  - Synthèse : une ligne par université, total national ;
 *  - Facultés : regroupées par université (bandeau), total de chaque université ;
 *  - Filières : par université puis par faculté, sous-total de chaque faculté,
 *    total de chaque université ;
 *  - Évolution : par université, années académiques successives.
 * Nombres avec séparateur de milliers, pourcentages au format « 45,3 % »,
 * en-têtes figés, impression A4 paysage.
 */
class ExportNational
{
    // Styles (index des cellXfs ci-dessous)
    private const TEXTE = 0;

    private const NOMBRE = 1;

    private const POURCENT = 2;

    private const TITRE = 6;

    private const SOUS_TITRE = 7;

    private const ENTETE = 8;

    private const BANDEAU_UNI = 9;

    private const BANDEAU_FAC = 10;

    private const ERREUR = 20;

    /** Tons de ligne : [texte, nombre, pourcentage]. */
    private const TONS = [
        'normal' => [0, 1, 2], 'alterne' => [3, 4, 5], 'retrait' => [21, 1, 2], 'retrait_alterne' => [22, 4, 5],
        'faculte' => [11, 12, 13], 'universite' => [14, 15, 16], 'national' => [17, 18, 19],
    ];

    private const STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0"/><numFmt numFmtId="165" formatCode="0.0&quot; %&quot;"/></numFmts>'
        .'<fonts count="7"><font><sz val="10"/><name val="Arial"/></font>'
        .'<font><sz val="14"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
        .'<font><sz val="9"/><i/><color rgb="FF555555"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><b/><color rgb="FF1A3A5C"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><b/><name val="Arial"/></font>'
        .'<font><sz val="10"/><i/><color rgb="FFB91C1C"/><name val="Arial"/></font></fonts>'
        .'<fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF1A3A5C"/></patternFill></fill>'   // 2 titre, total national
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF2C5F9E"/></patternFill></fill>'   // 3 en-tête de colonnes
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/></patternFill></fill>'   // 4 bandeau université
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFD6E4F0"/></patternFill></fill>'   // 5 bandeau faculté
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFEEF4FA"/></patternFill></fill>'   // 6 sous-total faculté
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFC9DCEF"/></patternFill></fill>'   // 7 total université
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFF7F9FC"/></patternFill></fill></fills>'   // 8 ligne alternée
        .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        .'<border><left style="thin"><color rgb="FFC8CED6"/></left><right style="thin"><color rgb="FFC8CED6"/></right><top style="thin"><color rgb="FFC8CED6"/></top><bottom style="thin"><color rgb="FFC8CED6"/></bottom><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="23">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center"/></xf>'                                          // 0 texte
        .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'            // 1 nombre
        .'<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'            // 2 %
        .'<xf numFmtId="0" fontId="0" fillId="8" borderId="1" xfId="0" applyFill="1"><alignment vertical="center"/></xf>'                                        // 3 texte alterné
        .'<xf numFmtId="164" fontId="0" fillId="8" borderId="1" xfId="0" applyFill="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>' // 4
        .'<xf numFmtId="165" fontId="0" fillId="8" borderId="1" xfId="0" applyFill="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>' // 5
        .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'           // 6 titre
        .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment horizontal="left" vertical="center"/></xf>'                                     // 7 sous-titre
        .'<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFill="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'       // 8 en-tête
        .'<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFill="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'           // 9 bandeau université
        .'<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFill="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'           // 10 bandeau faculté
        .'<xf numFmtId="0" fontId="5" fillId="6" borderId="1" xfId="0" applyFill="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'           // 11 sous-total faculté
        .'<xf numFmtId="164" fontId="5" fillId="6" borderId="1" xfId="0" applyFill="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
        .'<xf numFmtId="165" fontId="5" fillId="6" borderId="1" xfId="0" applyFill="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0" applyFill="1"><alignment horizontal="left" vertical="center"/></xf>'                      // 14 total université
        .'<xf numFmtId="164" fontId="4" fillId="7" borderId="1" xfId="0" applyFill="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
        .'<xf numFmtId="165" fontId="4" fillId="7" borderId="1" xfId="0" applyFill="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFill="1"><alignment horizontal="left" vertical="center"/></xf>'                      // 17 total national
        .'<xf numFmtId="164" fontId="3" fillId="2" borderId="1" xfId="0" applyFill="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
        .'<xf numFmtId="165" fontId="3" fillId="2" borderId="1" xfId="0" applyFill="1" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0"><alignment vertical="center"/></xf>'                                                       // 20 erreur
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="center" indent="2"/></xf>'                                           // 21 texte en retrait
        .'<xf numFmtId="0" fontId="0" fillId="8" borderId="1" xfId="0" applyFill="1"><alignment vertical="center" indent="2"/></xf>'                             // 22 retrait alterné
        .'</cellXfs></styleSheet>';

    public function classeur(array $stats, array $totaux, string $annee): Classeur
    {
        $classeur = new Classeur(self::STYLES);
        $ok = array_filter($stats, fn ($s) => ! $s['erreur']);
        $this->synthese($classeur, $stats, $totaux, $annee);
        $this->facultes($classeur, $stats, $totaux, $annee);
        $this->filieres($classeur, $stats, $totaux, $annee);
        $this->evolution($classeur, $ok, $annee);

        return $classeur;
    }

    // ── Feuilles ──────────────────────────────────────────────

    private function synthese(Classeur $c, array $stats, array $t, string $annee): void
    {
        $colonnes = ['Université', 'Facultés', 'Filières', 'Inscrits', 'Hommes', 'Femmes', '% Femmes', 'Passages', 'Redoublants', 'Taux de passage',
            'Heures prévues', 'Heures faites', 'Exécution du programme', 'PV publiés', 'PV en attente'];
        $lignes = $this->entete('Tableau de bord national — synthèse par université', $annee, $colonnes);
        $i = 0;
        foreach ($stats as $s) {
            if ($s['erreur']) {
                $lignes[] = $this->erreur($s['uni'], count($colonnes));

                continue;
            }
            $lignes[] = $this->ligne($i++ % 2 ? 'alterne' : 'normal', [$s['uni']->nom.' ('.$s['uni']->sigle.')'], [
                [$s['nb_facultes'], 'n'], [$s['nb_filieres'], 'n'], ...$this->indicateurs($s, 'taux_passage'), [$s['pv_attente'], 'n'],
            ]);
        }
        $lignes[] = $this->ligne('national', ['TOTAL NATIONAL ('.$t['universites'].' universités)'], [['', 'n'], ['', 'n'], ...$this->indicateurs($t, 'taux_passage'), ['', 'n']]);
        $c->feuille('Synthèse nationale', $lignes, [46, 10, 10, 11, 11, 11, 11, 11, 12, 13, 13, 13, 14, 11, 12], 4,
            ['A1:'.Classeur::ref(count($colonnes), 1), 'A2:'.Classeur::ref(count($colonnes), 2)], null, [], ['impression' => 4]);
    }

    private function facultes(Classeur $c, array $stats, array $t, string $annee): void
    {
        $colonnes = ['Faculté / École', 'Inscrits', 'Hommes', 'Femmes', '% Femmes', 'Passages', 'Redoublants', 'Taux de passage',
            'Heures prévues', 'Heures faites', 'Exécution du programme', 'PV publiés'];
        $nb = count($colonnes);
        $lignes = $this->entete('Facultés et écoles, par université', $annee, $colonnes);
        foreach ($stats as $s) {
            if ($s['erreur']) {
                $lignes[] = $this->erreur($s['uni'], $nb);

                continue;
            }
            $lignes[] = $this->bandeau($s['uni']->nom.' ('.$s['uni']->sigle.') — '.count($s['facultes']).' faculté(s) / école(s)', self::BANDEAU_UNI, $nb);
            foreach (array_values($s['facultes']) as $i => $f) {
                $lignes[] = $this->ligne($i % 2 ? 'retrait_alterne' : 'retrait', [$f['faculte']], $this->indicateurs($f, 'taux'));
            }
            $lignes[] = $this->ligne('universite', ['Total '.$s['uni']->sigle], $this->indicateurs($s, 'taux_passage'));
            $lignes[] = ['height' => 8, 'cells' => []];
        }
        $lignes[] = $this->ligne('national', ['TOTAL NATIONAL'], $this->indicateurs($t, 'taux_passage'));
        $c->feuille('Facultés', $lignes, [50, 11, 11, 11, 11, 11, 12, 13, 13, 13, 14, 11], 4,
            ['A1:'.Classeur::ref($nb, 1), 'A2:'.Classeur::ref($nb, 2)], null, [], ['impression' => 4]);
    }

    private function filieres(Classeur $c, array $stats, array $t, string $annee): void
    {
        $colonnes = ['Filière', 'Niveau', 'Inscrits', 'Hommes', 'Femmes', '% Femmes', 'Passages', 'Redoublants', 'Taux de passage',
            'Heures prévues', 'Heures faites', 'Exécution du programme'];
        $nb = count($colonnes);
        $lignes = $this->entete('Filières, par université et par faculté', $annee, $colonnes);
        foreach ($stats as $s) {
            if ($s['erreur']) {
                $lignes[] = $this->erreur($s['uni'], $nb);

                continue;
            }
            $lignes[] = $this->bandeau($s['uni']->nom.' ('.$s['uni']->sigle.')', self::BANDEAU_UNI, $nb);
            foreach ($s['facultes'] as $f) {
                $lignes[] = $this->bandeau($f['faculte'].' — '.count($f['filieres']).' filière(s) / niveau(x)', self::BANDEAU_FAC, $nb);
                foreach (array_values($f['filieres']) as $i => $l) {
                    $lignes[] = $this->ligne($i % 2 ? 'retrait_alterne' : 'retrait', [$l['filiere'], $l['niveau']], $this->indicateurs($l, 'taux', false));
                }
                $lignes[] = $this->ligne('faculte', ['Sous-total '.$f['faculte'], ''], $this->indicateurs($f, 'taux', false));
            }
            $lignes[] = $this->ligne('universite', ['Total '.$s['uni']->sigle, ''], $this->indicateurs($s, 'taux_passage', false));
            $lignes[] = ['height' => 8, 'cells' => []];
        }
        $lignes[] = $this->ligne('national', ['TOTAL NATIONAL', ''], $this->indicateurs($t, 'taux_passage', false));
        $c->feuille('Filières', $lignes, [46, 9, 11, 11, 11, 11, 11, 12, 13, 13, 13, 14], 4,
            ['A1:'.Classeur::ref($nb, 1), 'A2:'.Classeur::ref($nb, 2)], null, [], ['impression' => 4]);
    }

    private function evolution(Classeur $c, array $ok, string $annee): void
    {
        $colonnes = ['Année académique', 'Inscrits', 'Passages', 'Redoublants', 'Taux de passage'];
        $nb = count($colonnes);
        $lignes = $this->entete('Évolution par université', $annee, $colonnes);
        foreach ($ok as $s) {
            $lignes[] = $this->bandeau($s['uni']->nom.' ('.$s['uni']->sigle.')', self::BANDEAU_UNI, $nb);
            foreach (array_values($s['evolution']) as $i => $e) {
                $lignes[] = $this->ligne($e['annee'] === $annee ? 'faculte' : ($i % 2 ? 'retrait_alterne' : 'retrait'), [$e['annee']],
                    [[$e['inscrits'], 'n'], [$e['passages'], 'n'], [$e['redoublants'], 'n'], [$e['taux'], 'p']]);
            }
            $lignes[] = ['height' => 8, 'cells' => []];
        }
        $c->feuille('Évolution', $lignes, [30, 12, 12, 13, 15], 4, ['A1:'.Classeur::ref($nb, 1), 'A2:'.Classeur::ref($nb, 2)], null, [], ['impression' => 4]);
    }

    // ── Lignes ────────────────────────────────────────────────

    /** Titre, sous-titre, ligne vide, en-tête de colonnes (4 lignes, figées). */
    private function entete(string $titre, string $annee, array $colonnes): array
    {
        return [
            ['height' => 30, 'cells' => array_merge([['v' => $titre.' — '.$annee, 'style' => self::TITRE]], array_fill(0, count($colonnes) - 1, ['style' => self::TITRE]))],
            ['height' => 18, 'cells' => [['v' => 'Ministère de l\'Enseignement supérieur — généré le '.now()->format('d/m/Y à H:i'), 'style' => self::SOUS_TITRE]]],
            ['height' => 6, 'cells' => []],
            ['height' => 34, 'cells' => array_map(fn ($x) => ['v' => $x, 'style' => self::ENTETE], $colonnes)],
        ];
    }

    /** Bandeau (université, faculté) sur toute la largeur. */
    private function bandeau(string $texte, int $style, int $nb): array
    {
        return ['height' => 22, 'merges' => [[1, $nb]], 'cells' => array_merge([['v' => $texte, 'style' => $style]], array_fill(0, $nb - 1, ['style' => $style]))];
    }

    private function erreur(object $uni, int $nb): array
    {
        return ['height' => 20, 'merges' => [[1, $nb]], 'cells' => array_merge([['v' => $uni->nom.' ('.$uni->sigle.') — données indisponibles (connexion impossible)', 'style' => self::ERREUR]],
            array_fill(0, $nb - 1, ['style' => self::ERREUR]))];
    }

    /**
     * @param  list<string>  $textes
     * @param  list<array{0: mixed, 1: string}>  $valeurs  [valeur, 'n' nombre | 'p' pourcentage] ; null ou '' : case vide
     */
    private function ligne(string $ton, array $textes, array $valeurs): array
    {
        [$sTexte, $sNombre, $sPourcent] = self::TONS[$ton];
        $cellules = array_map(fn ($x) => ['v' => $x, 'style' => $sTexte], $textes);
        foreach ($valeurs as [$v, $type]) {
            $cellules[] = ['v' => $v === null || $v === '' ? '' : (is_numeric($v) ? $v + 0 : $v), 'style' => $type === 'p' ? $sPourcent : $sNombre];
        }

        return ['height' => in_array($ton, ['universite', 'national'], true) ? 22 : 18, 'cells' => $cellules];
    }

    /**
     * Inscrits, hommes, femmes, % femmes, passages, redoublants, taux de passage,
     * heures prévues et faites, exécution du programme (+ PV publiés).
     */
    private function indicateurs(array $d, string $cleTaux, bool $pv = true): array
    {
        $inscrits = (int) ($d['inscrits'] ?? 0);
        $femmes = (int) ($d['femmes'] ?? 0);
        $hommes = isset($d['hommes']) ? (int) $d['hommes'] : max(0, $inscrits - $femmes);
        $valeurs = [
            [$inscrits, 'n'], [$hommes, 'n'], [$femmes, 'n'], [$inscrits > 0 ? round($femmes / $inscrits * 100, 1) : null, 'p'],
            [$d['passages'] ?? null, 'n'], [$d['redoublants'] ?? null, 'n'], [$d[$cleTaux] ?? null, 'p'],
            [$d['heures_prevues'] ?? null, 'n'], [$d['heures_faites'] ?? null, 'n'], [$d['taux_programme'] ?? null, 'p'],
        ];

        return $pv ? [...$valeurs, [$d['pv_publies'] ?? null, 'n']] : $valeurs;
    }
}
