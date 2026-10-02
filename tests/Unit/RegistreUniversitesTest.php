<?php

namespace Tests\Unit;

use App\Universites\RegistreUniversites;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Mêmes résultats que l'ancien config/loader.php. */
class RegistreUniversitesTest extends TestCase
{
    private function registre(bool $parametreUni = false): RegistreUniversites
    {
        $u = fn ($code, $extra = []) => $extra + ['code' => $code, 'nom' => $code, 'sigle' => strtoupper($code), 'base' => "b_$code", 'actif' => true, 'espace' => 'gesnotes'];

        return new RegistreUniversites([
            'defaut' => 'udh',
            'autoriser_parametre_uni' => $parametreUni,
            'liste' => [
                'udh' => $u('udh'), 'uam' => $u('uam'), 'udo' => $u('udo', ['actif' => false]),
                'ministere' => $u('ministere', ['espace' => 'ministere', 'base' => null]),
            ],
        ]);
    }

    public static function hotes(): array
    {
        return [
            'sous-domaine local' => ['uam.localhost', 'uam'],
            'localhost nu' => ['localhost', null],
            '127.0.0.1' => ['127.0.0.1', null],
            'inconnu → défaut' => ['xyz.localhost', 'udh'],
            'inactif → défaut' => ['udo.localhost', 'udh'],
            'alwaysdata → défaut' => ['edufile.alwaysdata.net', 'udh'],
            'domaine nu' => ['cursus-niger.net', null],
            'production' => ['uam.cursus-niger.net', 'uam'],
            'majuscules' => ['UAM.Cursus-Niger.net', 'uam'],
            'ministère' => ['ministere.cursus-niger.net', 'ministere'],
        ];
    }

    #[DataProvider('hotes')]
    public function test_choix_de_l_universite_selon_l_hote(string $hote, ?string $attendu): void
    {
        $this->assertSame($attendu, $this->registre()->resoudre($hote)?->code);
    }

    public function test_parametre_uni_ignore_quand_desactive(): void
    {
        $this->assertSame('udh', $this->registre(false)->resoudre('udh.localhost', 'uam')->code);
    }

    public function test_parametre_uni_quand_autorise_mais_jamais_pour_le_ministere(): void
    {
        $this->assertSame('uam', $this->registre(true)->resoudre('udh.localhost', 'uam')->code);
        $this->assertSame('ministere', $this->registre(true)->resoudre('ministere.localhost', 'uam')->code);
    }

    public function test_actives_exclut_inactives_et_espaces_nationaux(): void
    {
        $this->assertSame(['udh', 'uam'], array_keys($this->registre()->actives()));
    }
}
