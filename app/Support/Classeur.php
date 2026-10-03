<?php

namespace App\Support;

use ZipArchive;

/**
 * Écriture d'un classeur XLSX sans bibliothèque — portage de la classe
 * SimpleXlsx de l'ancien liste_inscrits_excel.php (mêmes 12 styles).
 *
 * Styles : 0 normal, 1 titre, 2 groupe, 3 sous-titre, 4 total, 5 en-tête
 * de colonne, 6 sexe M, 7 sexe F, 8 niveau, 9 semestres, 10 ligne
 * alternée, 11 gras.
 *
 * Différences voulues : deux feuilles ne peuvent plus porter le même nom
 * (deux groupes au début identique, coupés à 31 caractères, donnaient un
 * fichier qu'Excel refusait d'ouvrir) ; un classeur vide reçoit une
 * feuille ; les caractères interdits en XML sont retirés.
 */
final class Classeur
{
    private array $feuilles = [];

    private array $chaines = [];

    /**
     * @param  list<array{height?: int, merges?: list<array{0:int,1:int}>, cells: list<array{v?: mixed, style?: int}>}>  $lignes
     * @param  list<int|float>  $largeurs
     */
    public function feuille(string $nom, array $lignes, array $largeurs = []): void
    {
        $nom = mb_substr(trim(preg_replace('/[\/\\\\?*\[\]:]/', '_', $nom)) ?: 'Feuille', 0, 31);
        $pris = array_map('mb_strtolower', array_column($this->feuilles, 'nom'));
        for ($n = 2, $base = $nom; in_array(mb_strtolower($nom), $pris, true); $n++) {
            $nom = mb_substr($base, 0, 31 - strlen(" ($n)"))." ($n)";
        }
        $this->feuilles[] = ['nom' => $nom, 'lignes' => $lignes, 'largeurs' => $largeurs];
    }

    /** Écrit le classeur dans un fichier temporaire et retourne son chemin. */
    public function enregistrer(): string
    {
        if (! $this->feuilles) {
            $this->feuille('Liste', [['cells' => [['v' => 'Aucun étudiant']]]]);
        }
        $xmlFeuilles = array_map(fn ($f) => $this->xmlFeuille($f), $this->feuilles);

        $rels = $elements = $types = '';
        foreach ($this->feuilles as $i => $f) {
            $id = $i + 1;
            $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
            $elements .= '<sheet name="'.self::xml($f['nom']).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $entete = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $sst = $entete.'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($this->chaines).'" uniqueCount="'.count($this->chaines).'">';
        foreach (array_keys($this->chaines) as $s) {
            $sst .= '<si><t xml:space="preserve">'.self::xml((string) $s).'</t></si>';
        }
        $sst .= '</sst>';

        $chemin = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive;
        $zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $entete.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$types.'</Types>');
        $zip->addFromString('_rels/.rels', $entete.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $entete.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$elements.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $entete.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId100" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'<Relationship Id="rId101" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'.$rels.'</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', $sst);
        $zip->addFromString('xl/styles.xml', self::styles());
        foreach ($xmlFeuilles as $i => $x) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $x);
        }
        $zip->close();

        return $chemin;
    }

    private function xmlFeuille(array $f): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($f['largeurs']) {
            $x .= '<cols>';
            foreach ($f['largeurs'] as $i => $w) {
                $x .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        $fusions = [];
        foreach ($f['lignes'] as $ri => $ligne) {
            $r = $ri + 1;
            $x .= '<row r="'.$r.'" ht="'.($ligne['height'] ?? 16).'" customHeight="1">';
            foreach ($ligne['cells'] as $ci => $cellule) {
                $ref = self::ref($ci + 1, $r);
                $style = (int) ($cellule['style'] ?? 0);
                $v = $cellule['v'] ?? '';
                if ($v === '' || $v === null) {
                    $x .= '<c r="'.$ref.'" s="'.$style.'"/>';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="'.$ref.'" s="'.$style.'"><v>'.$v.'</v></c>';
                } else {
                    // Texte toujours en chaîne partagée : un matricule « 00012 » garde ses zéros
                    $this->chaines[(string) $v] ??= count($this->chaines);
                    $x .= '<c r="'.$ref.'" s="'.$style.'" t="s"><v>'.$this->chaines[(string) $v].'</v></c>';
                }
            }
            $x .= '</row>';
            foreach ($ligne['merges'] ?? [] as [$de, $a]) {
                $fusions[] = self::ref($de, $r).':'.self::ref($a, $r);
            }
        }
        $x .= '</sheetData>';
        if ($fusions) {
            $x .= '<mergeCells count="'.count($fusions).'">'.implode('', array_map(fn ($m) => '<mergeCell ref="'.$m.'"/>', $fusions)).'</mergeCells>';
        }

        return $x.'</worksheet>';
    }

    private static function ref(int $col, int $ligne): string
    {
        $lettres = '';
        for ($c = $col; $c > 0; $c = intdiv($c - 1, 26)) {
            $lettres = chr(65 + ($c - 1) % 26).$lettres;
        }

        return $lettres.$ligne;
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function styles(): string
    {
        $remplissages = ['FF1A3A5C', 'FF2C5F9E', 'FFD6E4F0', 'FFEBF5FF', 'FFDBEAFE', 'FFFCE7F3', 'FFE0F2FE', 'FFE8F5E9', 'FFFFF8E1', 'FFFFFFFF'];
        $fills = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .implode('', array_map(fn ($c) => '<fill><patternFill patternType="solid"><fgColor rgb="'.$c.'"/></patternFill></fill>', $remplissages));
        // [police, remplissage, alignement]
        $xfs = [[0, 11, 'left'], [5, 2, 'center'], [2, 3, 'center'], [3, 4, 'center'], [1, 2, 'right'], [1, 2, 'center'],
            [3, 6, 'center'], [3, 7, 'center'], [3, 8, 'center'], [4, 9, 'left" wrapText="1'], [0, 5, 'left'], [3, 11, 'left']];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="6"><font><sz val="10"/><name val="Arial"/></font><font><sz val="10"/><b/><name val="Arial"/><color rgb="FFFFFFFF"/></font>'
            .'<font><sz val="11"/><b/><name val="Arial"/><color rgb="FFFFFFFF"/></font><font><sz val="10"/><b/><name val="Arial"/></font>'
            .'<font><sz val="9"/><name val="Arial"/></font><font><sz val="12"/><b/><name val="Arial"/><color rgb="FFFFFFFF"/></font></fonts>'
            .'<fills count="12">'.$fills.'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/></border><border><left style="thin"><color rgb="FFCCCCCC"/></left><right style="thin"><color rgb="FFCCCCCC"/></right><top style="thin"><color rgb="FFCCCCCC"/></top><bottom style="thin"><color rgb="FFCCCCCC"/></bottom></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="12">'.implode('', array_map(fn ($s) => '<xf numFmtId="0" fontId="'.$s[0].'" fillId="'.$s[1].'" borderId="1" xfId="0"><alignment horizontal="'.$s[2].'" vertical="center"/></xf>', $xfs)).'</cellXfs>'
            .'</styleSheet>';
    }
}
