<?php

namespace App\Universites;

/**
 * Lecture du registre et choix de l'université d'après l'hôte de la requête.
 * Reprend à l'identique les règles de l'ancien config/loader.php.
 */
class RegistreUniversites
{
    /** @var array<string, Universite> */
    private array $universites = [];

    public function __construct(private readonly array $config)
    {
        foreach ($config['liste'] as $code => $infos) {
            $this->universites[$code] = Universite::depuisConfig($infos);
        }
    }

    public function trouver(string $code): ?Universite
    {
        return $this->universites[$code] ?? null;
    }

    public function parDefaut(): Universite
    {
        return $this->universites[$this->config['defaut']];
    }

    /** @return array<string, Universite> universités actives (hors ministère et ANAB) */
    public function actives(): array
    {
        return array_filter($this->universites, fn (Universite $u) => $u->actif && $u->estUniversite());
    }

    /**
     * Code demandé par l'hôte, ou null pour la page de présentation
     * (domaine nu, localhost ou 127.0.0.1 sans sous-domaine).
     * L'hôte arrive sans port (Request::getHost()).
     */
    public function codeDepuisHote(string $hote): ?string
    {
        $hote = strtolower($hote);

        // 127.0.0.1 : pas de sous-domaine possible → présentation
        if (str_contains($hote, '127.0.0.1')) {
            return null;
        }
        if (str_contains($hote, 'localhost')) {
            $parties = explode('.', $hote);

            return (count($parties) >= 2 && $parties[0] !== 'localhost') ? $parties[0] : null;
        }
        // Hébergement Alwaysdata : domaine unique, toujours l'université par défaut
        if (str_contains($hote, 'alwaysdata.net')) {
            return $this->config['defaut'];
        }
        $parties = explode('.', $hote);

        return count($parties) < 3 ? null : $parties[0];
    }

    /**
     * Université à utiliser pour cet hôte, en tenant compte de ?uni= et du
     * repli sur l'université par défaut (inconnue ou inactive).
     * Retourne null pour la page de présentation.
     */
    public function resoudre(string $hote, ?string $parametreUni = null): ?Universite
    {
        $code = $this->codeDepuisHote($hote);
        if ($code === null) {
            return null;
        }

        $espaceNational = in_array($code, ['ministere', 'anab'], true);
        if ($parametreUni !== null && ! $espaceNational && $this->config['autoriser_parametre_uni']) {
            $code = preg_replace('/[^a-z]/', '', strtolower($parametreUni));
        }

        $universite = $this->trouver($code);

        return ($universite && $universite->actif) ? $universite : $this->parDefaut();
    }
}
