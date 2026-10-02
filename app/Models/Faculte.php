<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Table existante `facultes`. */
class Faculte extends Model
{
    protected $table = 'facultes';

    protected $guarded = ['id'];

    const UPDATED_AT = null; // la table n'a que created_at

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function departements(): HasMany
    {
        return $this->hasMany(Departement::class);
    }
}
