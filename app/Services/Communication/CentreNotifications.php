<?php

namespace App\Services\Communication;

use App\Services\SuiviEtudiant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liste des notifications de l'application (la cloche) : notifications du
 * compte (résultats, absences, examens, paiements, messages…) et annonces
 * en cours qui le concernent, des plus récentes aux plus anciennes. Sont
 * « non lues » celles arrivées depuis la dernière ouverture de la liste.
 */
class CentreNotifications
{
    private const LIMITE = 50;

    public function __construct(private readonly SuiviEtudiant $suivi) {}

    /** @return array{elements: Collection, non_lues: int} */
    public function etudiant(object $etudiant): array
    {
        return $this->liste('etudiant', (int) $etudiant->id, $this->annonces([$etudiant], false));
    }

    /** @param  iterable<object>  $enfants */
    public function parent(int $parentId, iterable $enfants): array
    {
        return $this->liste('parent', $parentId, $this->annonces($enfants, true));
    }

    public function marquerLues(string $type, int $compteId): void
    {
        if (Schema::hasTable('notifications_lectures')) {
            DB::table('notifications_lectures')->upsert([['type_compte' => $type, 'compte_id' => $compteId, 'lu_le' => now()]], ['type_compte', 'compte_id'], ['lu_le']);
        }
    }

    private function liste(string $type, int $compteId, Collection $annonces): array
    {
        if (! Schema::hasTable('notifications_mobile')) {
            return ['elements' => collect(), 'non_lues' => 0];
        }
        $propres = DB::table('notifications_mobile')->where(['type_compte' => $type, 'compte_id' => $compteId])
            ->orderByDesc('cree_le')->orderByDesc('id')->limit(self::LIMITE)->get(['id', 'categorie', 'titre', 'texte', 'cree_le'])
            ->map(fn ($n) => ['id' => 'n'.$n->id, 'categorie' => $n->categorie, 'titre' => $n->titre, 'texte' => $n->texte, 'date' => (string) $n->cree_le]);
        $luLe = DB::table('notifications_lectures')->where(['type_compte' => $type, 'compte_id' => $compteId])->value('lu_le');
        $elements = $propres->concat($annonces)->sortByDesc('date')->take(self::LIMITE)->values()
            ->map(fn ($e) => $e + ['lue' => $luLe !== null && $e['date'] <= $luLe]);

        return ['elements' => $elements, 'non_lues' => $elements->where('lue', false)->count()];
    }

    /** Annonces en cours pour ces étudiants (pour un parent : sauf celles réservées aux étudiants). */
    private function annonces(iterable $etudiants, bool $pourParent): Collection
    {
        $annonces = collect();
        foreach ($etudiants as $et) {
            $annonces = $annonces->concat($this->suivi->annonces($et)->when($pourParent, fn ($q) => $q->where('cible', '!=', 'etudiants'))
                ->orderByDesc('created_at')->limit(30)->get(['id', 'titre', 'contenu', 'type', 'created_at']));
        }

        return $annonces->unique('id')->map(fn ($a) => ['id' => 'a'.$a->id, 'categorie' => 'annonce', 'titre' => $a->titre,
            'texte' => mb_strimwidth(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $a->contenu))), 0, 300, '…'), 'date' => (string) $a->created_at])->values();
    }
}
