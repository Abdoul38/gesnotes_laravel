<?php

namespace App\Services;

use App\Universites\UniversiteCourante;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs d'une année pour le tableau de bord du rectorat — portage du
 * calcul de pages/rectorat.php (mêmes requêtes) :
 *  - inscrits, passages et redoublements (passages_niveau : une décision par
 *    étudiant et par an ; licence validée = réussite) ;
 *  - détail par faculté puis par filière et niveau ;
 *  - exécution du programme par les heures (prévues / faites, plafonnées).
 *
 * Mis en cache 5 minutes comme avant, mais dans le cache de l'application,
 * clé propre à l'université : l'ancien cache était une table relue avec
 * unserialize().
 */
class IndicateursRectorat
{
    public function __construct(private readonly UniversiteCourante $universite) {}

    public function pour(int $anneeId): array
    {
        return Cache::remember($this->cleCache($anneeId), 300, fn () => $this->calculer($anneeId));
    }

    public function oublier(int $anneeId): void
    {
        Cache::forget($this->cleCache($anneeId));
    }

    /**
     * Toute écriture dans l'université (notes, heures, PV, inscriptions…)
     * périme ses indicateurs : le numéro de version entre dans la clé du
     * cache. Appelé par le middleware InvaliderIndicateurs et les commandes
     * qui modifient les données. Avant : jusqu'à 5 minutes d'anciens chiffres
     * au rectorat et au ministère après une déclaration d'heures.
     */
    public function invalider(): void
    {
        Cache::increment($this->prefixe().':version');
    }

    private function cleCache(int $anneeId): string
    {
        return $this->prefixe().':v'.(int) Cache::get($this->prefixe().':version', 0).":$anneeId";
    }

    private function prefixe(): string
    {
        return 'rectorat:'.($this->universite->estDefinie() ? $this->universite->get()->code : 'defaut');
    }

