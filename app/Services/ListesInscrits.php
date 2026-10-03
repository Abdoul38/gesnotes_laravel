<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Listes des inscrits — une seule requête pour la page, le PDF et l'Excel
 * (pages/inscriptions/liste_inscrits*.php en avaient chacun leur copie).
 *
 * Différence voulue : le PDF et l'Excel ne filtraient pas par faculté —
 * un agent de faculté obtenait la liste de toute l'université en
 * modifiant l'adresse. Le périmètre s'applique maintenant aux trois.
 */
class ListesInscrits
{
    public const TYPES = ['tous', 'faculte', 'departement', 'filiere', 'niveau', 'semestre'];

    /** Critères lus dans la requête (mêmes paramètres que l'ancienne page). */
    public function criteres(Request $request): array
    {
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : 'tous';
        $filtre = trim((string) $request->query('filtre_id'));

        return [
            'annee_id' => $request->has('annee_id') ? (int) $request->query('annee_id') : (int) DB::table('annees_academiques')->where('active', 1)->value('id'),
            'type' => $type,
            'filtre' => $filtre,                 // faculté / département (texte)
            'filtre_id' => (int) $filtre,        // filière (identifiant)
            'filiere_id' => (int) $request->query('filiere_id'),
            'niveau_id' => (int) $request->query('niveau_id'),
            'semestre_id' => (int) $request->query('semestre_id'),
            'pedagogique' => $request->has('pedagogique') || $type === 'semestre',
        ];
    }

