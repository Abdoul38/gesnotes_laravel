<?php

namespace App\Services;

use App\Universites\UniversiteCourante;

/**
 * Clé portée par le QR code d'un document : prouve que la personne a le
 * document en main. Sans elle (numéro tapé, documents imprimés avant cette
 * clé), la vérification publique ne montre qu'une version réduite — les
 * numéros se suivent et permettaient de relever noms, mentions et moyennes.
 *
 * Signée avec VERIFICATION_SECRET (à défaut, APP_KEY) : à ne jamais changer
 * une fois des documents imprimés, sinon leurs QR ne donneraient plus que la
 * version réduite.
 */
class CleVerification
{
    public function __construct(private readonly UniversiteCourante $universite) {}

    public function cle(string $numero): string
    {
        $secret = (string) (config('gesnotes.verification_secret') ?: config('app.key'));

        return substr(hash_hmac('sha256', mb_strtoupper(trim($numero)).'|'.$this->universite->get()->code, $secret), 0, 16);
    }

    public function valide(string $numero, ?string $cle): bool
    {
        return $cle !== null && $cle !== '' && hash_equals($this->cle($numero), $cle);
    }

    /** Adresse à mettre dans le QR code du document. */
    public function url(string $numero): string
    {
        return route('verifier_diplome', ['num' => $numero, 'cle' => $this->cle($numero)]);
    }
}
