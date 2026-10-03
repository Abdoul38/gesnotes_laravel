<?php

namespace App\Http\Controllers\Rapports;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Inscriptions;
use App\Services\Journal;
use App\Services\Parametres;
use App\Universites\UniversiteCourante;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rapports et exports — portage de pages/admin/rapports.php et
 * rapports_export.php : liste des inscrits, effectifs par filière,
 * comparatif inter-années, rapport financier ; écran, impression, CSV.
 *
 * Différences voulues :
 *  - tous les rapports (et l'export) limités à la faculté de
 *    l'utilisateur : l'export, le comparatif, le rapport financier et les
 *    chiffres globaux portaient sur toute l'université ;
 *  - export CSV protégé contre les formules (cellule commençant par =, +,
 *    -, @) et journalisé ; en-tête au nom de l'université (UDH écrit en dur).
 */
class RapportsController extends Controller
{
    public const RAPPORTS = ['inscriptions' => '📋 Liste des inscrits', 'filieres' => '🎓 Effectifs par filière',
        'comparatif' => '📈 Comparatif inter-années', 'paiements' => '💰 Rapport financier'];

    public function __construct(private readonly Perimetre $perimetre) {}

    public function index(Request $request, UniversiteCourante $universite, Parametres $parametres): View
    {
        $p = $this->parametres($request);

        return view($request->boolean('print') ? 'rapports.rapports_impression' : 'rapports.rapports', $p + [
            'donnees' => $this->donnees($request->user(), $p),
            'globaux' => $this->globaux($request->user(), $p['anneeId']),
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'filieres' => DB::table('filieres as f')->where('actif', 1)->when($this->perimetre->faculte($request->user()), fn ($q, $fac) => $q->where('f.faculte_id', $fac))
                ->orderBy('faculte')->orderBy('libelle')->get(['id', 'libelle', 'faculte']),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(),
            'institution' => $parametres->valeur('institution_nom', $universite->get()->nom),
        ]);
    }

    public function export(Request $request, Journal $journal, UniversiteCourante $universite, Parametres $parametres): StreamedResponse
    {
        $p = $this->parametres($request);
        $donnees = $this->donnees($request->user(), $p);
        $journal->action('exporter', 'rapports', "Export du rapport {$p['rapport']} ({$p['anneeLib']})");
        [$colonnes, $lignes, $total] = match ($p['rapport']) {
            'inscriptions' => [['Matricule', 'Nom', 'Prénoms', 'Sexe', 'Filière', 'Faculté', 'Niveau', 'Statut', 'Montant payé (FCFA)', 'Mode paiement', 'Date inscription'],
                $donnees->map(fn ($r) => [$r->numero, $r->nom, $r->prenoms, $r->sexe, $r->filiere, $r->faculte, $r->niveau, $r->statut, $r->montant_paye, $r->mode_paiement,
                    $r->date_inscription ? date('d/m/Y', strtotime($r->date_inscription)) : '']), [8, $donnees->sum('montant_paye')]],
            'filieres' => [['Filière', 'Faculté', 'Niveau', 'Nb inscrits', 'Hommes', 'Femmes', 'Total perçu (FCFA)'],
                $donnees->map(fn ($r) => [$r->filiere, $r->faculte, $r->niveau, $r->nb_inscrits, $r->nb_hommes, $r->nb_femmes, $r->total_paye]), [6, $donnees->sum('total_paye')]],
            'comparatif' => [['Année', 'Total inscrits', 'Hommes', 'Femmes', 'Filières actives', 'Total perçu (FCFA)'],
                $donnees->map(fn ($r) => [$r->annee, $r->nb_inscrits, $r->nb_hommes, $r->nb_femmes, $r->nb_filieres, $r->total_paye]), [5, $donnees->sum('total_paye')]],
            default => [['Filière', 'Niveau', 'Nb inscrits', 'Total perçu (FCFA)', 'Paiements complets', 'Paiements partiels', 'Mode paiement'],
                $donnees->map(fn ($r) => [$r->filiere, $r->niveau, $r->nb_inscrits, $r->total_paye, $r->nb_complets, $r->nb_partiels, $r->mode_paiement]), [3, $donnees->sum('total_paye')]],
        };
        $nom = 'rapport_'.$p['rapport'].'_'.str_replace(['/', ' '], '_', $p['anneeLib']).'_'.date('Ymd_His').'.csv';
        $institution = mb_strtoupper($parametres->valeur('institution_nom', $universite->get()->nom));

        return response()->streamDownload(function () use ($colonnes, $lignes, $total, $p, $institution) {
            $sortie = fopen('php://output', 'w');
            $ecrire = fn (array $l) => fputcsv($sortie, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : $v, $l), ';', '"', '');
            echo "\xEF\xBB\xBF";
            $ecrire(["$institution — Service Central de la Scolarité"]);
            $ecrire(['Rapport : '.mb_strtoupper($p['rapport']).' | Année : '.$p['anneeLib'].' | Généré le '.date('d/m/Y à H:i')]);
            $ecrire([]);
            $ecrire($colonnes);
            foreach ($lignes as $l) {
                $ecrire(array_values($l));
            }
            if ($lignes->isNotEmpty()) {
                $ecrire([]);
                $fin = array_fill(0, count($colonnes), '');
                $fin[0] = 'TOTAL GÉNÉRAL';
                $fin[$total[0]] = number_format((float) $total[1], 0, ',', ' ').' FCFA';
                $ecrire($fin);
            }
            fclose($sortie);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function parametres(Request $request): array
    {
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeId = (int) $request->query('annee_id') ?: (int) ($annees->firstWhere('active', 1)->id ?? 0);

        return ['rapport' => isset(self::RAPPORTS[$request->query('rapport')]) ? $request->query('rapport') : 'inscriptions', 'anneeId' => $anneeId,
            'anneeLib' => (string) ($annees->firstWhere('id', $anneeId)->libelle ?? ''),
            'filiereId' => (int) $request->query('filiere_id'), 'niveauId' => (int) $request->query('niveau_id')];
    }

    private function donnees(Utilisateur $u, array $p): Collection
    {
        $fac = $this->perimetre->faculte($u);
        $parFac = fn ($q) => $q->when($fac, fn ($w) => $w->where('f.faculte_id', $fac));

        return match ($p['rapport']) {
            'inscriptions' => $parFac(DB::table('inscriptions as i')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
                ->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')->where('i.annee_id', $p['anneeId'])
                ->when($p['filiereId'], fn ($q, $v) => $q->where('e.filiere_id', $v))->when($p['niveauId'], fn ($q, $v) => $q->where('i.niveau_id', $v)))
                ->orderBy('f.libelle')->orderBy('n.code')->orderBy('e.nom')->orderBy('e.prenoms')
                ->get(['e.numero', 'e.nom', 'e.prenoms', 'e.sexe', 'f.libelle as filiere', 'f.faculte', 'n.code as niveau', 'i.statut', 'i.montant_paye', 'i.mode_paiement', 'i.date_inscription']),
            'filieres' => $parFac(DB::table('filieres as f')->leftJoin('etudiants as e', 'e.filiere_id', '=', 'f.id')
                ->leftJoin('inscriptions as i', fn ($j) => $j->on('i.etudiant_id', '=', 'e.id')->where('i.annee_id', $p['anneeId'])->where('i.statut', 'inscrit'))
                ->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')->where('f.actif', 1)->when($p['filiereId'], fn ($q, $v) => $q->where('f.id', $v)))
                ->groupBy('f.id', 'f.libelle', 'f.faculte', 'n.id', 'n.code')->havingRaw('COUNT(i.id) > 0')->orderBy('f.faculte')->orderBy('f.libelle')->orderBy('n.code')
                ->get(['f.libelle as filiere', 'f.faculte', 'n.code as niveau', DB::raw('COUNT(i.id) AS nb_inscrits'), DB::raw("SUM(e.sexe = 'M' AND i.id IS NOT NULL) AS nb_hommes"),
                    DB::raw("SUM(e.sexe = 'F' AND i.id IS NOT NULL) AS nb_femmes"), DB::raw('COALESCE(SUM(i.montant_paye), 0) AS total_paye')]),
            'comparatif' => DB::table('annees_academiques as a')
                ->leftJoin('inscriptions as i', fn ($j) => $j->on('i.annee_id', '=', 'a.id')->where('i.statut', 'inscrit')
                    ->when($fac, fn ($w) => $w->whereExists(fn ($s) => $s->from('etudiants as e2')->join('filieres as f2', 'f2.id', '=', 'e2.filiere_id')->whereColumn('e2.id', 'i.etudiant_id')->where('f2.faculte_id', $fac))))
                ->leftJoin('etudiants as e', 'e.id', '=', 'i.etudiant_id')->groupBy('a.id', 'a.libelle')->orderByDesc('a.libelle')
                ->get(['a.libelle as annee', DB::raw('COUNT(DISTINCT i.etudiant_id) AS nb_inscrits'), DB::raw("COUNT(DISTINCT CASE WHEN e.sexe = 'M' THEN i.etudiant_id END) AS nb_hommes"),
                    DB::raw("COUNT(DISTINCT CASE WHEN e.sexe = 'F' THEN i.etudiant_id END) AS nb_femmes"), DB::raw('COUNT(DISTINCT e.filiere_id) AS nb_filieres'),
                    DB::raw('COALESCE(SUM(i.montant_paye), 0) AS total_paye')]),
            default => $parFac(DB::table('inscriptions as i')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
                ->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')->where('i.annee_id', $p['anneeId'])->when($p['filiereId'], fn ($q, $v) => $q->where('e.filiere_id', $v)))
                ->groupBy('f.id', 'f.libelle', 'n.id', 'n.code', 'i.mode_paiement')->orderBy('f.libelle')->orderBy('n.code')
                ->get(['f.libelle as filiere', 'n.code as niveau', DB::raw('COUNT(i.id) AS nb_inscrits'), DB::raw('COALESCE(SUM(i.montant_paye), 0) AS total_paye'),
                    DB::raw('COUNT(CASE WHEN i.montant_paye >= '.Inscriptions::FRAIS.' THEN 1 END) AS nb_complets'),
                    DB::raw('COUNT(CASE WHEN i.montant_paye < '.Inscriptions::FRAIS.' AND i.montant_paye > 0 THEN 1 END) AS nb_partiels'), 'i.mode_paiement']),
        };
    }

    private function globaux(Utilisateur $u, int $anneeId): object
    {
        $fac = $this->perimetre->faculte($u);

        return DB::table('inscriptions as i')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->where('i.annee_id', $anneeId)->where('i.statut', 'inscrit')->when($fac, fn ($w) => $w->where('f.faculte_id', $fac))
            ->first([DB::raw('COUNT(DISTINCT i.etudiant_id) AS nb'), DB::raw("COUNT(DISTINCT CASE WHEN e.sexe = 'M' THEN i.etudiant_id END) AS h"),
                DB::raw("COUNT(DISTINCT CASE WHEN e.sexe = 'F' THEN i.etudiant_id END) AS f"), DB::raw('COUNT(DISTINCT e.filiere_id) AS nf'), DB::raw('COALESCE(SUM(i.montant_paye), 0) AS tp')]);
    }
}
