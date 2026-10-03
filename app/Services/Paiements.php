<?php

namespace App\Services;

use App\Autorisations\Perimetre;
use App\Models\Utilisateur;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Paiements des étudiants (table paiements) : valeurs autorisées, statut
 * calculé, périmètre de l'agent.
 */
class Paiements
{
    public const TYPES = ['inscription' => 'Inscription', 'scolarite' => 'Scolarité', 'rattrapage' => 'Rattrapage', 'diplome' => 'Diplôme',
        'attestation' => 'Attestation', 'certificat' => 'Certificat', 'releve_note' => 'Relevé de notes', 'depot_dossier' => 'Dépôt de dossier', 'autre' => 'Autre'];

    public const MODES = ['especes' => '💵 Espèces', 'virement' => '🏦 Virement', 'mobile_money' => '📱 Mobile Money', 'cheque' => '📝 Chèque'];

    public const STATUTS = ['en_attente' => 'En attente', 'partiel' => 'Partiel', 'complet' => 'Complet', 'rembourse' => 'Remboursé'];

    /** Plafond d'un montant (FCFA) : bloque les fautes de frappe à 11 chiffres. */
    public const MONTANT_MAX = 100000000;

    public function __construct(private readonly Perimetre $perimetre) {}

    /** Ancienne règle : rien payé → en attente ; tout payé → complet ; sinon partiel. */
    public static function statut(float $du, float $paye): string
    {
        return $paye <= 0 ? 'en_attente' : ($paye >= $du ? 'complet' : 'partiel');
    }

    /**
     * Paiements visibles : ceux des étudiants ayant une inscription dans la
     * faculté de l'agent (règle de l'ancienne liste).
     */
    public function requete(Utilisateur $u): Builder
    {
        $faculte = $this->perimetre->faculte($u);

        return DB::table('paiements as p')->join('etudiants as e', 'e.id', '=', 'p.etudiant_id')
            ->when($faculte, fn ($q) => $q->whereExists(fn ($s) => $s->from('inscriptions as i')->join('promotions as pr', 'pr.id', '=', 'i.promotion_id')
                ->join('filieres as f', 'f.id', '=', 'pr.filiere_id')->whereColumn('i.etudiant_id', 'e.id')->where('f.faculte_id', $faculte)));
    }

    /** Paiement du périmètre de l'agent, ou null. */
    public function trouver(Utilisateur $u, int $id): ?object
    {
        return $this->requete($u)->where('p.id', $id)->first(['p.*', 'e.nom', 'e.prenoms', 'e.numero']);
    }

    public function etudiantAutorise(Utilisateur $u, int $etudiantId): bool
    {
        $faculte = $this->perimetre->faculte($u);

        return DB::table('etudiants')->where('id', $etudiantId)->exists()
            && ($faculte === 0 || DB::table('inscriptions as i')->join('promotions as pr', 'pr.id', '=', 'i.promotion_id')
                ->join('filieres as f', 'f.id', '=', 'pr.filiere_id')->where('i.etudiant_id', $etudiantId)->where('f.faculte_id', $faculte)->exists());
    }
}