    private function calculer(int $a): array
    {
        $totalInscrits = (int) DB::scalar("SELECT COUNT(DISTINCT i.etudiant_id) FROM inscriptions i JOIN promotions p ON p.id = i.promotion_id
            WHERE p.annee_id = ? AND i.statut = 'inscrit'", [$a]);
        $sexes = DB::selectOne("SELECT COUNT(DISTINCT CASE WHEN e.sexe='M' THEN i.etudiant_id END) AS hommes,
                COUNT(DISTINCT CASE WHEN e.sexe='F' THEN i.etudiant_id END) AS femmes
            FROM inscriptions i JOIN promotions p ON p.id = i.promotion_id JOIN etudiants e ON e.id = i.etudiant_id
            WHERE p.annee_id = ? AND i.statut = 'inscrit'", [$a]);
        $passages = 0;
        $redoublants = 0;
        foreach (DB::select('SELECT decision, COUNT(DISTINCT etudiant_id) AS nb FROM passages_niveau WHERE annee_depart_id = ? GROUP BY decision', [$a]) as $d) {
            if (in_array($d->decision, ['passage', 'licence_validee'], true)) {
                $passages += (int) $d->nb;
            } elseif ($d->decision === 'redoublement') {
                $redoublants = (int) $d->nb;
            }
        }
        $pvPublies = (int) DB::scalar("SELECT COUNT(*) FROM pv_sessions ps JOIN promotions p ON p.id = ps.promotion_id
            WHERE p.annee_id = ? AND ps.statut IN ('finalise','publie')", [$a]);

        $evolution = array_map(function ($r) {
            $total = $r->passages + $r->redoublants;

            return ['annee' => $r->annee, 'inscrits' => (int) $r->inscrits, 'passages' => (int) $r->passages, 'redoublants' => (int) $r->redoublants,
                'taux' => $total > 0 ? round($r->passages / $total * 100, 1) : null];
        }, DB::select("SELECT a.libelle AS annee, COUNT(DISTINCT i.etudiant_id) AS inscrits,
                COALESCE(SUM(CASE WHEN pn.decision IN ('passage','licence_validee') THEN 1 ELSE 0 END), 0) AS passages,
                COALESCE(SUM(CASE WHEN pn.decision = 'redoublement' THEN 1 ELSE 0 END), 0) AS redoublants
            FROM annees_academiques a
            LEFT JOIN promotions p ON p.annee_id = a.id
            LEFT JOIN inscriptions i ON i.promotion_id = p.id AND i.statut = 'inscrit'
            LEFT JOIN passages_niveau pn ON pn.etudiant_id = i.etudiant_id AND pn.annee_depart_id = a.id
            GROUP BY a.id, a.libelle ORDER BY a.libelle ASC LIMIT 6"));

        $programme = DB::selectOne('SELECT COALESCE(SUM(m.volume_horaire_prevu), 0) AS prevues,
                COALESCE(SUM(LEAST(m.heures_faites, m.volume_horaire_prevu)), 0) AS faites,
                COALESCE(SUM(m.volume_horaire_prevu IS NULL), 0) AS sans_volume,
                COALESCE(SUM(m.volume_horaire_prevu IS NOT NULL AND m.heures_faites >= m.volume_horaire_prevu), 0) AS completes,
                COALESCE(SUM(m.volume_horaire_prevu IS NOT NULL AND m.heures_faites > 0 AND m.heures_faites < m.volume_horaire_prevu), 0) AS en_cours,
                COALESCE(SUM(m.volume_horaire_prevu IS NOT NULL AND m.heures_faites = 0), 0) AS sans_toucher
            FROM matieres m JOIN unites_enseignement ue ON ue.id = m.ue_id JOIN promotions p ON p.id = ue.promotion_id
            WHERE p.annee_id = ? AND m.actif = 1', [$a]);

        $decisions = $passages + $redoublants;

        return [
            'inscrits' => $totalInscrits,
            'hommes' => (int) $sexes->hommes,
            'femmes' => (int) $sexes->femmes,
            'passages' => $passages,
            'redoublants' => $redoublants,
            'taux_passage' => $decisions > 0 ? round($passages / $decisions * 100, 1) : null, // null : aucune décision de passage encore
            'pv_publies' => $pvPublies,
            'evolution' => $evolution,
            'facultes' => $this->facultes($a),
            'heures_prevues' => (float) $programme->prevues,
            'heures_faites' => (float) $programme->faites,
            'taux_programme' => $programme->prevues > 0 ? round($programme->faites / $programme->prevues * 100, 1) : 0,
            'matieres' => ['sans_volume' => (int) $programme->sans_volume, 'completes' => (int) $programme->completes,
                'en_cours' => (int) $programme->en_cours, 'sans_toucher' => (int) $programme->sans_toucher],
        ];
    }

    /** Facultés (libellé de la filière) avec leurs filières par niveau. */
    private function facultes(int $a): array
    {
        $parFac = fn (string $sql) => collect(DB::select($sql, [$a]))->keyBy('faculte');
        $passages = $parFac("SELECT TRIM(f.faculte) AS faculte,
                SUM(CASE WHEN pn.decision IN ('passage','licence_validee') THEN 1 ELSE 0 END) AS passages,
                SUM(CASE WHEN pn.decision = 'redoublement' THEN 1 ELSE 0 END) AS redoublants
            FROM passages_niveau pn JOIN filieres f ON f.id = pn.filiere_id
            WHERE pn.annee_depart_id = ? AND f.faculte IS NOT NULL AND TRIM(f.faculte) != '' GROUP BY TRIM(f.faculte)");
        $genre = $parFac("SELECT TRIM(f.faculte) AS faculte, SUM(e.sexe = 'M') AS hommes, SUM(e.sexe = 'F') AS femmes
            FROM inscriptions i JOIN promotions p ON p.id = i.promotion_id JOIN filieres f ON f.id = p.filiere_id JOIN etudiants e ON e.id = i.etudiant_id
            WHERE p.annee_id = ? AND i.statut = 'inscrit' AND f.faculte IS NOT NULL AND TRIM(f.faculte) != '' GROUP BY TRIM(f.faculte)");
        $programme = $parFac("SELECT TRIM(f.faculte) AS faculte, COALESCE(SUM(m.volume_horaire_prevu), 0) AS prevues,
                COALESCE(SUM(LEAST(m.heures_faites, m.volume_horaire_prevu)), 0) AS faites
            FROM matieres m JOIN unites_enseignement ue ON ue.id = m.ue_id JOIN promotions p ON p.id = ue.promotion_id JOIN filieres f ON f.id = p.filiere_id
            WHERE p.annee_id = ? AND m.actif = 1 AND f.faculte IS NOT NULL AND TRIM(f.faculte) != '' GROUP BY TRIM(f.faculte)");
        $pv = $parFac("SELECT TRIM(f.faculte) AS faculte, COUNT(*) AS nb FROM pv_sessions ps JOIN promotions p ON p.id = ps.promotion_id
            JOIN filieres f ON f.id = p.filiere_id
            WHERE p.annee_id = ? AND ps.statut IN ('finalise','publie') AND f.faculte IS NOT NULL AND TRIM(f.faculte) != '' GROUP BY TRIM(f.faculte)");

        $facultes = [];
        foreach (DB::select("SELECT TRIM(f.faculte) AS faculte, COUNT(DISTINCT i.etudiant_id) AS inscrits,
                ROUND(AVG(CASE WHEN rs.valide = 1 THEN rs.moyenne_generale END), 2) AS moyenne
            FROM inscriptions i JOIN promotions p ON p.id = i.promotion_id JOIN filieres f ON f.id = p.filiere_id
            LEFT JOIN pv_sessions ps ON ps.promotion_id = p.id AND ps.type_session = 'normale'
            LEFT JOIN resultats_semestre rs ON rs.pv_id = ps.id AND rs.etudiant_id = i.etudiant_id AND rs.valide = 1
            WHERE p.annee_id = ? AND i.statut = 'inscrit' AND f.faculte IS NOT NULL AND TRIM(f.faculte) != ''
            GROUP BY TRIM(f.faculte) ORDER BY TRIM(f.faculte)", [$a]) as $r) {
            $p = $passages[$r->faculte] ?? null;
            $pr = $programme[$r->faculte] ?? null;
            $facultes[$this->cle($r->faculte)] = $this->taux([
                'faculte' => $r->faculte, 'inscrits' => (int) $r->inscrits, 'moyenne' => $r->moyenne,
                'passages' => (int) ($p->passages ?? 0), 'redoublants' => (int) ($p->redoublants ?? 0),
                'hommes' => (int) ($genre[$r->faculte]->hommes ?? 0), 'femmes' => (int) ($genre[$r->faculte]->femmes ?? 0),
                'heures_prevues' => (float) ($pr->prevues ?? 0), 'heures_faites' => (float) ($pr->faites ?? 0),
                'pv_publies' => (int) ($pv[$r->faculte]->nb ?? 0), 'filieres' => [],
            ]);
        }

        // Filières par niveau, rattachées à leur faculté (libellé comparé sans casse ni espace insécable)
        $passagesFil = collect(DB::select("SELECT pn.filiere_id, COALESCE(n.code, '?') AS niveau,
                SUM(CASE WHEN pn.decision IN ('passage','licence_validee') THEN 1 ELSE 0 END) AS passages,
                SUM(CASE WHEN pn.decision = 'redoublement' THEN 1 ELSE 0 END) AS redoublants
            FROM passages_niveau pn LEFT JOIN niveaux n ON n.id = pn.niveau_actuel_id
            WHERE pn.annee_depart_id = ? GROUP BY pn.filiere_id, COALESCE(n.code, '?')", [$a]))->keyBy(fn ($r) => $r->filiere_id.'-'.$r->niveau);
        $programmeFil = collect(DB::select('SELECT p.filiere_id, n.code AS niveau, COALESCE(SUM(m.volume_horaire_prevu), 0) AS prevues,
                COALESCE(SUM(LEAST(m.heures_faites, m.volume_horaire_prevu)), 0) AS faites
            FROM matieres m JOIN unites_enseignement ue ON ue.id = m.ue_id JOIN promotions p ON p.id = ue.promotion_id JOIN niveaux n ON n.id = p.niveau_id
            WHERE p.annee_id = ? AND m.actif = 1 GROUP BY p.filiere_id, n.id, n.code', [$a]))->keyBy(fn ($r) => $r->filiere_id.'-'.$r->niveau);
        foreach (DB::select("SELECT fi.id AS filiere_id, fi.libelle AS filiere, TRIM(fi.faculte) AS faculte, n.code AS niveau,
                COUNT(DISTINCT i.etudiant_id) AS inscrits, COUNT(DISTINCT CASE WHEN e.sexe = 'F' THEN i.etudiant_id END) AS femmes,
                ROUND(AVG(CASE WHEN rs.valide = 1 THEN rs.moyenne_generale END), 2) AS moyenne
            FROM inscriptions i JOIN promotions p ON p.id = i.promotion_id JOIN filieres fi ON fi.id = p.filiere_id
            JOIN niveaux n ON n.id = p.niveau_id JOIN etudiants e ON e.id = i.etudiant_id
            LEFT JOIN pv_sessions ps ON ps.promotion_id = p.id AND ps.type_session = 'normale'
            LEFT JOIN resultats_semestre rs ON rs.pv_id = ps.id AND rs.etudiant_id = i.etudiant_id
            WHERE p.annee_id = ? AND i.statut = 'inscrit'
            GROUP BY fi.id, fi.libelle, fi.faculte, n.id, n.code ORDER BY TRIM(fi.faculte), fi.libelle, n.code", [$a]) as $r) {
            $cle = $this->cle((string) $r->faculte);
            if (! isset($facultes[$cle])) {
                continue;
            }
            $p = $passagesFil[$r->filiere_id.'-'.$r->niveau] ?? null;
            $pr = $programmeFil[$r->filiere_id.'-'.$r->niveau] ?? null;
            $facultes[$cle]['filieres'][] = $this->taux([
                'filiere_id' => (int) $r->filiere_id, 'filiere' => $r->filiere, 'niveau' => $r->niveau, 'inscrits' => (int) $r->inscrits,
                'femmes' => (int) $r->femmes, 'moyenne' => $r->moyenne,
                'passages' => (int) ($p->passages ?? 0), 'redoublants' => (int) ($p->redoublants ?? 0),
                'heures_prevues' => (float) ($pr->prevues ?? 0), 'heures_faites' => (float) ($pr->faites ?? 0),
            ]);
        }

        return array_values($facultes);
    }

    private function taux(array $l): array
    {
        $decisions = $l['passages'] + $l['redoublants'];

        return $l + [
            'taux' => $decisions > 0 ? round($l['passages'] / $decisions * 100, 1) : null,
            'taux_programme' => $l['heures_prevues'] > 0 ? round($l['heures_faites'] / $l['heures_prevues'] * 100, 1) : 0,
        ];
    }

    private function cle(string $faculte): string
    {
        return mb_strtolower(trim(str_replace("\xC2\xA0", ' ', $faculte)));
    }
}
