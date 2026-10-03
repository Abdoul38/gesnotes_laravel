<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Compte parent (table existante `parents`) : connexion à l'Espace Parents
 * par numéro de téléphone et mot de passe, distincte des comptes du
 * personnel (garde « parent »).
 *
 * @property int $id
 * @property string $nom
 * @property string $prenoms
 * @property string $telephone
 * @property ?string $email
 * @property bool $actif
 */
class ParentEleve extends Authenticatable
{
    protected $table = 'parents';

    protected $guarded = ['id'];

    protected $hidden = ['mot_de_passe_hash'];

    // Pas de colonne remember_token dans la table
    protected $rememberTokenName = '';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'derniere_connexion' => 'datetime',
            'mot_de_passe_hash' => 'hashed',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe_hash';
    }
}
