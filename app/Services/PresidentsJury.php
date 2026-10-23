<?php

namespace App\Services;

use App\Models\Utilisateur;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Présidents du jury de délibération : un enseignant par semestre d'une
 * promotion (filière, niveau, année), pour tous les PV de ce semestre
 * (normale, rattrapage, délibération, réclamations).
 *
 * Le président voit ses PV, les verrouille et les déverrouille après la
 * délibération ; il ne saisit pas de notes dans le PV. Son nom est imprimé
 * au bas du PV, du PV de délibération et de la liste des admis.
 */
class PresidentsJury
{
    public function disponible(): bool
    {
        return Schema::hasTable('presidents_jury');
    }

    /** Président du semestre du PV (enseignant), ou null. */
    public function pour(object $pv): ?object
    {
        if (! $this->disponible()) {
            return null;
        }

        return DB::table('presidents_jury as pj')->join('enseignants as en', 'en.id', '=', 'pj.enseignant_id')
            ->where(['pj.promotion_id' => $pv->promotion_id, 'pj.semestre_id' => $pv->semestre_id])
            ->first(['en.id', 'en.nom', 'en.prenoms', 'en.grade']);
    }

    /** Nom imprimé : « Grade Prénoms NOM ». */
    public static function nom(?object $president): ?string
    {
        return $president ? trim(($president->grade ? $president->grade.' ' : '').$president->prenoms.' '.mb_strtoupper($president->nom)) : null;
    }

    public function preside(Utilisateur $u, object $pv): bool
    {
        return $u->enseignant_id && $this->disponible() && DB::table('presidents_jury')
            ->where(['promotion_id' => $pv->promotion_id, 'semestre_id' => $pv->semestre_id, 'enseignant_id' => $u->enseignant_id])->exists();
    }

    /** PV des semestres présidés par l'utilisateur, les plus récents d'abord. */
    public function pvPresides(Utilisateur $u): Collection
    {
        if (! $u->enseignant_id || ! $this->disponible()) {
            return collect();
        }

        return DB::table('presidents_jury as pj')
            ->join('pv_sessions as ps', fn ($j) => $j->on('ps.promotion_id', '=', 'pj.promotion_id')->on('ps.semestre_id', '=', 'pj.semestre_id'))
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->where('pj.enseignant_id', $u->enseignant_id)
            ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('n.code')->orderBy('s.code')->orderBy('ps.id')
            ->get(['ps.id', 'ps.libelle', 'ps.type_session', 'ps.statut', 'ps.verrouille', 'f.libelle as filiere', 'n.code as niveau',
                'a.libelle as annee', 's.libelle as sem_libelle',
                DB::raw('(SELECT COUNT(*) FROM resultats_semestre rs WHERE rs.pv_id = ps.id) AS nb_resultats')]);
    }
}
