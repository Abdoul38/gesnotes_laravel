<?php

namespace App\Universites;

/**
 * Une université (ou un espace national : ministère, ANAB) du registre
 * config/universites.php.
 */
final class Universite
{
    public function __construct(
        public readonly string $code,
        public readonly string $nom,
        public readonly string $sigle,
        public readonly string $ville,
        public readonly string $couleur,
        public readonly string $logo,
        public readonly ?string $base,
        public readonly bool $actif,
        public readonly string $espace,
    ) {}

    public static function depuisConfig(array $c): self
    {
        return new self(
            code: $c['code'],
            nom: $c['nom'],
            sigle: $c['sigle'],
            ville: $c['ville'] ?? '',
            couleur: $c['couleur'] ?? '#1A3A5C',
            logo: $c['logo'] ?? '',
            base: $c['base'] ?? null,
            actif: (bool) ($c['actif'] ?? false),
            espace: $c['espace'] ?? 'gesnotes',
        );
    }

    public function estUniversite(): bool
    {
        return $this->espace === 'gesnotes';
    }

    /** Nom affiché de l'application, comme l'ancien APP_NAME (« CURSUS — UDH »). */
    public function nomApplication(): string
    {
        return 'CURSUS — '.$this->sigle;
    }
}
