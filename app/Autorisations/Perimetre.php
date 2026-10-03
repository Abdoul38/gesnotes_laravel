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

    /** Étudiant dont la filière, ou l'une des inscriptions, relève de la faculté (règle de la liste des étudiants). */
    public function etudiant(Utilisateur $u, int $etudiantId): bool
    {
        $f = $this->faculte($u);

        return $f === 0
            || DB::table('etudiants as e')->join('filieres as fe', 'fe.id', '=', 'e.filiere_id')
                ->where('e.id', $etudiantId)->where('fe.faculte_id', $f)->exists()
            || DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('filieres as fi', 'fi.id', '=', 'p.filiere_id')
                ->where('i.etudiant_id', $etudiantId)->where('fi.faculte_id', $f)->exists();
    }

    /**
     * Restreint une requête sur les étudiants (alias « e ») au périmètre :
     * faculté (filière ou inscription), département pour un chef de département.
     */
    public function limiterEtudiants(\Illuminate\Database\Query\Builder $q, Utilisateur $u): \Illuminate\Database\Query\Builder
    {
        $departement = $u->role === 'chef_departement' ? $u->departementId() : 0;
        [$colonne, $valeur] = $departement ? ['departement_id', $departement] : ['faculte_id', $this->faculte($u)];
        if (! $valeur) {
            return $q;
        }

        return $q->where(fn ($w) => $w
            ->whereExists(fn ($s) => $s->from('filieres as fe')->whereColumn('fe.id', 'e.filiere_id')->where("fe.$colonne", $valeur))
            ->orWhereExists(fn ($s) => $s->from('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                ->join('filieres as fi', 'fi.id', '=', 'p.filiere_id')->whereColumn('i.etudiant_id', 'e.id')->where("fi.$colonne", $valeur)));
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
