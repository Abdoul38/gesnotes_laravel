<?php

namespace App\Http\Controllers\Espaces;

use App\Http\Controllers\Controller;
use App\Services\IndicateursNationaux;
use App\Universites\Universite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Portail de l'Agence Nationale des Bourses — portage de pages/anab.php :
 * étudiants ayant validé S1 à S4 d'une licence ou d'un DUT (meilleure
 * moyenne validée par semestre), moyennes sur 4 et 6 semestres, mention
 * (seuils de l'université consultée, plus 16 / 14 / 12 fixes),
 * éligibilité à partir de 12/20 ; dossier d'un étudiant.
 *
 * Différences voulues :
 *  - seuls les résultats des PV finalisés ou publiés comptent (ceux d'un PV
 *    encore en brouillon étaient montrés à l'agence) ;
 *  - chaque consultation d'un dossier est inscrite au journal d'activité
 *    de l'université concernée (aucune trace avant) ;
 *  - filtres passés en paramètres liés (la filière était insérée dans la requête) ;
 *  - niveau LP pris en compte : S5 et S6 des étudiants de l'IUT (DUT puis
 *    licence professionnelle) n'apparaissaient pas, ni leur moyenne sur 6
 *    semestres.
 */
class AnabController extends Controller
{
    /** Premier cycle, comme App\Services\Parcours : Licence, DUT et licence professionnelle (S5, S6 des étudiants de l'IUT). */
    public const NIVEAUX = ['L1', 'L2', 'L3', 'DUT1', 'DUT2', 'LP'];

    private const SEMESTRES = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'];

    /** Seuils par défaut (ceux de l'ancienne page, fixes pour toutes les universités). */
    public const SEUILS_DEFAUT = ['tres_bien' => 16.0, 'bien' => 14.0, 'assez_bien' => 12.0];

    /**
     * Mention selon les seuils de l'université consultée (paramètres
     * mention_tres_bien / mention_bien / mention_assez_bien, ceux de ses
     * documents) : l'ANAB voit la même mention que sur l'attestation.
     */
    public static function mention(?float $m, array $seuils = self::SEUILS_DEFAUT): string
    {
        return match (true) {
            $m === null => '—', $m >= $seuils['tres_bien'] => 'Très Bien', $m >= $seuils['bien'] => 'Bien',
            $m >= $seuils['assez_bien'] => 'Assez Bien', default => 'Passable',
        };
    }

    /** Seuils de mention de l'université courante (lus en base : le cache de Parametres vaut pour une seule université). */
    private function seuils(): array
    {
        $p = DB::table('parametres')->whereIn('cle', ['mention_tres_bien', 'mention_bien', 'mention_assez_bien'])->pluck('valeur', 'cle');
        $lire = fn (string $cle, float $defaut) => is_numeric($p[$cle] ?? null) ? (float) $p[$cle] : $defaut;

        return ['tres_bien' => $lire('mention_tres_bien', 16.0), 'bien' => $lire('mention_bien', 14.0), 'assez_bien' => $lire('mention_assez_bien', 12.0)];
    }

    public function __construct(private readonly IndicateursNationaux $national) {}

    public function index(Request $request): View
    {
        $u = $this->national->universite((string) $request->query('univ'));
        $filtres = [
            'univ' => $u?->code ?? '', 'filiere_id' => $request->integer('filiere_id'), 'annee_id' => $request->integer('annee_id'),
            'niveau_code' => in_array($request->query('niveau_code'), self::NIVEAUX, true) ? (string) $request->query('niveau_code') : '',
            'q' => mb_substr(trim((string) $request->query('q')), 0, 100),
        ];
        $donnees = ['universites' => $this->national->universites(), 'uni' => $u, 'filtres' => $filtres, 'eligibles' => [], 'dossier' => null, 'erreur' => false, 'seuils' => self::SEUILS_DEFAUT,
            'filieres' => collect(), 'annees' => collect(), 'niveaux' => collect()];
        if (! $u) {
            return view('espaces.anab', $donnees);
        }

        $etudiantId = $request->integer('etudiant');
        $lu = $this->national->dans($u, function () use ($u, $filtres, $etudiantId) {
            $listes = [
                'filieres' => DB::table('filieres')->where('actif', 1)->orderBy('libelle')->get(['id', 'libelle']),
                'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(['id', 'libelle']),
                'niveaux' => DB::table('niveaux')->whereIn('code', self::NIVEAUX)->orderBy('code')->get(['id', 'code', 'libelle']),
            ];

            return $listes + ['seuils' => $this->seuils()] + ($etudiantId > 0 ? ['dossier' => $this->dossier($u, $etudiantId)] : ['eligibles' => $this->eligibles($filtres)]);
        });

        return view('espaces.anab', $lu === null ? ['erreur' => true] + $donnees : $lu + $donnees);
    }

    /** Requête commune : résultats validés de S1 à S6 en licence / DUT, PV finalisés ou publiés. */
    private function resultatsValides()
    {
        return DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
            ->join('semestres as s', 's.id', '=', 'ps.semestre_id')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->join('niveaux as nv', 'nv.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->where('rs.valide', 1)->whereIn('ps.statut', ['finalise', 'publie'])
            ->whereIn('s.code', self::SEMESTRES)->whereIn('nv.code', self::NIVEAUX);
    }

    private function eligibles(array $f): array
    {
        $parEtudiant = [];
        foreach ($this->resultatsValides()->groupBy('rs.etudiant_id', 's.code', 'nv.code')
            ->get(['rs.etudiant_id', 's.code as sem', 'nv.code as niveau', DB::raw('MAX(rs.moyenne_generale) AS moyenne'),
                DB::raw('MAX(a.id) AS annee_id'), DB::raw('MAX(a.libelle) AS annee')]) as $r) {
            $parEtudiant[$r->etudiant_id][$r->sem] = ['moyenne' => (float) $r->moyenne, 'annee_id' => (int) $r->annee_id, 'annee' => $r->annee, 'niveau' => $r->niveau];
        }
        $ids = array_keys(array_filter($parEtudiant, fn ($s) => isset($s['S1'], $s['S2'], $s['S3'], $s['S4'])));
        if (! $ids) {
            return [];
        }
        $etudiants = DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')->whereIn('e.id', $ids)
            ->when($f['filiere_id'] > 0, fn ($q) => $q->where('e.filiere_id', $f['filiere_id']))
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('e.nom', 'like', '%'.addcslashes($f['q'], '%_\\').'%')
                ->orWhere('e.prenoms', 'like', '%'.addcslashes($f['q'], '%_\\').'%')->orWhere('e.numero', 'like', '%'.addcslashes($f['q'], '%_\\').'%')))
            ->orderBy('e.nom')->orderBy('e.prenoms')->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero', 'f.libelle as filiere']);

        $liste = [];
        foreach ($etudiants as $e) {
            $sems = $parEtudiant[$e->id];
            // Année et niveau de référence : ceux du dernier semestre atteint (S6 si licence complète, sinon S4)
            $ref = $sems['S6'] ?? $sems['S4'];
            if (($f['annee_id'] && $ref['annee_id'] !== $f['annee_id']) || ($f['niveau_code'] !== '' && $ref['niveau'] !== $f['niveau_code'])) {
                continue;
            }
            $liste[] = (object) (['id' => $e->id, 'nom' => $e->nom, 'prenoms' => $e->prenoms, 'numero' => $e->numero, 'filiere' => $e->filiere,
                'sems' => $sems] + $this->moyennes($sems));
        }

        return $liste;
    }

    private function dossier(Universite $u, int $id): ?array
    {
        $etudiant = DB::table('etudiants')->where('id', $id)
            ->first(['id', 'numero', 'nom', 'prenoms', 'sexe', 'date_naissance', 'lieu_naissance', 'nationalite', 'statut_etudiant']);
        if (! $etudiant) {
            return null;
        }
        $sems = [];
        foreach ($this->resultatsValides()->where('rs.etudiant_id', $id)->groupBy('s.code')
            ->get(['s.code as sem', DB::raw('MAX(rs.moyenne_generale) AS moyenne'), DB::raw('MAX(a.libelle) AS annee')]) as $r) {
            $sems[$r->sem] = ['moyenne' => (float) $r->moyenne, 'annee' => $r->annee];
        }
        $inscriptions = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->where('i.etudiant_id', $id)->orderByDesc('a.libelle')
            ->get(['a.libelle as annee', 'f.libelle as filiere', 'n.code as niveau', 'i.statut', 'i.date_inscription']);
        $resultats = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
            ->leftJoin('semestres as s', 's.id', '=', 'ps.semestre_id')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->where('rs.etudiant_id', $id)->whereIn('ps.statut', ['finalise', 'publie'])
            ->orderBy('a.libelle')->orderBy('n.code')->orderBy('s.code')->orderByRaw("ps.type_session = 'normale' DESC")
            ->get(['a.libelle as annee', 'n.code as niveau', 'f.libelle as filiere', DB::raw('COALESCE(s.code, ps.libelle) AS sem_code'), 'ps.type_session',
                'rs.moyenne_generale', 'rs.credits_obtenus', 'rs.credits_total', 'rs.mention', 'rs.valide', 'rs.defaillant', 'rs.rang']);
        $passages = DB::table('passages_niveau as pn')->join('annees_academiques as a', 'a.id', '=', 'pn.annee_depart_id')
            ->join('filieres as f', 'f.id', '=', 'pn.filiere_id')->where('pn.etudiant_id', $id)->orderByDesc('a.libelle')
            ->get(['a.libelle as annee', 'f.libelle as filiere', 'pn.decision']);

        $compte = Auth::guard('anab')->user();
        DB::table('audit_log')->insert(['user_id' => null, 'user_nom' => mb_substr('ANAB — '.$compte->nom_complet, 0, 150), 'user_role' => 'anab',
            'action' => 'consulter', 'module' => 'anab', 'description' => "Dossier bourse consulté : {$etudiant->numero} ({$compte->login})",
            'cible_id' => $id, 'ip_address' => request()->ip()]);

        return ['etudiant' => $etudiant, 'sems' => $sems, 'inscriptions' => $inscriptions, 'derniere' => $inscriptions->firstWhere('statut', 'inscrit'),
            'resultats' => $resultats->groupBy('annee'), 'passages' => $passages] + $this->moyennes($sems);
    }

    /** Moyenne sur S1–S4 (si les 4) et sur S1–S6 (si les 6). */
    private function moyennes(array $sems): array
    {
        $m = array_map(fn ($s) => $s['moyenne'], $sems);
        $quatre = array_intersect_key($m, array_flip(['S1', 'S2', 'S3', 'S4']));
        $six = array_intersect_key($m, array_flip(self::SEMESTRES));

        return ['moy4' => count($quatre) === 4 ? round(array_sum($quatre) / 4, 2) : null,
            'moy6' => count($six) === 6 ? round(array_sum($six) / 6, 2) : null,
            'annee_s4' => $sems['S4']['annee'] ?? null, 'annee_s6' => $sems['S6']['annee'] ?? null];
    }
}
