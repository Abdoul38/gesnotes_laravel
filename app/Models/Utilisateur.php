<?php

namespace App\Models;

use App\Autorisations\Roles;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Compte du back-office (table existante `utilisateurs`).
 *
 * @property int $id
 * @property string $nom
 * @property string $prenoms
 * @property string $email
 * @property string $role
 * @property bool $actif
 * @property ?int $faculte_id
 * @property ?int $departement_id
 * @property ?int $etudiant_id
 * @property ?int $enseignant_id
 */
class Utilisateur extends Authenticatable
{
    protected $table = 'utilisateurs';

    protected $guarded = ['id'];

    protected $hidden = ['mot_de_passe'];

    // La table n'a pas de colonne remember_token : « se souvenir de moi »
    // n'existait pas dans l'ancienne application.
    protected $rememberTokenName = '';

    /** @var array<string, bool>|null permissions du rôle, chargées une fois */
    private ?array $permissions = null;

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'derniere_connexion' => 'datetime',
            'mot_de_passe' => 'hashed',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe';
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function departement(): BelongsTo
    {
        return $this->belongsTo(Departement::class);
    }

    public function estAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Ancien hasRole(). */
    public function aRole(string $role): bool
    {
        return Roles::satisfait($this->role, $role);
    }

    /**
     * Ancien hasPermission() : admin a tout, sinon table role_permissions
     * (éditable dans « Rôles & Permissions »).
     */
    public function aPermission(string $code): bool
    {
        if ($this->estAdmin()) {
            return true;
        }
        $this->permissions ??= DB::table('role_permissions')
            ->where('role', $this->role)
            ->pluck('permission_code')
            ->flip()
            ->map(fn () => true)
            ->all();

        return isset($this->permissions[$code]);
    }

    /** Rôle (ex. 'secretaire') ou code de permission (ex. 'paiements_voir'). */
    public function autorise(string $roleOuPermission): bool
    {
        return Roles::estUnRole($roleOuPermission)
            ? $this->aRole($roleOuPermission)
            : $this->aPermission($roleOuPermission);
    }

    /** 0 = toutes les facultés (ancien getFaculteId()). */
    public function faculteId(): int
    {
        return (int) $this->faculte_id;
    }

    /** 0 = tous les départements (ancien getDepartementId()). */
    public function departementId(): int
    {
        return (int) $this->departement_id;
    }

    /** Ancien voitTout() : admin, ou compte rattaché à aucune faculté. */
    public function voitTout(): bool
    {
        return $this->estAdmin() || $this->faculteId() === 0;
    }

    public function nomComplet(): string
    {
        return trim($this->nom.' '.$this->prenoms);
    }

    public function initiales(): string
    {
        return mb_strtoupper(mb_substr($this->nom, 0, 1).mb_substr($this->prenoms, 0, 1));
    }
}
