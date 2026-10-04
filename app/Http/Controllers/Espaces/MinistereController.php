<?php

namespace App\Http\Controllers\Espaces;

use App\Http\Controllers\Controller;
use App\Services\IndicateursNationaux;
use App\Support\Classeur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tableau de bord national du Ministère — portage de pages/ministere.php :
 * vue nationale, évolution, comparaison (dont filières équivalentes),
 * détail université → faculté → filière, PV (liste et détail en lecture
 * seule), journal des consultations (administrateurs).
 *
 * Différences voulues :
 *  - aucune écriture dans les bases des universités (voir IndicateursNationaux) ;
 *  - détail limité aux PV finalisés ou publiés ;
 *  - exports produits et journalisés par le serveur (Excel, rapport et PV
 *    imprimables) : le navigateur pouvait écrire ce qu'il voulait dans le
 *    journal, sans jeton ;
 *  - total national des passages : les licences validées étaient comptées
 *    deux fois dans le taux national.
 */
class MinistereController extends Controller
{
    public const ONGLETS = ['national' => '🌍 Vue nationale', 'evolution' => '📈 Évolution', 'comparaison' => '⚖️ Comparaison',
        'detail' => '🏛️ Détail complet', 'pv' => '📄 PV', 'journal' => '🕵️ Journal'];

    public const ACTIONS = [
        'consultation_dashboard' => ['🏠 Dashboard', 'badge-blue'], 'consultation_pv' => ['📄 Consultation PV', 'badge-amber'],
        'export_pdf_admis' => ['📥 Export admis', 'badge-green'], 'export_pdf_global' => ['📥 Export PV complet', 'badge-green'],
        'export_pdf_rapport_global' => ['📥 Export rapport national', 'badge-green'], 'export_excel' => ['📊 Export Excel', 'badge-green'],
    ];

    public function __construct(private readonly IndicateursNationaux $national) {}

