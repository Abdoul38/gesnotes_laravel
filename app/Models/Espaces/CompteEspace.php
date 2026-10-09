<?php

namespace App\Models\Espaces;

use App\Espaces\Espaces;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Compte d'un espace national (Ministère, ANAB) : tables existantes
 * mesr_comptes / anab_comptes, dans la base propre aux espaces si elle est
 * configurée (Espaces::connexion()), sinon dans celle de l'université
 * gesnotes.espaces_comptes. Connexion par identifiant (login).
 *
 * @property int $id
 * @property string $login
 * @property string $nom_complet
 * @property string $role
 * @property bool $actif
 */
abstract class CompteEspace extends Authenticatable
{
    protected $guarded = ['id'];

    protected $hidden = ['mot_de_passe'];

    // Pas de colonne remember_token dans ces tables
    protected $rememberTokenName = '';

    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return Espaces::connexion() ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'mot_de_passe' => 'hashed', 'derniere_connexion' => 'datetime'];
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe';
    }

    public function estAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
