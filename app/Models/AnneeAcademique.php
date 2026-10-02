<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Table existante `annees_academiques`. */
class AnneeAcademique extends Model
{
    protected $table = 'annees_academiques';

    protected $guarded = ['id'];

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'date_debut' => 'date', 'date_fin' => 'date'];
    }

    /** Année marquée active=1 (une seule à la fois). */
    public static function active(): ?self
    {
        return static::where('active', 1)->first();
    }
}
