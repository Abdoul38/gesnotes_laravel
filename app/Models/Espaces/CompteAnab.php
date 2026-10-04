<?php

namespace App\Models\Espaces;

/** Compte du portail de l'Agence Nationale des Bourses (rôles admin, agent). */
class CompteAnab extends CompteEspace
{
    protected $table = 'anab_comptes';
}
