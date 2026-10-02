<?php

namespace App\Universites;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Université de la requête en cours. Bascule la connexion « universite »
 * vers sa base : tous les modèles l'utilisent par défaut.
 */
class UniversiteCourante
{
    private ?Universite $universite = null;

    public function definir(Universite $universite): void
    {
        if (! $universite->estUniversite() || ! $universite->base) {
            throw new RuntimeException("« {$universite->code} » n'a pas de base d'université.");
        }

        if ($this->universite?->code !== $universite->code) {
            config(['database.connections.universite.database' => $universite->base]);
            DB::purge('universite');
        }
        $this->universite = $universite;
    }

    public function get(): Universite
    {
        return $this->universite ?? throw new RuntimeException('Aucune université définie pour cette requête.');
    }

    public function estDefinie(): bool
    {
        return $this->universite !== null;
    }
}