    public function index(Request $request): View|RedirectResponse
    {
        $compte = Auth::guard('mesr')->user();
        $onglet = isset(self::ONGLETS[$request->query('onglet')]) ? (string) $request->query('onglet') : 'national';
        if ($onglet === 'journal' && ! $compte->estAdmin()) {
            $onglet = 'national';
        }
        [$annees, $active] = $this->national->annees();
        $annee = in_array($request->query('annee'), $annees, true) ? (string) $request->query('annee') : ($active ?? $annees[0] ?? '');
        $this->tracer($request, 'consultation_dashboard', $onglet, "année: $annee");

        $donnees = ['onglet' => $onglet, 'annees' => $annees, 'annee' => $annee, 'universites' => $this->national->universites()];
        if ($onglet === 'pv') {
            return view('espaces.ministere', $donnees + $this->ongletPv($request, $annees, $annee));
        }
        if ($onglet === 'journal') {
            return view('espaces.ministere', $donnees + ['journal' => DB::table('mesr_logs_consultation')->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get()]);
        }
        $stats = $this->national->parUniversite($annee);

        return view('espaces.ministere', $donnees + [
            'stats' => $stats,
            'totaux' => $this->national->totaux($stats, $annee),
            'comparaison' => $onglet === 'comparaison' ? $this->national->comparaisonFilieres($stats) : [],
        ]);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        [$annees, $active] = $this->national->annees();
        $annee = in_array($request->query('annee'), $annees, true) ? (string) $request->query('annee') : ($active ?? $annees[0] ?? '');
        $stats = $this->national->parUniversite($annee);
        $t = $this->national->totaux($stats, $annee);
        $this->tracer($request, 'export_excel', $annee);
        $pct = fn ($part, $tout) => $tout > 0 ? round($part / $tout * 100, 1) : 0;
        $entete = fn (array $titres) => ['height' => 30, 'cells' => array_map(fn ($x) => ['v' => $x, 'style' => 5], $titres)];
        $cel = fn (array $v) => ['cells' => array_map(fn ($x) => ['v' => $x], $v)];
        $ok = array_filter($stats, fn ($s) => ! $s['erreur']);

        $classeur = new Classeur;
        $classeur->feuille('National', [
            ['height' => 26, 'cells' => [['v' => "Tableau de bord national — Année académique $annee", 'style' => 1]]],
            ['cells' => [['v' => 'Généré le '.now()->format('d/m/Y à H:i')]]],
            $entete(['Université', 'Inscrits', 'Hommes', 'Femmes', '% Femmes', 'Passages', 'Redoublants', 'Taux de passage', 'Heures prévues', 'Heures faites',
                'Exécution programme', 'Facultés', 'Filières', 'PV publiés', 'PV en attente']),
            ...array_map(fn ($s) => $s['erreur'] ? $cel([$s['uni']->nom, 'Connexion impossible'])
                : $cel([$s['uni']->nom, $s['inscrits'], $s['hommes'], $s['femmes'], $pct($s['femmes'], $s['inscrits']), $s['passages'], $s['redoublants'],
                    $s['taux_passage'], $s['heures_prevues'], $s['heures_faites'], $s['taux_programme'], $s['nb_facultes'], $s['nb_filieres'], $s['pv_publies'], $s['pv_attente']]),
                array_values($stats)),
            $cel(['TOTAL NATIONAL', $t['inscrits'], $t['hommes'], $t['femmes'], $pct($t['femmes'], $t['inscrits']), $t['passages'], $t['redoublants'],
                $t['taux_passage'], $t['heures_prevues'], $t['heures_faites'], $t['taux_programme'], '', '', $t['pv_publies'], '']),
        ], [44, 10, 10, 10, 10, 10, 12, 14, 14, 14, 16, 10, 10, 10, 12], 3, ['A1:O1', 'A2:O2']);

        $facultes = [];
        $filieres = [];
        foreach ($ok as $s) {
            foreach ($s['facultes'] as $f) {
                $facultes[] = $cel([$s['uni']->sigle, $f['faculte'], $f['inscrits'], $f['femmes'], $f['passages'], $f['redoublants'], $f['taux'], $f['taux_programme'], $f['pv_publies']]);
                foreach ($f['filieres'] as $l) {
                    $filieres[] = $cel([$s['uni']->sigle, $f['faculte'], $l['filiere'], $l['niveau'], $l['inscrits'], $l['femmes'], $l['passages'], $l['redoublants'], $l['taux'], $l['taux_programme']]);
                }
            }
        }
        $classeur->feuille('Facultés', [$entete(['Université', 'Faculté', 'Inscrits', 'Femmes', 'Passages', 'Redoublants', 'Taux de passage', 'Exécution programme', 'PV publiés']), ...$facultes],
            [12, 44, 10, 10, 10, 12, 14, 16, 10], 1);
        $classeur->feuille('Filières', [$entete(['Université', 'Faculté', 'Filière', 'Niveau', 'Inscrits', 'Femmes', 'Passages', 'Redoublants', 'Taux de passage', 'Exécution programme']), ...$filieres],
            [12, 36, 40, 10, 10, 10, 10, 12, 14, 16], 1);
        $evolution = [];
        foreach ($ok as $s) {
            foreach ($s['evolution'] as $e) {
                $evolution[] = $cel([$s['uni']->sigle, $e['annee'], $e['inscrits'], $e['passages'], $e['redoublants'], $e['taux']]);
            }
        }
        $classeur->feuille('Évolution', [$entete(['Université', 'Année académique', 'Inscrits', 'Passages', 'Redoublants', 'Taux de passage']), ...$evolution], [12, 20, 12, 12, 14, 16], 1);

        return response()->download($classeur->enregistrer(), 'tableau_national_'.str_replace('/', '-', $annee).'_'.date('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** Rapport national imprimable (remplace l'export html2pdf). */
    public function rapport(Request $request): View
    {
        [$annees, $active] = $this->national->annees();
        $annee = in_array($request->query('annee'), $annees, true) ? (string) $request->query('annee') : ($active ?? $annees[0] ?? '');
        $stats = $this->national->parUniversite($annee);
        $this->tracer($request, 'export_pdf_rapport_global', $annee);

        return view('espaces.ministere_rapport', ['annee' => $annee, 'stats' => $stats, 'totaux' => $this->national->totaux($stats, $annee)]);
    }

    /** PV complet ou liste des admis, imprimables. */
    public function impressionPv(Request $request, string $code, int $id): View|RedirectResponse
    {
        $u = $this->national->universite($code);
        $detail = $u ? $this->national->detailPv($u, $id) : null;
        if (! $detail) {
            return redirect()->route('ministere.accueil', ['onglet' => 'pv'])->with('erreur', 'PV introuvable.');
        }
        $type = $request->query('type') === 'admis' ? 'admis' : 'global';
        $this->tracer($request, $type === 'admis' ? 'export_pdf_admis' : 'export_pdf_global', "{$u->sigle} / PV#$id", $this->description($detail['pv']));

        return view('portails.rectorat_pv_impression', ['pv' => $detail['pv'], 'type' => $type, 'resultats' => $detail['resultats'],
            'admis' => $detail['resultats']->where('valide', 1)->count(), 'entete' => $u->nom]);
    }

    private function ongletPv(Request $request, array $annees, string $annee): array
    {
        $filtres = [
            'pv_annee' => in_array($request->query('pv_annee'), $annees, true) ? (string) $request->query('pv_annee') : $annee,
            'pv_uni' => $this->national->universite((string) $request->query('pv_uni')) ? (string) $request->query('pv_uni') : '',
            'pv_statut' => in_array($request->query('pv_statut'), ['finalise', 'publie'], true) ? (string) $request->query('pv_statut') : '',
            'pv_session' => isset(IndicateursNationaux::SESSIONS[$request->query('pv_session')]) ? (string) $request->query('pv_session') : '',
        ];
        $detail = null;
        $u = $this->national->universite((string) $request->query('pv_code'));
        if ($u && $request->integer('pv_id') > 0) {
            $detail = $this->national->detailPv($u, $request->integer('pv_id'));
            if ($detail) {
                $detail['uni'] = $u;
                $this->tracer($request, 'consultation_pv', "{$u->sigle} / PV#{$detail['pv']->id}", $this->description($detail['pv']));
            }
        }

        return ['filtres' => $filtres, 'detail' => $detail,
            'pvs' => $detail ? [] : $this->national->pv($filtres['pv_annee'], $filtres['pv_uni'], $filtres['pv_statut'], $filtres['pv_session'])];
    }

    private function description(object $pv): string
    {
        return "{$pv->filiere} — {$pv->niveau} — {$pv->sem_libelle} — ".(IndicateursNationaux::SESSIONS[$pv->type_session] ?? $pv->type_session);
    }

    /** Journal des consultations du ministère (table mesr_logs_consultation). */
    private function tracer(Request $request, string $action, ?string $cible = null, ?string $details = null): void
    {
        $c = Auth::guard('mesr')->user();
        DB::table('mesr_logs_consultation')->insert(['login' => $c->login, 'nom_complet' => $c->nom_complet, 'action' => $action,
            'cible' => $cible !== null ? mb_substr($cible, 0, 255) : null, 'details' => $details !== null ? mb_substr($details, 0, 255) : null,
            'ip' => $request->ip(), 'created_at' => now()]);
    }
}
