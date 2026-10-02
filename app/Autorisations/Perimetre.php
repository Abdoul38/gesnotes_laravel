<?php

namespace App\Autorisations;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Périmètre faculté d'un utilisateur (faculte_id ; 0 = toute l'université).
 *
 * Les anciennes pages filtraient leurs LISTES par faculté, mais leurs
 * ACTIONS acceptaient n'importe quel identifiant envoyé : un compte d'une
 * faculté pouvait modifier les données d'une autre en changeant un id.
 * Ces vérifications ferment cette porte.
 */
class Perimetre
{
    /** Faculté imposée à l'utilisateur, ou 0 s'il voit tout. */
    public function faculte(Utilisateur $u): int
    {
        return ($u->faculteId() && Schema::hasColumn('filieres', 'faculte_id')) ? $u->faculteId() : 0;
    }

    public function faculteAutorisee(Utilisateur $u, ?int $faculteId): bool
    {
        $f = $this->faculte($u);

        return $f === 0 || (int) $faculteId === $f;
    }

    public function filiere(Utilisateur $u, int $filiereId): bool
    {
        return $this->faculte($u) === 0
            || DB::table('filieres')->where('id', $filiereId)->where('faculte_id', $this->faculte($u))->exists();
    }

    public function departement(Utilisateur $u, int $departementId): bool
    {
        return $this->faculte($u) === 0
            || DB::table('departements')->where('id', $departementId)->where('faculte_id', $this->faculte($u))->exists();
    }

    public function promotion(Utilisateur $u, int $promotionId): bool
    {
        return $this->faculte($u) === 0
            || DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                ->where('p.id', $promotionId)->where('f.faculte_id', $this->faculte($u))->exists();
    }

    public function ue(Utilisateur $u, int $ueId): bool
    {
        $promo = DB::table('unites_enseignement')->where('id', $ueId)->value('promotion_id');

        return $promo !== null && $this->promotion($u, (int) $promo);
    }

    public function matiere(Utilisateur $u, int $matiereId): bool
    {
        $ue = DB::table('matieres')->where('id', $matiereId)->value('ue_id');

        return $ue !== null && $this->ue($u, (int) $ue);
    }
}
