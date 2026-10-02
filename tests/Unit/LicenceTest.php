<?php

namespace Tests\Unit;

use App\Services\Licence;
use App\Universites\UniversiteCourante;
use PHPUnit\Framework\TestCase;

class LicenceTest extends TestCase
{
    private function licence(string $secret = 'cle-secrete'): Licence
    {
        return new Licence($secret, new UniversiteCourante);
    }

    /** Copie exacte de genererLicence() de l'ancienne application. */
    private function genererCommeAvant(string $secret, string $inst, string $exp = '', int $max = 0): string
    {
        $payload = $inst.'|'.$exp.'|'.$max;

        return base64_encode($payload).'.'.substr(hash_hmac('sha256', $payload, $secret), 0, 16);
    }

    public function test_une_cle_emise_par_l_ancienne_application_reste_valide(): void
    {
        $r = $this->licence()->verifier($this->genererCommeAvant('cle-secrete', 'UDH', '2099-12-31', 50000));
        $this->assertTrue($r['valide']);
        $this->assertSame(['UDH', '2099-12-31', 50000], [$r['institution'], $r['expiration'], $r['max_etudiants']]);
    }

    public function test_aller_retour(): void
    {
        $l = $this->licence();
        $this->assertTrue($l->verifier($l->generer('UAM'))['valide']);
        $this->assertNull($l->verifier($l->generer('UAM'))['expiration']);
    }

    public function test_cle_falsifiee_ou_autre_secret(): void
    {
        $cle = $this->licence()->generer('UDH', '2099-01-01');
        [$p, $sig] = explode('.', $cle);
        $this->assertSame('Clé de licence invalide ou falsifiée.', $this->licence()->verifier(base64_encode('UDH|2199-01-01|0').'.'.$sig)['erreur']);
        $this->assertFalse($this->licence('autre')->verifier($cle)['valide']);
        $this->assertFalse($this->licence('')->verifier($cle)['valide'], 'secret vide = tout refuser');
    }

    public function test_messages_d_erreur(): void
    {
        $l = $this->licence();
        $this->assertSame('Clé de licence manquante ou mal formée.', $l->verifier('')['erreur']);
        $this->assertSame('Clé de licence invalide (format incorrect).', $l->verifier('@@@.abc')['erreur']);
        $this->assertSame('Licence expirée depuis le 2001-01-01.', $l->verifier($l->generer('UDH', '2001-01-01'))['erreur']);
        $corrompue = base64_encode('UDH|x').'.'.substr(hash_hmac('sha256', 'UDH|x', 'cle-secrete'), 0, 16);
        $this->assertSame('Clé de licence corrompue.', $l->verifier($corrompue)['erreur']);
    }

    public function test_expire_aujourd_hui_reste_valide(): void
    {
        $this->assertTrue($this->licence()->verifier($this->licence()->generer('UDH', date('Y-m-d')))['valide']);
    }
}
