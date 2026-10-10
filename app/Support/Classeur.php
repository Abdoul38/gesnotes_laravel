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

    private bool $formules = false;

    /** Empreinte du mot de passe de protection : [hash, sel, itérations] (SHA-512, format Office). */
    private ?array $empreinte = null;

    /** Feuille de styles propre au document (sinon les 12 styles ci-dessus). */
    public function __construct(private readonly ?string $styles = null) {}

    /**
     * Mot de passe des feuilles protégées et de la structure du classeur
     * (empêche de réafficher ou de supprimer une feuille masquée). Sans appel,
     * mot de passe aléatoire jamais communiqué.
     */
    public function motDePasse(string $motDePasse): void
    {
        $sel = random_bytes(16);
        $h = hash('sha512', $sel.mb_convert_encoding($motDePasse, 'UTF-16LE', 'UTF-8'), true);
        for ($i = 0; $i < 100000; $i++) {
            $h = hash('sha512', $h.pack('V', $i), true);
        }
        $this->empreinte = [base64_encode($h), base64_encode($sel), 100000];
    }

    /**
     * @param  list<array{height?: int, merges?: list<array{0:int,1:int}>, cells: list<array{v?: mixed, f?: string, style?: int}>}>  $lignes
     * @param  list<int|float>  $largeurs
     */
    /**
     * @param  list<array{0:int,1:int}>  $groupes  colonnes groupées (numéros de 1 à n, bornes comprises) : bouton −/+ d'Excel pour les replier
     * @param  array{masquee?: bool, protegee?: bool, validations?: list<array{plage: string, min: float, max: float, titre: string}>}  $options
     *                                                                                                                                   masquee : feuille invisible, qu'on ne peut pas réafficher depuis Excel ;
     *                                                                                                                                   protegee : cellules verrouillées sauf les styles déverrouillés (zone de saisie) ;
     *                                                                                                                                   validations : nombres refusés hors de [min, max] dans la plage
     */
    public function feuille(string $nom, array $lignes, array $largeurs = [], int $lignesFigees = 0, array $fusions = [], ?string $filtre = null, array $groupes = [], array $options = []): void
    {
        $nom = mb_substr(trim(preg_replace('/[\/\\\\?*\[\]:]/', '_', $nom)) ?: 'Feuille', 0, 31);
        $pris = array_map('mb_strtolower', array_column($this->feuilles, 'nom'));
        for ($n = 2, $base = $nom; in_array(mb_strtolower($nom), $pris, true); $n++) {
            $nom = mb_substr($base, 0, 31 - strlen(" ($n)"))." ($n)";
        }
        // $filtre : plage « A4:U30 » dont la première ligne porte les boutons de filtre d'Excel
        $filtre = $filtre !== null && preg_match('/^[A-Z]{1,3}\d+:[A-Z]{1,3}\d+$/', $filtre) ? $filtre : null;
        $this->feuilles[] = ['nom' => $nom, 'lignes' => $lignes, 'largeurs' => $largeurs, 'figees' => $lignesFigees, 'fusions' => $fusions, 'filtre' => $filtre, 'groupes' => $groupes, 'options' => $options];
    }

    /** Écrit le classeur dans un fichier temporaire et retourne son chemin. */
    public function enregistrer(): string
    {
        if (! $this->feuilles) {
            $this->feuille('Liste', [['cells' => [['v' => 'Aucun étudiant']]]]);
        }
        $protection = collect($this->feuilles)->contains(fn ($f) => ! empty($f['options']['protegee']) || ! empty($f['options']['masquee']));
        if ($protection && ! $this->empreinte) {
            $this->motDePasse(bin2hex(random_bytes(16)));
        }
        $xmlFeuilles = array_map(fn ($f) => $this->xmlFeuille($f), $this->feuilles);

        $rels = $elements = $types = $noms = '';
        foreach ($this->feuilles as $i => $f) {
            if ($f['filtre']) {
                // Nom réservé qu'Excel associe au filtre automatique de la feuille
                $plage = preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $f['filtre']);
                $noms .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$i.'" hidden="1">\''.self::xml(str_replace("'", "''", $f['nom'])).'\'!'.$plage.'</definedName>';
            }
            $id = $i + 1;
            $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
            $elements .= '<sheet name="'.self::xml($f['nom']).'" sheetId="'.$id.'"'.(! empty($f['options']['masquee']) ? ' state="veryHidden"' : '').' r:id="rId'.$id.'"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $entete = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $sst = $entete.'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($this->chaines).'" uniqueCount="'.count($this->chaines).'">';
        foreach (array_keys($this->chaines) as $s) {
            $sst .= '<si><t xml:space="preserve">'.self::xml((string) $s).'</t></si>';
        }
        $sst .= '</sst>';

        $chemin = FichierTemporaire::chemin('xlsx_', '.xlsx');
        $zip = new ZipArchive;
        $zip->open($chemin, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', $entete.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$types.'</Types>');
        $zip->addFromString('_rels/.rels', $entete.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        // Classeur avec formules : recalcul complet à l'ouverture
        $calcul = $this->formules ? '<calcPr calcId="0" fullCalcOnLoad="1"/>' : '';
        $zip->addFromString('xl/workbook.xml', $entete.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .($protection ? '<workbookProtection workbookAlgorithmName="SHA-512" workbookHashValue="'.$this->empreinte[0].'" workbookSaltValue="'.$this->empreinte[1].'" workbookSpinCount="'.$this->empreinte[2].'" lockStructure="1"/>' : '')
            .'<sheets>'.$elements.'</sheets>'.($noms ? '<definedNames>'.$noms.'</definedNames>' : '').$calcul.'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $entete.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId100" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'<Relationship Id="rId101" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'.$rels.'</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', $sst);
        $zip->addFromString('xl/styles.xml', $this->styles ?? self::styles());
        foreach ($xmlFeuilles as $i => $x) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $x);
        }
        $zip->close();

        return $chemin;
    }

    private function xmlFeuille(array $f): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        // Colonnes groupées : boutons de repli au-dessus des colonnes, le bouton à droite du groupe (côté résumé)
        $niveaux = [];
        foreach ($f['groupes'] ?? [] as [$de, $a]) {
            for ($c = $de; $c <= $a; $c++) {
                $niveaux[$c] = 1;
            }
        }
        if ($niveaux) {
            $x .= '<sheetPr><outlinePr summaryRight="1"/></sheetPr>';
        }
        if ($f['figees']) {
            $x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$f['figees'].'" topLeftCell="A'.($f['figees'] + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if ($niveaux) {
            $x .= '<sheetFormatPr defaultRowHeight="15" outlineLevelCol="1"/>';
        }
        if ($f['largeurs']) {
            $x .= '<cols>';
            foreach ($f['largeurs'] as $i => $w) {
                $x .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"'.(isset($niveaux[$i + 1]) ? ' outlineLevel="1"' : '').'/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        $fusions = $f['fusions'];   // zones « A2:A4 » données directement (fusions sur plusieurs lignes)
        foreach ($f['lignes'] as $ri => $ligne) {
            $r = $ri + 1;
            $x .= '<row r="'.$r.'" ht="'.($ligne['height'] ?? 16).'" customHeight="1">';
            foreach ($ligne['cells'] as $ci => $cellule) {
                $ref = self::ref($ci + 1, $r);
                $style = (int) ($cellule['style'] ?? 0);
                $v = $cellule['v'] ?? '';
                if (isset($cellule['f'])) {
                    // Formule (syntaxe anglaise du format de fichier : IF, ROUND, virgules) et sa valeur déjà calculée,
                    // affichée telle quelle et relue par les imports
                    $this->formules = true;
                    $formule = '<f>'.self::xml((string) $cellule['f']).'</f>';
                    $x .= match (true) {
                        $v === '' || $v === null => '<c r="'.$ref.'" s="'.$style.'">'.$formule.'</c>',
                        is_bool($v) => '<c r="'.$ref.'" s="'.$style.'" t="b">'.$formule.'<v>'.($v ? 1 : 0).'</v></c>',
                        is_int($v) || is_float($v) => '<c r="'.$ref.'" s="'.$style.'">'.$formule.'<v>'.$v.'</v></c>',
                        default => '<c r="'.$ref.'" s="'.$style.'" t="str">'.$formule.'<v>'.self::xml((string) $v).'</v></c>',
                    };
                } elseif ($v === '' || $v === null) {
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
        if (! empty($f['options']['protegee']) || ! empty($f['options']['masquee'])) {
            // Tout est verrouillé sauf les cellules au style déverrouillé ; filtres et masquage de colonnes permis
            [$hash, $sel, $tours] = $this->empreinte;
            $x .= '<sheetProtection algorithmName="SHA-512" hashValue="'.$hash.'" saltValue="'.$sel.'" spinCount="'.$tours.'" sheet="1" objects="1" scenarios="1"'
                .' formatColumns="0" autoFilter="0"/>';
        }
        if ($f['filtre']) {
            $x .= '<autoFilter ref="'.$f['filtre'].'"/>';
        }
        if ($fusions) {
            $x .= '<mergeCells count="'.count($fusions).'">'.implode('', array_map(fn ($m) => '<mergeCell ref="'.$m.'"/>', $fusions)).'</mergeCells>';
        }
        $validations = $f['options']['validations'] ?? [];
        if ($validations) {
            $x .= '<dataValidations count="'.count($validations).'">';
            foreach ($validations as $v) {
                [$min, $max] = [self::nombre($v['min']), self::nombre($v['max'])];
                $x .= '<dataValidation type="decimal" operator="between" allowBlank="1" showInputMessage="1" showErrorMessage="1" errorStyle="stop"'
                    .' errorTitle="Note refusée" error="'.self::xml($v['titre'].' : saisir une note de '.$min.' à '.$max.'.').'"'
                    .' promptTitle="'.self::xml(mb_substr($v['titre'], 0, 32)).'" prompt="'.self::xml('Note de '.$min.' à '.$max).'" sqref="'.$v['plage'].'">'
                    .'<formula1>'.$min.'</formula1><formula2>'.$max.'</formula2></dataValidation>';
            }
            $x .= '</dataValidations>';
        }

        return $x.'</worksheet>';
    }

    private static function nombre(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    public static function ref(int $col, int $ligne): string
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
