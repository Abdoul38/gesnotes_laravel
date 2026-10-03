<?php

namespace App\Http\Controllers\Portails;

use App\Http\Controllers\Controller;
use App\Services\IndicateursRectorat;
use App\Services\Journal;
use App\Services\Pv;
use App\Support\Classeur;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tableau de bord du rectorat — portage de pages/rectorat.php (recteur,
 * admin) : indicateurs de l'année, évolution, facultés et filières, PV
 * finalisés ou publiés, parcours d'un étudiant, exécution du programme,
 * historique des consultations ; tout en lecture seule.
 *
 * Différences voulues :
 *  - un PV en brouillon n'est plus consultable en changeant pv_id dans
 *    l'adresse (la liste ne montrait que les PV finalisés ou publiés) ;
 *  - plus de point d'entrée qui écrivait dans le journal des consultations
 *    ce que le navigateur envoyait : les exports sont servis (et journalisés)
 *    par le serveur ; PDF = pages imprimables (plus de html2pdf, bloqué par
 *    la politique de sécurité des contenus) ;
 *  - message du changement de mot de passe en session (il était repris de
 *    l'adresse : n'importe quel texte pouvait s'afficher sur la page) ;
 *    nouveau mot de passe : 8 caractères, lettres et chiffres ;
 *  - historique : le recteur voit ses propres consultations (« mes
 *    consultations »), l'administrateur toutes ;
 *  - parcours d'un étudiant : résultats enregistrés, sans la revérification
 *    « en direct » des défaillances (même choix que les relevés, étape 5) ;
 *  - Excel au format .xlsx (classeur XML 2003 avant).
 */
class RectoratController extends Controller
{
    public const ONGLETS = ['accueil' => '🏠 Accueil', 'evolution' => '📈 Évolution', 'detail' => '🏫 Facultés / Filières', 'pv' => '📄 PV',
        'etudiants' => '🔎 Étudiants', 'programme' => '📘 Programme', 'historique' => '🕵️ Historique'];

    public const SESSIONS = ['normale' => 'Session normale', 'rattrapage' => 'Rattrapage', 'reclamation' => 'Réclamation', 'reclamation_depot' => 'Réclamation dépôt'];

    public const STATUTS = ['brouillon' => ['Brouillon', 'badge-amber'], 'en_cours' => ['En cours', 'badge-amber'], 'finalise' => ['Finalisé', 'badge-blue'], 'publie' => ['Publié', 'badge-green']];

    public const CATEGORIES = ['complete' => ['✅ Complète', 'badge-green'], 'en_cours' => ['🟡 En cours', 'badge-amber'], 'sans_toucher' => ['🔴 Sans heure', 'badge-red'], 'sans_volume' => ['⚪ Sans volume', 'badge-blue']];

    public function __construct(private readonly IndicateursRectorat $indicateurs, private readonly Pv $pvs, private readonly UniversiteCourante $universite, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $onglet = isset(self::ONGLETS[$request->query('onglet')]) ? $request->query('onglet') : 'accueil';
        $this->tracer($request, 'consultation_dashboard', $onglet);
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get(['id', 'libelle', 'active']);
        $annee = $this->annee($annees, (int) $request->query('annee_id'));
        $stats = $this->indicateurs->pour((int) ($annee->id ?? 0));
        $donnees = compact('onglet', 'annees', 'annee', 'stats');

        return view('portails.rectorat', $donnees + match ($onglet) {
            'pv' => $this->ongletPv($request, $annees, $annee),
            'etudiants' => $this->ongletEtudiants($request),
            'programme' => $this->ongletProgramme($request, $annee, $stats),
            'historique' => ['journal' => DB::table('rectorat_logs_consultation')
                ->when($request->user()->role !== 'admin', fn ($q) => $q->where('user_id', $request->user()->id))
                ->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get()],
            default => [],
        });
    }

    /** Rapport détaillé Excel : synthèse, facultés, filières et niveaux, évolution. */
    public function excel(Request $request): BinaryFileResponse
    {
        $annee = $this->annee(DB::table('annees_academiques')->orderByDesc('libelle')->get(), (int) $request->query('annee_id'));
        $libelle = (string) ($annee->libelle ?? '');
        $s = $this->indicateurs->pour((int) ($annee->id ?? 0));
        $this->tracer($request, 'export_excel_rapport', $libelle);
        $nom = $this->universite->get()->nom;
        $pct = fn ($part, $tout) => $tout > 0 ? round($part / $tout * 100, 1) : 0;
        $entete = fn (array $titres) => ['height' => 30, 'cells' => array_map(fn ($t) => ['v' => $t, 'style' => 5], $titres)];
        $nbFilieres = fn (array $f) => count(array_unique(array_column($f['filieres'], 'filiere_id')));

        $classeur = new Classeur;
        $classeur->feuille('Synthèse', [
            ['height' => 26, 'cells' => [['v' => "Rapport Rectorat — $nom — Année académique $libelle", 'style' => 1]]],
            ['cells' => [['v' => 'Généré le '.now()->format('d/m/Y à H:i')]]],
            $entete(['Université', 'Année académique', 'Inscrits', 'Hommes', 'Femmes', '% Femmes', 'Passages', 'Redoublants', 'Taux de passage',
                'Heures prévues', 'Heures faites', 'Exécution programme', 'Facultés', 'Filières', 'PV publiés']),
            ['cells' => $this->cellules([$nom, $libelle, $s['inscrits'], $s['hommes'], $s['femmes'], $pct($s['femmes'], $s['inscrits']), $s['passages'],
                $s['redoublants'], $s['taux_passage'], $s['heures_prevues'], $s['heures_faites'], $s['taux_programme'], count($s['facultes']),
                array_sum(array_map($nbFilieres, $s['facultes'])), $s['pv_publies']])],
        ], [40, 18, 10, 10, 10, 10, 10, 12, 14, 14, 14, 16, 10, 10, 10], 3, ['A1:O1', 'A2:O2']);
        $classeur->feuille('Détail par faculté', [
            $entete(['Faculté', 'Filières', 'Inscrits', 'Hommes', 'Femmes', '% Femmes', 'Passages', 'Redoublants', 'Taux de passage',
                'Heures prévues', 'Heures faites', 'Exécution programme', 'PV publiés']),
            ...array_map(fn ($f) => ['cells' => $this->cellules([$f['faculte'], $nbFilieres($f), $f['inscrits'], $f['hommes'], $f['femmes'],
                $pct($f['femmes'], $f['inscrits']), $f['passages'], $f['redoublants'], $f['taux'], $f['heures_prevues'], $f['heures_faites'],
                $f['taux_programme'], $f['pv_publies']])], $s['facultes']),
        ], [44, 10, 10, 10, 10, 10, 10, 12, 14, 14, 14, 16, 10], 1);
        $filieres = [];
        foreach ($s['facultes'] as $f) {
            foreach ($f['filieres'] as $l) {
                $filieres[] = ['cells' => $this->cellules([$f['faculte'], $l['filiere'], $l['niveau'], $l['inscrits'], $l['femmes'], $pct($l['femmes'], $l['inscrits']),
                    $l['passages'], $l['redoublants'], $l['taux'], $l['heures_prevues'], $l['heures_faites'], $l['taux_programme']])];
            }
        }
        $classeur->feuille('Détail par filière', [
            $entete(['Faculté', 'Filière', 'Niveau', 'Inscrits', 'Femmes', '% Femmes', 'Passages', 'Redoublants', 'Taux de passage',
                'Heures prévues', 'Heures faites', 'Exécution programme']),
            ...$filieres,
        ], [36, 40, 10, 10, 10, 10, 10, 12, 14, 14, 14, 16], 1);
        $classeur->feuille('Évolution pluriannuelle', [
            $entete(['Année académique', 'Inscrits', 'Passages', 'Redoublants', 'Taux de passage']),
            ...array_map(fn ($e) => ['cells' => $this->cellules([$e['annee'], $e['inscrits'], $e['passages'], $e['redoublants'], $e['taux']])], $s['evolution']),
        ], [22, 12, 12, 14, 16], 1);

        return response()->download($classeur->enregistrer(), 'rapport_rectorat_'.str_replace('/', '-', $libelle).'_'.date('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** Rapport de synthèse imprimable (une page). */
    public function rapport(Request $request): View
    {
        $annee = $this->annee(DB::table('annees_academiques')->orderByDesc('libelle')->get(), (int) $request->query('annee_id'));
        $this->tracer($request, 'export_pdf_rapport_global', (string) ($annee->libelle ?? ''));

        return view('portails.rectorat_rapport', ['annee' => $annee, 'stats' => $this->indicateurs->pour((int) ($annee->id ?? 0)),
            'universite' => $this->universite->get()->nom]);
    }

    /** PV complet ou liste des admis, imprimables. */
    public function impressionPv(Request $request, int $id): View|RedirectResponse
    {
        $pv = $this->pvConsultable($id);
        if (! $pv) {
            return redirect()->route('rectorat', ['onglet' => 'pv'])->with('erreur', 'PV introuvable.');
        }
        $type = $request->query('type') === 'admis' ? 'admis' : 'global';
        $resultats = $this->resultats($pv->id);
        $this->tracer($request, $type === 'admis' ? 'export_pdf_admis' : 'export_pdf_global', "PV#{$pv->id}", $this->descriptionPv($pv));

        return view('portails.rectorat_pv_impression', ['pv' => $pv, 'type' => $type, 'resultats' => $resultats,
            'admis' => $resultats->where('valide', 1)->count()]);
    }

    public function motDePasse(Request $request): RedirectResponse
    {
        $retour = redirect()->route('rectorat', ['onglet' => isset(self::ONGLETS[$request->input('onglet')]) ? $request->input('onglet') : 'accueil']);
        $u = $request->user();
        if (! Hash::check((string) $request->input('ancien_mdp'), $u->mot_de_passe)) {
            return $retour->with('erreur', 'Mot de passe actuel incorrect.');
        }
        $v = Validator::make($request->all(), ['nouveau_mdp' => ['required', 'same:confirmer_mdp', Password::min(8)->letters()->numbers()]],
            ['same' => 'La confirmation ne correspond pas au nouveau mot de passe.'], ['nouveau_mdp' => 'nouveau mot de passe']);
        if ($v->fails()) {
            return $retour->with('erreur', $v->errors()->first());
        }
        $u->mot_de_passe = (string) $request->input('nouveau_mdp');
        $u->save();
        $this->journal->action('modifier', 'utilisateur', 'Changement de son mot de passe (rectorat)', (int) $u->id);

        return $retour->with('succes', 'Mot de passe modifié avec succès.');
    }

    private function ongletPv(Request $request, Collection $annees, ?object $annee): array
    {
        $pvAnnee = $annees->firstWhere('id', (int) $request->query('pv_annee_id')) ?? $annee;
        $filtres = ['pv_annee_id' => $pvAnnee?->id != $annee?->id ? $pvAnnee?->id : null,
            'pv_statut' => in_array($request->query('pv_statut'), ['finalise', 'publie'], true) ? $request->query('pv_statut') : null,
            'pv_session' => isset(self::SESSIONS[$request->query('pv_session')]) ? $request->query('pv_session') : null];
        $detail = (int) $request->query('pv_id') ? $this->pvConsultable((int) $request->query('pv_id')) : null;
        if ($detail) {
            $this->tracer($request, 'consultation_pv', "PV#{$detail->id}", $this->descriptionPv($detail));
            $resultats = $this->resultats($detail->id);
            $matieres = $this->categoriser(DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
                ->where(['ue.promotion_id' => $detail->promotion_id, 'ue.semestre_id' => $detail->semestre_id, 'm.actif' => 1])
                ->orderBy('m.ordre')->orderBy('m.libelle')->get(['m.id', 'm.code', 'm.libelle', 'm.credits', 'm.volume_horaire_prevu', 'm.heures_faites']));

            return ['pvAnnee' => $pvAnnee, 'filtres' => $filtres, 'detail' => $detail, 'resultats' => $resultats, 'matieresPv' => $matieres];
        }

        $liste = DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->where('p.annee_id', $pvAnnee?->id ?? 0)->whereIn('ps.statut', ['finalise', 'publie'])
            ->when($filtres['pv_statut'], fn ($q, $v) => $q->where('ps.statut', $v))
            ->when($filtres['pv_session'], fn ($q, $v) => $q->where('ps.type_session', $v))
            ->orderBy('f.faculte')->orderBy('f.libelle')->orderBy('n.code')->orderBy('s.code')
            ->get(['ps.id', 'ps.type_session', 'ps.statut', 'ps.verrouille', 'f.libelle as filiere', 'f.faculte', 'n.code as niveau', 's.libelle as sem_libelle',
                DB::raw('(SELECT COUNT(*) FROM resultats_semestre rs WHERE rs.pv_id = ps.id) AS nb_total'),
                DB::raw('(SELECT COUNT(*) FROM resultats_semestre rs WHERE rs.pv_id = ps.id AND rs.valide = 1) AS nb_admis'),
                DB::raw('(SELECT COUNT(*) FROM matieres m JOIN unites_enseignement ue ON ue.id = m.ue_id WHERE ue.promotion_id = ps.promotion_id AND ue.semestre_id = ps.semestre_id AND m.actif = 1) AS matieres_total'),
                DB::raw('(SELECT COUNT(DISTINCT no.matiere_id) FROM notes no WHERE no.pv_id = ps.id AND (no.note_examen IS NOT NULL OR no.note_cc IS NOT NULL)) AS matieres_evaluees')]);
        $total = DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->where('p.annee_id', $pvAnnee?->id ?? 0)->whereIn('ps.statut', ['finalise', 'publie'])->count();

        return ['pvAnnee' => $pvAnnee, 'filtres' => $filtres, 'detail' => null, 'liste' => $liste, 'totalPv' => $total];
    }

    private function ongletEtudiants(Request $request): array
    {
        $q = trim((string) $request->query('etu_q', ''));
        $trouves = mb_strlen($q) >= 2 ? DB::table('etudiants')
            ->where(fn ($w) => $w->where('nom', 'like', "%$q%")->orWhere('prenoms', 'like', "%$q%")->orWhere('numero', 'like', "%$q%"))
            ->orderBy('nom')->orderBy('prenoms')->limit(30)->get(['id', 'numero', 'nom', 'prenoms']) : collect();
        $etudiant = (int) $request->query('etu_id') ? DB::table('etudiants')->find((int) $request->query('etu_id'), ['id', 'numero', 'nom', 'prenoms', 'sexe', 'date_naissance', 'lieu_naissance']) : null;
        $parcours = [];
        $nbSemestres = 0;
        if ($etudiant) {
            $semestres = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
                ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                ->join('semestres as s', 's.id', '=', 'ps.semestre_id')->leftJoin('filieres as f', 'f.id', '=', 'p.filiere_id')->leftJoin('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->where('rs.etudiant_id', $etudiant->id)->whereIn('ps.statut', ['finalise', 'publie'])
                ->orderByDesc('a.libelle')->orderBy('s.code')->orderBy('ps.type_session')
                ->get(['ps.promotion_id', 'ps.type_session', 'a.id as annee_id', 'a.libelle as annee', 's.libelle as sem_label',
                    DB::raw("COALESCE(f.libelle, '—') AS filiere"), DB::raw("COALESCE(n.code, '?') AS niveau"), 'n.libelle as niv_label',
                    'rs.moyenne_generale', 'rs.mention', 'rs.decision', 'rs.valide', 'rs.credits_obtenus',
                    DB::raw('(SELECT COALESCE(SUM(ue.credits_total), 0) FROM unites_enseignement ue WHERE ue.promotion_id = ps.promotion_id AND ue.semestre_id = ps.semestre_id) AS credits_total')]);
            $inscriptions = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                ->leftJoin('filieres as f', 'f.id', '=', 'p.filiere_id')->leftJoin('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->where('i.etudiant_id', $etudiant->id)->orderByDesc('a.libelle')->orderBy('i.id')
                ->get(['i.statut as statut_inscription', 'p.id as promotion_id', 'a.id as annee_id', 'a.libelle as annee',
                    DB::raw("COALESCE(f.libelle, '—') AS filiere"), DB::raw("COALESCE(n.code, '?') AS niveau"), 'n.libelle as niv_label']);
            $decisions = DB::table('passages_niveau')->where('etudiant_id', $etudiant->id)->orderBy('id')->get(['annee_depart_id', 'decision'])
                ->unique('annee_depart_id')->pluck('decision', 'annee_depart_id');
            $avecResultat = $semestres->pluck('promotion_id')->flip();
            $bloc = fn ($l) => ['annee' => $l->annee, 'decision' => $decisions[$l->annee_id] ?? null, 'semestres' => [], 'sans_resultat' => []];
            foreach ($semestres as $l) {
                $parcours[$l->annee_id] ??= $bloc($l);
                $parcours[$l->annee_id]['semestres'][] = $l;
            }
            foreach ($inscriptions as $l) {
                $parcours[$l->annee_id] ??= $bloc($l);
                if (! $avecResultat->has($l->promotion_id)) {
                    $parcours[$l->annee_id]['sans_resultat'][] = $l;
                }
            }
            uasort($parcours, fn ($x, $y) => strcmp($y['annee'], $x['annee']));
            $nbSemestres = $semestres->count();
            $this->tracer($request, 'consultation_etudiant', "{$etudiant->numero} — {$etudiant->nom} {$etudiant->prenoms}");
        }

        return ['etuQ' => $q, 'trouves' => $trouves, 'etudiant' => $etudiant, 'parcours' => $parcours, 'nbSemestres' => $nbSemestres];
    }

    private function ongletProgramme(Request $request, ?object $annee, array $stats): array
    {
        $fac = (string) $request->query('prog_fac', '');
        $statut = isset(self::CATEGORIES[$request->query('prog_statut')]) ? $request->query('prog_statut') : '';
        $matieres = $this->categoriser(DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
            ->join('promotions as p', 'p.id', '=', 'ue.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'ue.semestre_id')
            ->leftJoin('affectations as af', fn ($j) => $j->on('af.matiere_id', '=', 'm.id')->on('af.annee_id', '=', 'p.annee_id'))
            ->leftJoin('enseignants as ens', 'ens.id', '=', 'af.enseignant_id')
            ->where('p.annee_id', $annee?->id ?? 0)->where('m.actif', 1)
            ->when($fac !== '', fn ($q) => $q->where('f.faculte', $fac))
            ->groupBy('m.id', 'm.code', 'm.libelle', 'm.credits', 'm.volume_horaire_prevu', 'm.heures_faites', 'm.ordre', 'f.faculte', 'f.libelle', 'n.code', 's.libelle')
            ->orderBy('f.faculte')->orderBy('f.libelle')->orderBy('n.code')->orderBy('m.ordre')->orderBy('m.libelle')
            ->get(['m.id', 'm.code', 'm.libelle', 'm.credits', 'm.volume_horaire_prevu', 'm.heures_faites', 'f.libelle as filiere', 'f.faculte',
                'n.code as niveau', 's.libelle as semestre', DB::raw("GROUP_CONCAT(DISTINCT CONCAT(ens.nom, ' ', ens.prenoms) SEPARATOR ', ') AS enseignants")]));

        return ['progFac' => $fac, 'progStatut' => $statut,
            'matieresProgramme' => $statut !== '' ? $matieres->where('categorie', $statut)->values() : $matieres];
    }

    /** Catégorie d'exécution d'une matière selon ses heures. */
    private function categoriser(Collection $matieres): Collection
    {
        return $matieres->map(function ($m) {
            $prevu = $m->volume_horaire_prevu !== null ? (float) $m->volume_horaire_prevu : null;
            $fait = (float) $m->heures_faites;
            $m->pct = $prevu > 0 ? min(100, round($fait / $prevu * 100, 1)) : 0;
            $m->categorie = $prevu === null ? 'sans_volume' : ($fait >= $prevu ? 'complete' : ($fait > 0 ? 'en_cours' : 'sans_toucher'));

            return $m;
        });
    }

    /** PV finalisé ou publié (les seuls que le rectorat consulte). */
    private function pvConsultable(int $id): ?object
    {
        $pv = $this->pvs->trouver($id);

        return $pv && in_array($pv->statut, ['finalise', 'publie'], true) ? $pv : null;
    }

    private function resultats(int $pvId): Collection
    {
        return DB::table('resultats_semestre as rs')->join('etudiants as e', 'e.id', '=', 'rs.etudiant_id')->where('rs.pv_id', $pvId)
            ->orderByRaw('rs.moyenne_generale IS NULL')->orderByDesc('rs.moyenne_generale')->orderBy('e.nom')->orderBy('e.prenoms')
            ->get(['e.numero', 'e.nom', 'e.prenoms', 'rs.credits_obtenus', 'rs.moyenne_generale', 'rs.decision', 'rs.mention', 'rs.rang', 'rs.valide']);
    }

    private function descriptionPv(object $pv): string
    {
        return "{$pv->filiere} — {$pv->niveau} — {$pv->sem_libelle} — ".(self::SESSIONS[$pv->type_session] ?? $pv->type_session);
    }

    /** Année demandée, sinon l'année active, sinon la plus récente. */
    private function annee(Collection $annees, int $id): ?object
    {
        return $annees->firstWhere('id', $id) ?? $annees->firstWhere('active', 1) ?? $annees->first();
    }

    private function cellules(array $valeurs): array
    {
        return array_map(fn ($v) => ['v' => $v], $valeurs);
    }

    /** Journal des consultations du rectorat (table rectorat_logs_consultation). */
    private function tracer(Request $request, string $action, ?string $cible = null, ?string $details = null): void
    {
        $u = $request->user();
        DB::table('rectorat_logs_consultation')->insert(['user_id' => $u->id, 'nom_complet' => mb_substr(trim($u->prenoms.' '.$u->nom), 0, 150) ?: null,
            'action' => $action, 'cible' => $cible !== null ? mb_substr($cible, 0, 255) : null, 'details' => $details !== null ? mb_substr($details, 0, 255) : null,
            'ip' => $request->ip(), 'created_at' => now()]);
    }
}
