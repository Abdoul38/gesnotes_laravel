<?php

namespace App\Services;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Statistiques du tableau de bord — portage de pages/accueil.php.
 * Mêmes requêtes que l'original ; seules les valeurs injectées dans le
 * SQL (faculté) passent désormais par des paramètres liés.
 */
class TableauDeBord
{
    /** @var array{0:string, 1:array} filtre faculté sur l'alias f (filieres) */
    private array $filtre = ['', []];

    public function pour(Utilisateur $utilisateur, int $anneeId): array
    {
        $this->filtre = $this->filtreFaculte($utilisateur->faculteId());
        $a = $anneeId;

        $totalInscrits = $this->compter("SELECT COUNT(DISTINCT i.etudiant_id) FROM inscriptions i JOIN promotions p ON p.id=i.promotion_id JOIN filieres f ON f.id=p.filiere_id WHERE p.annee_id=? AND i.statut='inscrit' {F}", [$a]);
        $nbH = $this->compter("SELECT COUNT(DISTINCT i.etudiant_id) FROM inscriptions i JOIN promotions p ON p.id=i.promotion_id JOIN etudiants e ON e.id=i.etudiant_id JOIN filieres f ON f.id=p.filiere_id WHERE p.annee_id=? AND i.statut='inscrit' AND e.sexe='M' {F}", [$a]);
        $nbF = $this->compter("SELECT COUNT(DISTINCT i.etudiant_id) FROM inscriptions i JOIN promotions p ON p.id=i.promotion_id JOIN etudiants e ON e.id=i.etudiant_id JOIN filieres f ON f.id=p.filiere_id WHERE p.annee_id=? AND i.statut='inscrit' AND e.sexe='F' {F}", [$a]);

        $totalPed = $this->compter("SELECT COUNT(DISTINCT ip.etudiant_id) FROM inscriptions_pedagogiques ip JOIN inscriptions i ON i.id=ip.inscription_id JOIN promotions p ON p.id=i.promotion_id JOIN filieres f ON f.id=p.filiere_id WHERE ip.annee_id=? AND ip.statut='actif' {F}", [$a]);
        $pedH = $this->compter("SELECT COUNT(DISTINCT ip.etudiant_id) FROM inscriptions_pedagogiques ip JOIN etudiants e ON e.id=ip.etudiant_id JOIN inscriptions i ON i.id=ip.inscription_id JOIN promotions p ON p.id=i.promotion_id JOIN filieres f ON f.id=p.filiere_id WHERE ip.annee_id=? AND ip.statut='actif' AND e.sexe='M' {F}", [$a]);
        $pedF = $this->compter("SELECT COUNT(DISTINCT ip.etudiant_id) FROM inscriptions_pedagogiques ip JOIN etudiants e ON e.id=ip.etudiant_id JOIN inscriptions i ON i.id=ip.inscription_id JOIN promotions p ON p.id=i.promotion_id JOIN filieres f ON f.id=p.filiere_id WHERE ip.annee_id=? AND ip.statut='actif' AND e.sexe='F' {F}", [$a]);

        return [
            'totalInscrits' => $totalInscrits, 'nbH' => $nbH, 'nbF' => $nbF,
            'totalPed' => $totalPed, 'pedH' => $pedH, 'pedF' => $pedF,
            'nonPed' => $totalInscrits - $totalPed,
            'parFaculte' => $this->lignes("SELECT f.faculte, COUNT(DISTINCT i.etudiant_id) AS nb FROM inscriptions i JOIN promotions p ON p.id=i.promotion_id JOIN filieres f ON f.id=p.filiere_id WHERE p.annee_id=? AND i.statut='inscrit' {F} GROUP BY f.faculte ORDER BY nb DESC", [$a]),
            'parFiliere' => $this->lignes("SELECT f.libelle AS filiere, COUNT(DISTINCT i.etudiant_id) AS nb FROM inscriptions i JOIN promotions p ON p.id=i.promotion_id JOIN filieres f ON f.id=p.filiere_id WHERE p.annee_id=? AND i.statut='inscrit' {F} GROUP BY f.id ORDER BY nb DESC LIMIT 15", [$a]),
            'parNiveau' => $this->lignes("SELECT n.code AS niveau, n.libelle, COUNT(DISTINCT i.etudiant_id) AS nb FROM inscriptions i JOIN promotions p ON p.id=i.promotion_id JOIN niveaux n ON n.id=p.niveau_id JOIN filieres f ON f.id=p.filiere_id WHERE p.annee_id=? AND i.statut='inscrit' {F} GROUP BY n.id ORDER BY n.code", [$a]),
            'pedParFaculte' => $this->lignes("SELECT f.faculte, COUNT(DISTINCT ip.etudiant_id) AS nb FROM inscriptions_pedagogiques ip JOIN inscriptions i ON i.id=ip.inscription_id JOIN promotions p ON p.id=i.promotion_id JOIN filieres f ON f.id=p.filiere_id WHERE ip.annee_id=? AND ip.statut='actif' AND f.faculte IS NOT NULL AND f.faculte!='' {F} GROUP BY f.faculte ORDER BY nb DESC", [$a]),
            'pedParDept' => $this->lignes("SELECT f.departement, COUNT(DISTINCT ip.etudiant_id) AS nb FROM inscriptions_pedagogiques ip JOIN inscriptions i ON i.id=ip.inscription_id JOIN promotions p ON p.id=i.promotion_id JOIN filieres f ON f.id=p.filiere_id WHERE ip.annee_id=? AND ip.statut='actif' AND f.departement IS NOT NULL AND f.departement!='' {F} GROUP BY f.departement ORDER BY nb DESC", [$a]),
            // L'original calculait une répartition par semestre et filière
            // ($pedSemestres) sans l'afficher, mais s'en servait pour décider
            // du message « aucune inscription pédagogique » : seule son
            // existence est reprise (mêmes jointures, mêmes filtres).
            'aPedParSemestre' => $this->compter("SELECT COUNT(*) FROM inscriptions_pedagogiques ip JOIN inscriptions i ON i.id=ip.inscription_id JOIN promotions p ON p.id=i.promotion_id JOIN filieres f ON f.id=p.filiere_id JOIN niveaux n ON n.id=p.niveau_id JOIN semestres s ON s.id=ip.semestre_id WHERE ip.annee_id=? AND ip.statut='actif' {F}", [$a]) > 0,
        ];
    }

