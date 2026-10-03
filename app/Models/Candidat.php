<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Compte du portail candidats (table existante `candidats`) : connexion par
 * e-mail et mot de passe, distincte des comptes du personnel (garde
 * « candidat »).
 *
 * @property int $id
 * @property string $nom
 * @property string $prenom
 * @property string $email
 * @property string $telephone
 * @property bool $actif
 */
class Candidat extends Authenticatable
{
    protected $table = 'candidats';

    protected $guarded = ['id'];

    protected $hidden = ['mot_de_passe', 'token_reset'];

    // Pas de colonne remember_token dans la table
    protected $rememberTokenName = '';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'mot_de_passe' => 'hashed',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe';
    }

    public function nomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }
}
