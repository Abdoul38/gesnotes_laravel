<?php

namespace App\Services;

use App\Models\Utilisateur;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Règles communes aux procès-verbaux : qui y accède, qui saisit, et QUELS
 * étudiants composent un PV.
 *
 * L'ancienne application avait quatre définitions différentes des
 * étudiants d'un PV (affichage, enregistrement des notes, recalcul,
 * recalcul « rapide ») : le recalcul rapide prenait par exemple les inscrits
 * administratifs de la promotion, créant des résultats pour des étudiants
 * qui ne composaient pas. Une seule ici, celle de l'affichage :
 *  - normale : inscrits pédagogiquement à ce semestre et cette année, dans
 *    une promotion de la même filière (un étudiant peut composer en avance) ;
 *  - rattrapage : les mêmes, sauf ceux validés à la session normale ;
 *  - réclamation : les étudiants ayant des notes dans ce PV.
 */
class Pv
{
    public const STATUTS = ['brouillon' => 'Brouillon', 'finalise' => 'Finalisé', 'publie' => 'Publié'];

    /** Réclamations possibles pendant N heures après la publication du PV. */
    public const DELAI_RECLAMATION_HEURES = 72;

    /**
     * Date de publication d'un PV (alias « ps ») en SQL : celle enregistrée dans
     * pv_publications (jointure « pp ») ; à défaut — PV publiés par l'ancienne
     * application — la dernière modification, comme avant.
     */
    public const PUBLIE_LE_SQL = 'COALESCE(pp.publie_le, ps.updated_at)';

    public const SESSIONS = ['normale' => '1ère Session (Normale)', 'rattrapage' => '2ème Session (Rattrapage)', 'deliberation' => 'Délibération',
        'reclamation' => 'Réclamation (fondées)', 'reclamation_depot' => 'Réclamation (déposées)'];

    /** PV avec filière, niveau, année, semestre. */
    public function trouver(int $id): ?object
    {
        return DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->where('ps.id', $id)
            ->first(['ps.*', 'p.annee_id', 'p.filiere_id', 'p.niveau_id', 'f.libelle as filiere', 'f.code as filiere_code', 'f.departement', 'f.faculte',
                'f.faculte_id', 'f.departement_id', 'n.code as niveau', 'n.libelle as niveau_libelle', 'a.libelle as annee',
                's.code as semestre', 's.libelle as sem_libelle']);
    }

    /**
     * Ancien contrôle de périmètre de pv_detail.php : admin, secrétaire,
     * sc_centrale voient tout ; sc_entite et doyen leur faculté ; chef de
     * département son département ; enseignant les PV où il a une matière
     * affectée ; les autres rien.
     */
    public function accessible(Utilisateur $u, object $pv): bool
    {
        return match ($u->role) {
            'admin', 'secretaire', 'sc_centrale' => true,
            'sc_entite', 'doyen' => $u->faculteId() > 0 && (int) $pv->faculte_id === $u->faculteId(),
            'chef_departement' => $u->departementId() > 0 && (int) $pv->departement_id === $u->departementId(),
            'enseignant' => $u->enseignant_id && DB::table('affectations as af')->join('matieres as m', 'm.id', '=', 'af.matiere_id')
                ->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
                ->where('ue.promotion_id', $pv->promotion_id)->where('ue.semestre_id', $pv->semestre_id)
                ->where('af.enseignant_id', $u->enseignant_id)->exists(),
            default => false,
        };
    }

    /** Saisie des notes et actions sur le PV : sc_entite ou admin (ancien hasRole, hiérarchique), PV non verrouillé. */
    public function peutModifier(Utilisateur $u, object $pv): bool
    {
        return $this->gestionnaire($u) && ! $pv->verrouille;
    }

    public function gestionnaire(Utilisateur $u): bool
    {
        return $u->aRole('sc_entite') || $u->aRole('admin');
    }

    /** Requête des étudiants du PV (colonnes e.*). */
    public function etudiants(object $pv, string $recherche = ''): Builder
    {
        // Sous-requêtes EXISTS (pas de jointure) : aucun doublon, pas de DISTINCT
        $q = DB::table('etudiants as e')->select('e.*');
        if (in_array($pv->type_session, ['reclamation', 'reclamation_depot'], true)) {
            $q->whereExists(fn ($s) => $s->from('notes as n')->whereColumn('n.etudiant_id', 'e.id')->where('n.pv_id', $pv->id))
                ->whereExists(fn ($s) => $s->from('inscriptions_pedagogiques as ip')->join('inscriptions as i', 'i.id', '=', 'ip.inscription_id')
                    ->join('promotions as p', 'p.id', '=', 'i.promotion_id')->whereColumn('ip.etudiant_id', 'e.id')
                    ->where(['ip.semestre_id' => $pv->semestre_id, 'ip.annee_id' => $pv->annee_id, 'ip.statut' => 'actif',
                        'p.filiere_id' => $pv->filiere_id, 'p.niveau_id' => $pv->niveau_id]));
        } else {
            $q->whereExists(fn ($s) => $s->from('inscriptions_pedagogiques as ip')->join('inscriptions as i', 'i.id', '=', 'ip.inscription_id')
                ->join('promotions as p', 'p.id', '=', 'i.promotion_id')->whereColumn('ip.etudiant_id', 'e.id')
                ->where(['ip.semestre_id' => $pv->semestre_id, 'ip.annee_id' => $pv->annee_id, 'ip.statut' => 'actif', 'p.filiere_id' => $pv->filiere_id]));
            if ($pv->type_session === 'rattrapage' && $pv->pv_normale_id) {
                $q->whereNotExists(fn ($s) => $s->from('resultats_semestre as rs')->whereColumn('rs.etudiant_id', 'e.id')
                    ->where('rs.pv_id', $pv->pv_normale_id)->where('rs.valide', 1));
            }
        }
        if ($recherche !== '') {
            $q->where(fn ($w) => $w->where('e.nom', 'like', "%$recherche%")->orWhere('e.prenoms', 'like', "%$recherche%")->orWhere('e.numero', 'like', "%$recherche%"));
        }

        return $q->orderBy('e.nom')->orderBy('e.prenoms');
    }

    /** UE du PV avec leurs matières actives (ordre de la maquette). */
    public function ues(object $pv)
    {
        $ues = DB::table('unites_enseignement')->where(['promotion_id' => $pv->promotion_id, 'semestre_id' => $pv->semestre_id])->orderBy('ordre')->get();
        $matieres = DB::table('matieres')->whereIn('ue_id', $ues->pluck('id'))->where('actif', 1)->orderBy('ordre')->get()->groupBy('ue_id');

        return $ues->map(function ($ue) use ($matieres) {
            $ue->matieres = $matieres->get($ue->id, collect())->values();

            return $ue;
        })->filter(fn ($ue) => $ue->matieres->isNotEmpty())->values();
    }

    /** Choix d'options : [etudiant][ue] => [matières]. */
    public function choixOptions(object $pv): array
    {
        $choix = [];
        foreach (DB::table('choix_options_etudiants')->where(['promotion_id' => $pv->promotion_id, 'semestre_id' => $pv->semestre_id])->get() as $c) {
            $choix[$c->etudiant_id][$c->ue_id][] = (int) $c->matiere_id;
        }

        return $choix;
    }
}
