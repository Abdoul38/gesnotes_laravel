<?php

namespace App\Models\Espaces;

/** Compte du tableau de bord national du Ministère (rôles admin, lecteur). */
class CompteMesr extends CompteEspace
{
    protected $table = 'mesr_comptes';
}
