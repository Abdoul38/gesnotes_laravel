<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Sauvegarde SQL de la base de l'université — portage de pages/admin/sauvegarde.php.
 *
 * Corrections :
 *  - l'ancien dossier pages/backups/ était commun à toutes les universités
 *    et le téléchargement prenait un NOM de fichier : un administrateur
 *    pouvait récupérer la sauvegarde d'une autre université. Les fichiers
 *    sont désormais rangés par université, hors du dossier web
 *    (config gesnotes.dossier_sauvegardes), et désignés par leur numéro
 *    dans la table `sauvegardes` de l'université ;
 *  - le dump est écrit au fil de l'eau (l'ancien chargeait chaque table
 *    entière en mémoire) ;
 *  - les messages d'erreur SQL ne sont plus affichés tels quels.
 */
class SauvegardeController extends Controller
{
    public function __construct(private readonly UniversiteCourante $universite, private readonly Journal $journal) {}

    public function index(): View
    {
        $base = DB::connection()->getDatabaseName();
        $dossier = $this->dossier();
        $historique = DB::table('sauvegardes as s')->leftJoin('utilisateurs as u', 'u.id', '=', 's.cree_par')
            ->orderByDesc('s.created_at')->orderByDesc('s.id')->limit(20)
            ->get(['s.*', 'u.nom as createur_nom', 'u.prenoms as createur_prenom'])
            ->map(fn ($s) => tap($s, fn ($s) => $s->present = $this->nomValide($s->nom_fichier) && is_file($dossier.DIRECTORY_SEPARATOR.$s->nom_fichier)));

        return view('administration.sauvegarde', [
            'base' => $base,
            'nbTables' => count(DB::select('SHOW TABLES')),
            'tailleMo' => DB::table('information_schema.TABLES')->where('table_schema', $base)->value(DB::raw('ROUND(SUM(data_length+index_length)/1024/1024,2)')),
            'historique' => $historique,
            'nbFichiers' => count(glob($dossier.DIRECTORY_SEPARATOR.'*.sql') ?: []),
        ]);
    }

    public function sauvegarder(Request $request): BinaryFileResponse|RedirectResponse
    {
        @set_time_limit(0);
        $nom = 'gesnotes_'.strtolower($this->universite->get()->code).'_'.date('Y-m-d_H-i-s').'.sql';
        $chemin = $this->dossier().DIRECTORY_SEPARATOR.$nom;

        try {
            $nbObjets = $this->ecrireDump($chemin);
        } catch (Throwable $e) {
            report($e);
            @unlink($chemin);
            DB::table('sauvegardes')->insert(['nom_fichier' => $nom, 'taille' => 0, 'nb_tables' => 0, 'type' => 'manuelle', 'statut' => 'erreur',
                'message' => mb_substr($e->getMessage(), 0, 2000), 'cree_par' => $request->user()->id]);

            return redirect()->route('sauvegarde')->with('erreur', 'Erreur lors de la sauvegarde (détail dans le journal du serveur).');
        }

        $taille = filesize($chemin);
        $id = DB::table('sauvegardes')->insertGetId(['nom_fichier' => $nom, 'taille' => $taille, 'nb_tables' => $nbObjets, 'type' => 'manuelle', 'statut' => 'ok', 'cree_par' => $request->user()->id]);
        $this->journal->action('sauvegarder', 'systeme', "Sauvegarde créée : $nom (".number_format($taille / 1024, 1).' Ko)', $id);

        return $this->envoyer($chemin, $nom);
    }

    public function telecharger(Request $request): BinaryFileResponse|RedirectResponse
    {
        $s = DB::table('sauvegardes')->where('id', (int) $request->input('id'))->first();
        $chemin = $s && $this->nomValide($s->nom_fichier) ? $this->dossier().DIRECTORY_SEPARATOR.$s->nom_fichier : null;
        if (! $chemin || ! is_file($chemin)) {
            return redirect()->route('sauvegarde')->with('erreur', 'Fichier introuvable.');
        }
        $this->journal->action('exporter', 'systeme', "Téléchargement sauvegarde : {$s->nom_fichier}", (int) $s->id);

        return $this->envoyer($chemin, $s->nom_fichier);
    }

