<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use ZipArchive;

/**
 * Lecture des fichiers déposés pour les imports (XLSX sans bibliothèque,
 * CSV / TXT) — reprend les lecteurs écrits séparément dans
 * etudiant_importer.php et import_etudiants.php.
 *
 * Différences voulues : le XML est lu sans accès réseau ni entités
 * externes, une feuille anormalement grosse une fois décompressée est
 * refusée, et les cellules « texte en ligne » (inlineStr) sont lues.
 */
class Tableur
{
    /** Taille maximale d'une partie XML décompressée (protège des « bombes ZIP »). */
    private const MAX_XML_OCTETS = 50 * 1024 * 1024;

    /**
     * Lignes de la première feuille d'un XLSX, indexées par numéro de ligne
     * Excel (1, 2, …) ; cellules manquantes complétées par ''.
     * Tableau vide si le fichier n'est pas un XLSX lisible.
     *
     * @return array<int, list<string>>
     */
    public static function xlsx(string $chemin): array
    {
        $zip = new ZipArchive;
        if ($zip->open($chemin) !== true) {
            return [];
        }
        $lire = function (string $nom) use ($zip): ?string {
            $stat = $zip->statName($nom);

            return $stat && $stat['size'] <= self::MAX_XML_OCTETS ? ($zip->getFromName($nom) ?: null) : null;
        };
        $partages = $lire('xl/sharedStrings.xml');
        $feuille = $lire('xl/worksheets/sheet1.xml');
        $zip->close();
        if (! $feuille) {
            return [];
        }

        $textes = [];
        if ($partages && ($dom = self::charger($partages))) {
            foreach ($dom->getElementsByTagName('si') as $si) {
                $textes[] = self::texte($si);
            }
        }

        $dom = self::charger($feuille);
        if (! $dom) {
            return [];
        }
        $lignes = [];
        foreach ($dom->getElementsByTagName('row') as $i => $ligne) {
            $cellules = [];
            foreach ($ligne->getElementsByTagName('c') as $c) {
                if (! preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $m)) {
                    continue;
                }
                $colonne = 0;
                foreach (str_split($m[1]) as $lettre) {
                    $colonne = $colonne * 26 + (ord($lettre) - 64);
                }
                $v = $c->getElementsByTagName('v')->item(0)?->textContent ?? '';
                $cellules[$colonne - 1] = match ($c->getAttribute('t')) {
                    's' => $textes[(int) $v] ?? '',
                    'b' => $v ? 'VRAI' : 'FAUX',
                    'inlineStr' => self::texte($c),
                    default => $v,
                };
            }
            $numero = (int) $ligne->getAttribute('r') ?: $i + 1;
            $lignes[$numero] = $cellules ? array_map(fn ($k) => $cellules[$k] ?? '', range(0, max(array_keys($cellules)))) : [];
        }

        return $lignes;
    }

    /** Contenu d'un CSV en UTF-8 (BOM retiré, Windows-1252 converti, fins de ligne unifiées). */
    public static function texteCsv(string $chemin): string
    {
        $brut = (string) file_get_contents($chemin);
        if (str_starts_with($brut, "\xEF\xBB\xBF")) {
            $brut = substr($brut, 3);
        }
        if (! mb_check_encoding($brut, 'UTF-8')) {
            $brut = mb_convert_encoding($brut, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $brut);
    }

    /** Séparateur le plus fréquent de la ligne : ; , ou tabulation. */
    public static function separateur(string $ligne): string
    {
        $comptes = [';' => substr_count($ligne, ';'), ',' => substr_count($ligne, ','), "\t" => substr_count($ligne, "\t")];
        arsort($comptes);

        return array_key_first($comptes);
    }

    private static function charger(string $xml): ?DOMDocument
    {
        $dom = new DOMDocument;

        return @$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) ? $dom : null;
    }

    /** Texte d'une chaîne Excel : tous ses morceaux <t> (texte enrichi compris). */
    private static function texte(DOMElement $element): string
    {
        $texte = '';
        foreach ($element->getElementsByTagName('t') as $t) {
            // Les indications phonétiques (<rPh>) ne font pas partie du texte
            if ($t->parentNode?->nodeName !== 'rPh') {
                $texte .= $t->textContent;
            }
        }

        return $texte;
    }
}