    /**
     * Filtre « faculté de l'utilisateur » : par filieres.faculte_id si la
     * colonne existe, sinon par le libellé de la faculté (anciennes bases).
     */
    private function filtreFaculte(int $faculteId): array
    {
        if (! $faculteId) {
            return ['', []];
        }
        if (Schema::hasColumn('filieres', 'faculte_id')) {
            return ['AND f.faculte_id = ?', [$faculteId]];
        }
        $libelle = DB::table('facultes')->where('id', $faculteId)->value('libelle');

        return $libelle ? ['AND f.faculte = ?', [$libelle]] : ['', []];
    }

    private function sql(string $sql, array $liaisons): array
    {
        [$filtre, $valeurs] = $this->filtre;

        // Le filtre se place toujours avant GROUP BY / ORDER BY : ses
        // valeurs suivent donc les liaisons du WHERE.
        return [str_replace('{F}', $filtre, $sql), array_merge($liaisons, $valeurs)];
    }

    private function compter(string $sql, array $liaisons): int
    {
        [$sql, $liaisons] = $this->sql($sql, $liaisons);

        return (int) DB::scalar($sql, $liaisons);
    }

    private function lignes(string $sql, array $liaisons): array
    {
        [$sql, $liaisons] = $this->sql($sql, $liaisons);

        return array_map(fn ($l) => (array) $l, DB::select($sql, $liaisons));
    }
}
