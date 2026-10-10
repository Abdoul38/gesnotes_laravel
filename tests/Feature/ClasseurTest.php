<?php

namespace Tests\Feature;

use App\Support\Classeur;
use Tests\TestCase;

/** Protection des classeurs Excel produits par l'application. */
class ClasseurTest extends TestCase
{
    public function test_mot_de_passe_au_format_office_sha512(): void
    {
        $classeur = new Classeur;
        $classeur->motDePasse('Secret2026');
        $classeur->feuille('PV', [['cells' => [['v' => 'x']]]], [], 0, [], null, [], ['protegee' => true]);
        $chemin = $classeur->enregistrer();
        $zip = new \ZipArchive;
        $zip->open($chemin);
        $feuille = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($chemin);

        $this->assertSame(1, preg_match('/hashValue="([^"]+)" saltValue="([^"]+)" spinCount="(\d+)"/', $feuille, $m));
        // Algorithme d'Office : H0 = SHA-512(sel + mot de passe UTF-16LE), puis Hn = SHA-512(Hn-1 + n sur 4 octets)
        $h = hash('sha512', base64_decode($m[2]).mb_convert_encoding('Secret2026', 'UTF-16LE', 'UTF-8'), true);
        for ($i = 0; $i < (int) $m[3]; $i++) {
            $h = hash('sha512', $h.pack('V', $i), true);
        }
        $this->assertSame($m[1], base64_encode($h));
    }

    public function test_sans_mot_de_passe_configure_un_mot_de_passe_aleatoire(): void
    {
        $empreintes = [];
        foreach ([1, 2] as $n) {
            $classeur = new Classeur;
            $classeur->feuille('PV', [['cells' => [['v' => 'x']]]], [], 0, [], null, [], ['protegee' => true]);
            $chemin = $classeur->enregistrer();
            $zip = new \ZipArchive;
            $zip->open($chemin);
            preg_match('/hashValue="([^"]+)"/', (string) $zip->getFromName('xl/worksheets/sheet1.xml'), $m);
            $zip->close();
            unlink($chemin);
            $empreintes[] = $m[1];
        }
        $this->assertNotSame($empreintes[0], $empreintes[1]);
    }
}
