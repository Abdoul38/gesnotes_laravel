<?php

namespace App\Services;

use App\Universites\UniversiteCourante;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Licence hors-ligne — portage à l'identique de includes/licence_functions.php.
 * Clé = base64("institution|expiration|max_etudiants") . "." . HMAC-SHA256 tronqué à 16 caractères.
 * Les clés déjà émises restent valides tant que LICENCE_SECRET est le même.
 */
class Licence
{
    public function __construct(
        private readonly string $secret,
        private readonly UniversiteCourante $universite,
    ) {}

    public function generer(string $institution, string $expiration = '', int $maxEtudiants = 0): string
    {
        $payload = $institution.'|'.$expiration.'|'.$maxEtudiants;

        return base64_encode($payload).'.'.$this->signer($payload);
    }

    /** @return array{valide:bool, erreur:?string, institution:?string, expiration:?string, max_etudiants:?int} */
    public function verifier(string $cle): array
    {
        $cle = trim($cle);
        if ($cle === '' || ! str_contains($cle, '.')) {
            return $this->invalide('Clé de licence manquante ou mal formée.');
        }

        [$payloadEncode, $signatureRecue] = explode('.', $cle, 2);
        $payload = base64_decode($payloadEncode, true);
        if ($payload === false) {
            return $this->invalide('Clé de licence invalide (format incorrect).');
        }
        if ($this->secret === '' || ! hash_equals($this->signer($payload), $signatureRecue)) {
            return $this->invalide('Clé de licence invalide ou falsifiée.');
        }

        $parties = explode('|', $payload);
        if (count($parties) !== 3) {
            return $this->invalide('Clé de licence corrompue.');
        }
        [$institution, $expiration, $maxEtudiants] = $parties;
        $maxEtudiants = (int) $maxEtudiants;

        if ($expiration !== '' && strtotime($expiration) < strtotime(date('Y-m-d'))) {
            return ['valide' => false, 'erreur' => "Licence expirée depuis le $expiration.", 'institution' => $institution, 'expiration' => $expiration, 'max_etudiants' => $maxEtudiants];
        }

        return ['valide' => true, 'erreur' => null, 'institution' => $institution, 'expiration' => $expiration ?: null, 'max_etudiants' => $maxEtudiants];
    }

    /** Licence enregistrée pour l'université courante (paramètre `licence_cle`). */
    public function verifierInstallation(): array
    {
        try {
            $cle = (string) DB::table('parametres')->where('cle', 'licence_cle')->value('valeur');
        } catch (Throwable) {
            return $this->invalide('Impossible de lire la licence (base de données).');
        }

        $r = $this->verifier($cle);
        if (! $r['valide']) {
            return $r;
        }

        // La licence doit appartenir à l'université qui l'utilise.
        $code = $this->universite->get()->code;
        if ($r['institution'] !== '' && strcasecmp($r['institution'], $code) !== 0) {
            return ['valide' => false, 'erreur' => "Cette licence appartient à l'établissement « {$r['institution']} », pas à celui-ci."] + $r;
        }

        if (! empty($r['max_etudiants'])) {
            try {
                $nb = DB::table('etudiants')->count();
                if ($nb > $r['max_etudiants']) {
                    $r['valide'] = false;
                    $r['erreur'] = "Limite de {$r['max_etudiants']} étudiants dépassée ($nb actuellement) — contactez le fournisseur pour une mise à niveau.";
                }
            } catch (Throwable) {
            }
        }

        return $r;
    }

    private function signer(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, $this->secret), 0, 16);
    }

    private function invalide(string $erreur): array
    {
        return ['valide' => false, 'erreur' => $erreur, 'institution' => null, 'expiration' => null, 'max_etudiants' => null];
    }
}