    public function supprimer(Request $request): RedirectResponse
    {
        $s = DB::table('sauvegardes')->where('id', (int) $request->input('id'))->first();
        if (! $s) {
            return redirect()->route('sauvegarde')->with('erreur', 'Sauvegarde introuvable.');
        }
        if ($this->nomValide($s->nom_fichier)) {
            @unlink($this->dossier().DIRECTORY_SEPARATOR.$s->nom_fichier);
        }
        DB::table('sauvegardes')->where('id', $s->id)->delete();
        $this->journal->action('supprimer', 'systeme', "Sauvegarde supprimée : {$s->nom_fichier}", (int) $s->id);

        return redirect()->route('sauvegarde')->with('succes', 'Sauvegarde supprimée.');
    }

    /** Dossier propre à l'université courante, créé au besoin. */
    private function dossier(): string
    {
        $racine = rtrim((string) (config('gesnotes.dossier_sauvegardes') ?: storage_path('app/private/sauvegardes')), '/\\');
        $dossier = $racine.DIRECTORY_SEPARATOR.preg_replace('/[^a-z0-9_-]/', '', strtolower($this->universite->get()->code));
        if (! is_dir($dossier)) {
            mkdir($dossier, 0750, true);
        }

        return $dossier;
    }

    private function nomValide(string $nom): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+\.sql$/', $nom) && ! str_contains($nom, '..');
    }

    private function envoyer(string $chemin, string $nom): BinaryFileResponse
    {
        return response()->download($chemin, $nom, ['Content-Type' => 'application/octet-stream']);
    }

    /** Dump SQL (structure + données des tables, structure des vues). Retourne le nombre d'objets. */
    private function ecrireDump(string $chemin): int
    {
        $db = DB::connection();
        $pdo = $db->getPdo();
        $q = fn (string $nom) => '`'.str_replace('`', '``', $nom).'`';
        $f = fopen($chemin, 'wb');
        if (! $f) {
            throw new \RuntimeException("Impossible d'écrire $chemin");
        }

        try {
            fwrite($f, "-- ============================================================\n"
                .'-- Sauvegarde GesNotes — '.$this->universite->get()->code.' — '.date('d/m/Y à H:i:s')."\n"
                .'-- Base de données : '.$db->getDatabaseName()."\n"
                ."-- ============================================================\n\n"
                ."SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\n\n");

            $tables = [];
            $vues = [];
            foreach ($db->select('SHOW FULL TABLES') as $ligne) {
                [$nom, $type] = array_values((array) $ligne);
                $type === 'VIEW' ? $vues[] = $nom : $tables[] = $nom;
            }

            foreach ($tables as $table) {
                $creation = (array) $db->selectOne('SHOW CREATE TABLE '.$q($table));
                fwrite($f, "-- ------------------------------------------------------------\n-- Table : $table\n"
                    ."-- ------------------------------------------------------------\n"
                    .'DROP TABLE IF EXISTS '.$q($table).";\n".$creation['Create Table'].";\n\n");

                $colonnes = null;
                $paquet = [];
                foreach ($db->table($table)->cursor() as $ligne) {
                    $ligne = (array) $ligne;
                    $colonnes ??= implode(',', array_map($q, array_keys($ligne)));
                    $paquet[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $ligne)).')';
                    if (count($paquet) === 500) {
                        fwrite($f, 'INSERT INTO '.$q($table)." ($colonnes) VALUES\n".implode(",\n", $paquet).";\n");
                        $paquet = [];
                    }
                }
                if ($paquet) {
                    fwrite($f, 'INSERT INTO '.$q($table)." ($colonnes) VALUES\n".implode(",\n", $paquet).";\n");
                }
                fwrite($f, "\n");
            }

            foreach ($vues as $vue) {
                try {
                    $creation = (array) $db->selectOne('SHOW CREATE VIEW '.$q($vue));
                    fwrite($f, "-- Vue : $vue\nDROP VIEW IF EXISTS ".$q($vue).";\n".$creation['Create View'].";\n\n");
                } catch (Throwable) {
                    fwrite($f, "-- Vue $vue ignorée (invalide)\n\n");
                }
            }

            fwrite($f, "SET FOREIGN_KEY_CHECKS=1;\n-- Fin de la sauvegarde — ".count($tables)." table(s)\n");
        } finally {
            fclose($f);
        }

        return count($tables) + count($vues);
    }
}