    /** Paramètres d'adresse pour reconstruire la même liste (page ↔ PDF ↔ Excel). */
    public static function parametres(array $c): array
    {
        return array_filter([
            'annee_id' => $c['annee_id'], 'type' => $c['type'], 'filtre_id' => $c['filtre'] !== '' ? $c['filtre'] : null,
            'filiere_id' => $c['filiere_id'] ?: null, 'niveau_id' => $c['niveau_id'] ?: null,
            'semestre_id' => $c['semestre_id'] ?: null, 'pedagogique' => $c['pedagogique'] && $c['type'] !== 'semestre' ? 1 : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Étudiants regroupés selon le type de liste.
     *
     * @return array<string, list<object>>
     */
    public function groupes(array $c, int $faculte): array
    {
        if (! $c['annee_id']) {
            return [];
        }
        $q = $c['pedagogique']
            ? DB::table('inscriptions_pedagogiques as ip')->join('inscriptions as i', 'i.id', '=', 'ip.inscription_id')
                ->join('semestres as s', 's.id', '=', 'ip.semestre_id')
                ->where('ip.annee_id', $c['annee_id'])->where('ip.statut', 'actif')
                ->when($c['type'] === 'semestre' && $c['semestre_id'], fn ($q) => $q->where('ip.semestre_id', $c['semestre_id']))
            : DB::table('inscriptions as i')->where('i.statut', 'inscrit');
        $q->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')
            ->when(! $c['pedagogique'], fn ($q) => $q->where('p.annee_id', $c['annee_id']))
            ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
            ->when($c['type'] === 'faculte' && $c['filtre'] !== '', fn ($q) => $q->where('f.faculte', $c['filtre']))
            ->when($c['type'] === 'departement' && $c['filtre'] !== '', fn ($q) => $q->where('f.departement', $c['filtre']))
            ->when($c['type'] === 'filiere' && $c['filtre_id'], fn ($q) => $q->where('f.id', $c['filtre_id']))
            ->when(in_array($c['type'], ['niveau', 'semestre'], true) && $c['filiere_id'], fn ($q) => $q->where('f.id', $c['filiere_id']))
            ->when(in_array($c['type'], ['niveau', 'semestre'], true) && $c['niveau_id'], fn ($q) => $q->where('n.id', $c['niveau_id']));

        $colonnes = ['e.id', 'e.numero as matricule', 'e.nom', 'e.prenoms', 'e.sexe', 'f.libelle as filiere', 'f.departement',
            DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'), 'n.code as niveau'];

        if ($c['type'] === 'semestre') {
            // Un groupe par semestre : un étudiant peut figurer dans plusieurs
            $lignes = $q->orderBy('s.code')->orderBy('f.libelle')->orderBy('n.code')->orderBy('e.nom')->orderBy('e.prenoms')
                ->get([...$colonnes, 's.libelle as semestre_label']);

            return $lignes->groupBy(fn ($e) => "{$e->semestre_label} — {$e->filiere} {$e->niveau}")->map->values()->all();
        }
        if ($c['pedagogique']) {
            $q->groupBy('e.id', 'f.id', 'n.id', 'e.numero', 'e.nom', 'e.prenoms', 'e.sexe', 'f.libelle', 'f.departement', 'fac.libelle', 'f.faculte', 'n.code');
            $colonnes[] = DB::raw("GROUP_CONCAT(DISTINCT s.libelle ORDER BY s.code SEPARATOR ', ') AS semestres");
        }
        $lignes = $q->orderBy('f.libelle')->orderBy('n.code')->orderBy('e.nom')->orderBy('e.prenoms')->get($colonnes);

        return $lignes->groupBy(fn ($e) => match ($c['type']) {
            'faculte' => $e->faculte ?: 'Sans faculté',
            'departement' => $e->departement ?: 'Sans département',
            'filiere' => $e->filiere,
            'niveau' => "{$e->niveau} — {$e->filiere}",
            default => 'Tous les étudiants inscrits',
        })->map->values()->all();
    }

    /** Critère affiché dans l'en-tête du PDF et de l'Excel. */
    public function titre(array $c): string
    {
        $filiere = fn (int $id) => DB::table('filieres')->where('id', $id)->value('libelle');
        $parties = array_filter([
            $c['filiere_id'] ? $filiere($c['filiere_id']) : null,
            $c['niveau_id'] ? DB::table('niveaux')->where('id', $c['niveau_id'])->value('code') : null,
            $c['type'] === 'semestre' && $c['semestre_id'] ? DB::table('semestres')->where('id', $c['semestre_id'])->value('libelle') : null,
        ]);

        return match ($c['type']) {
            'faculte' => $c['filtre'] !== '' ? 'Faculté : '.$c['filtre'] : 'Par faculté',
            'departement' => $c['filtre'] !== '' ? 'Département : '.$c['filtre'] : 'Par département',
            'filiere' => $c['filtre_id'] ? 'Filière : '.$filiere($c['filtre_id']) : 'Par filière',
            'niveau' => 'Par niveau'.($parties ? ' : '.implode(' — ', $parties) : ''),
            'semestre' => 'Par semestre'.($parties ? ' : '.implode(' — ', $parties) : ''),
            default => 'Tous les étudiants inscrits',
        };
    }

    /** Faculté désignée par les critères (pour le logo), sinon celle de l'utilisateur. */
    public function faculteDesCriteres(array $c, int $faculte): ?int
    {
        $id = match (true) {
            $c['type'] === 'faculte' && $c['filtre'] !== '' => DB::table('filieres')->where('faculte', $c['filtre'])->whereNotNull('faculte_id')->value('faculte_id'),
            $c['type'] === 'departement' && $c['filtre'] !== '' => DB::table('filieres')->where('departement', $c['filtre'])->whereNotNull('faculte_id')->value('faculte_id'),
            $c['type'] === 'filiere' && $c['filtre_id'] > 0 => DB::table('filieres')->where('id', $c['filtre_id'])->value('faculte_id'),
            in_array($c['type'], ['niveau', 'semestre'], true) && $c['filiere_id'] > 0 => DB::table('filieres')->where('id', $c['filiere_id'])->value('faculte_id'),
            default => null,
        };

        return (int) $id ?: ($faculte ?: null);
    }

    /** Colonnes à afficher selon le type de liste. */
    public static function colonnes(array $c): array
    {
        return [
            'filiere' => in_array($c['type'], ['tous', 'faculte', 'departement', 'semestre'], true),
            'niveau' => in_array($c['type'], ['tous', 'faculte', 'departement', 'filiere', 'semestre'], true),
            'semestres' => $c['pedagogique'] && $c['type'] !== 'semestre',
        ];
    }
}
