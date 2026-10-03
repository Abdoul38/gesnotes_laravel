<?php

namespace App\Support;

use ZipArchive;

/**
 * Modèle XLSX de l'import en masse avec inscription (ancien
 * import_etudiants.php?template=1) : titre, ligne d'en-têtes, deux exemples.
 * Produit le même fichier que l'original, sans bibliothèque.
 */
final class ModeleImportEtudiants
{
    public const ENTETES = [
        'Matricule', 'Nom*', 'Prenoms*', 'Sexe* (M/F)', 'Date_Naissance (JJ/MM/AAAA)',
        'Lieu_Naissance', 'Nationalite', 'Telephone', 'Email',
        'Adresse', 'Nom_Pere', 'Nom_Mere', 'Tel_Urgence',
        'BAC_Serie', 'BAC_Annee', 'BAC_Mention', 'Etablissement_Origine',
        'Numero_Dossier', 'Montant_Paye', 'Mode_Paiement',
    ];

    private const EXEMPLES = [
        ['00012', 'ABDOU', 'Ibrahim Ali', 'M', '15/05/2002', 'Tahoua', 'Nigérienne', '+227 90000001', 'ibrahim@email.com', 'Quartier Centre', 'Abdou Moussa', 'Rakia Ali', '+227 90000002', 'D', '2021', 'Passable', 'Lycée Dan Kassawa', 'D123456', '10000', 'especes'],
        ['', 'MOUSSA', 'Fatima Ali', 'F', '20/08/2003', 'Agadez', 'Nigérienne', '+227 90000003', '', 'Quartier Nord', 'Moussa Bello', 'Aïcha Ibrahim', '+227 90000004', 'C', '2022', 'Bien', 'Lycée National', 'D654321', '10000', 'especes'],
    ];

    private const TITRE = 'Template Import Étudiants — * = obligatoire — Matricule: laisser vide pour génération automatique';

    private const LARGEURS = [18, 22, 24, 10, 18, 18, 14, 16, 24, 20, 20, 20, 16, 10, 10, 12, 22, 14, 14, 14];

    /** Chemin d'un fichier temporaire contenant le modèle (à supprimer après envoi). */
    public static function creer(): string
    {
        $chaines = [];
        $indice = function (string $s) use (&$chaines): int {
            return $chaines[$s] ??= count($chaines);
        };
        $col = fn (int $i) => chr(65 + $i);

        foreach (self::ENTETES as $h) {
            $indice($h);
        }
        $lignes = '<row r="1"><c r="A1" t="s" s="2"><v>'.$indice(self::TITRE).'</v></c></row><row r="2">';
        foreach (self::ENTETES as $i => $h) {
            $lignes .= '<c r="'.$col($i).'2" t="s" s="1"><v>'.$indice($h).'</v></c>';
        }
        $lignes .= '</row>';
        foreach (self::EXEMPLES as $ri => $ligne) {
            $n = $ri + 3;
            $lignes .= '<row r="'.$n.'">';
            foreach ($ligne as $i => $v) {
                $lignes .= $v !== '' ? '<c r="'.$col($i).$n.'" t="s" s="3"><v>'.$indice($v).'</v></c>' : '<c r="'.$col($i).$n.'" s="3"/>';
            }
            $lignes .= '</row>';
        }

        $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($chaines).'" uniqueCount="'.count($chaines).'">';
        foreach (array_keys($chaines) as $s) {
            $sst .= '<si><t xml:space="preserve">'.htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
        }
        $sst .= '</sst>';

        $colonnes = '<cols>';
        foreach (self::LARGEURS as $i => $w) {
            $colonnes .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
        }
        $colonnes .= '</cols>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts><font><sz val="10"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF1e3a5c"/><name val="Calibri"/></font>'
            .'<font><sz val="10"/><name val="Calibri"/></font></fonts>'
            .'<fills><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1e3a5c"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF0B3"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFe8f0fe"/></patternFill></fill></fills>'
            .'<borders><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"><color rgb="FFAAAAAA"/></left><right style="thin"><color rgb="FFAAAAAA"/></right><top style="thin"><color rgb="FFAAAAAA"/></top><bottom style="thin"><color rgb="FFAAAAAA"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0"/>'
            .'<xf numFmtId="0" fontId="2" fillId="4" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0"/></cellXfs>'
            .'</styleSheet>';

        $chemin = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive;
        $zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Étudiants" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$colonnes.'<sheetData>'.$lignes.'</sheetData></worksheet>');
        $zip->addFromString('xl/sharedStrings.xml', $sst);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->close();

        return $chemin;
    }
}
