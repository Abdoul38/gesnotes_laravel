<?php

namespace App\Services;

use App\Models\Utilisateur;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Réclamations de notes — périmètre et droits communs à la liste, au
 * traitement et à l'export (anciennes pages pages/reclamations/*).
 */
class Reclamations
{
    public const STATUTS = [
        'en_attente' => ['🕐', 'badge-amber', 'En attente'],
        'fondee' => ['✅', 'badge-green', 'Fondée'],
        'non_fondee' => ['❌', 'badge-red', 'Non fondée'],
        'rejetee' => ['🚫', 'badge-gray', 'Rejetée'],
    ];

    /**
     * Réclamations visibles : faculté et/ou département de l'utilisateur.
     * Différence voulue : un enseignant (sans faculté) ne voit plus que les
     * réclamations sur ses propres matières, plus celles de toute l'université.
     */
    public function requete(Utilisateur $u): Builder
    {
        return DB::table('reclamations as r')
            ->join('etudiants as e', 'e.id', '=', 'r.etudiant_id')
            ->join('pv_sessions as ps', 'ps.id', '=', 'r.pv_id')
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('matieres as m', 'm.id', '=', 'r.matiere_id')
            ->leftJoin('utilisateurs as u', 'u.id', '=', 'r.traitee_par')
            ->when($u->faculteId(), fn ($q, $f) => $q->where('f.faculte_id', $f))
            ->when($u->departementId(), fn ($q, $d) => $q->where('f.departement_id', $d))
            ->when($u->role === 'enseignant', fn ($q) => $q->whereExists(fn ($s) => $s->from('affectations as af')
                ->whereColumn('af.matiere_id', 'r.matiere_id')->where('af.enseignant_id', (int) $u->enseignant_id)));
    }

    /** Ancien contrôle de reclamation_traitement.php. */
    public function peutTraiter(Utilisateur $u, object $r): bool
    {
        return match ($u->role) {
            'admin', 'secretaire', 'sc_centrale' => true,
            'sc_entite', 'doyen' => $u->faculteId() > 0 && (int) $r->faculte_id === $u->faculteId(),
            'chef_departement' => $u->departementId() > 0 && (int) $r->departement_id === $u->departementId(),
            default => false,
        };
    }

    /** Ancien contrôle de reclamations_export.php. */
    public function peutExporter(Utilisateur $u): bool
    {
        return in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite'], true);
    }
}
