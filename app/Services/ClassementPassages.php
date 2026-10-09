<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Classement des étudiants inscrits pour les indicateurs (statistiques,
 * rectorat, ministère) — règle de l'université : ADMIS = passage au niveau
 * suivant ou licence validée ; REDOUBLANTS = tous les autres inscrits, y
 * compris sans décision enregistrée.
 *
 * Un étudiant compte une fois par année (sa dernière inscription s'il en a
 * deux) et une seule décision par année : la dernière non vide (les bases
 * contenaient des doublons, que les anciens calculs additionnaient ; une
 * décision vide est une « licence_validee » refusée par une colonne trop
 * ancienne — voir gesnotes:reparer-passages).
 */
class ClassementPassages
{
    /**
     * @return Collection<int, object{etudiant_id: int, annee_id: int, annee: string, filiere_id: int, filiere: string, faculte: ?string,
     *     faculte_id: ?int, niveau_id: int, niveau: string, admis: bool}>
     */
    public function inscrits(?int $anneeId = null, int $filiereId = 0, int $faculteId = 0): Collection
    {
        $inscrits = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->where('i.statut', 'inscrit')
            ->when($anneeId, fn ($q) => $q->where('p.annee_id', $anneeId))
            ->when($filiereId, fn ($q) => $q->where('f.id', $filiereId))->when($faculteId, fn ($q) => $q->where('f.faculte_id', $faculteId))
            ->orderBy('i.id')
            ->get(['i.etudiant_id', 'p.annee_id', 'a.libelle as annee', 'f.id as filiere_id', 'f.libelle as filiere', 'f.faculte', 'f.faculte_id',
                'n.id as niveau_id', 'n.code as niveau'])
            ->keyBy(fn ($l) => $l->annee_id.'-'.$l->etudiant_id);
        $decisions = DB::table('passages_niveau')->whereIn('decision', ['passage', 'licence_validee', 'redoublement', ''])
            ->when($anneeId, fn ($q) => $q->where('annee_depart_id', $anneeId))->orderBy('id')
            ->get(['etudiant_id', 'annee_depart_id', 'decision'])
            ->mapWithKeys(fn ($d) => [$d->annee_depart_id.'-'.$d->etudiant_id => $d->decision === '' ? 'licence_validee' : $d->decision]);

        return $inscrits->map(function ($l, $cle) use ($decisions) {
            $l->admis = in_array($decisions[$cle] ?? null, ['passage', 'licence_validee'], true);

            return $l;
        })->values();
    }
}
