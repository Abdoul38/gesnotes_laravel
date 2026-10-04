<?php

namespace App\Http\Controllers\Notes;

use App\Events\PvPublie;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Pv;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Liste des PV — portage de pages/notes_pv/pv.php (statut et titre
 * modifiables dans la liste).
 *
 * Différences voulues :
 *  - statut et titre : le PV doit appartenir au périmètre de l'agent
 *    (faculté, département) — avant, n'importe quel identifiant était accepté ;
 *  - la publication déclenche l'événement PvPublie (notification des
 *    étudiants, branchée avec le module Communication, étape 8) ;
 *  - progression de saisie calculée sur le nombre réel de matières (elle
 *    supposait 5 matières par PV).
 */
class PvController extends Controller
{
    public function __construct(private readonly Pv $pv, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $faculte = $u->faculteId();
        $departement = $u->departementId();
        $filtres = [
            'annee_id' => (int) $request->query('annee_id'), 'filiere_id' => (int) $request->query('filiere_id'),
            'niveau_id' => (int) $request->query('niveau_id'), 'statut' => (string) $request->query('statut'), 'q' => trim((string) $request->query('q')),
        ];

        $requete = DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->when($filtres['annee_id'], fn ($q, $v) => $q->where('p.annee_id', $v))
            ->when($filtres['filiere_id'], fn ($q, $v) => $q->where('f.id', $v))
            ->when($filtres['niveau_id'], fn ($q, $v) => $q->where('n.id', $v))
            ->when(isset(Pv::STATUTS[$filtres['statut']]), fn ($q) => $q->where('ps.statut', $filtres['statut']))
            ->when($filtres['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('ps.libelle', 'like', "%{$filtres['q']}%")
                ->orWhere('f.libelle', 'like', "%{$filtres['q']}%")->orWhere('a.libelle', 'like', "%{$filtres['q']}%")))
            ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
            ->when($departement, fn ($q) => $q->where('f.departement_id', $departement));

        $total = (clone $requete)->count();
        $pagination = Pagination::calculer($total, 20, (int) $request->query('p', 1));
        $liste = $requete->orderByDesc('ps.updated_at')->offset($pagination['offset'])->limit(20)
            ->get(['ps.*', 'f.libelle as filiere', 'f.code as filiere_code', 'n.code as niveau', 'a.libelle as annee', 's.libelle as sem_libelle',
                DB::raw("(SELECT COUNT(DISTINCT ip.etudiant_id) FROM inscriptions_pedagogiques ip JOIN inscriptions i ON i.id = ip.inscription_id
                    JOIN promotions pe ON pe.id = i.promotion_id WHERE pe.filiere_id = p.filiere_id AND ip.annee_id = p.annee_id
                    AND ip.semestre_id = ps.semestre_id AND ip.statut = 'actif') AS nb_inscrits"),
                DB::raw('(SELECT COUNT(*) FROM notes WHERE pv_id = ps.id) AS nb_notes'),
                DB::raw('(SELECT COUNT(*) FROM matieres m JOIN unites_enseignement ue ON ue.id = m.ue_id
                    WHERE ue.promotion_id = ps.promotion_id AND ue.semestre_id = ps.semestre_id AND m.actif = 1) AS nb_matieres')]);

        return view('notes.pv.liste', [
            'liste' => $liste,
            'total' => $total,
            'pagination' => $pagination,
            'filtres' => $filtres,
            'gestionnaire' => $this->pv->gestionnaire($u),
            'peutEditer' => $u->aRole('admin') || $u->aRole('sc_centrale') || $u->aRole('sc_entite'),
            'faculte' => $faculte ? DB::table('facultes')->where('id', $faculte)->first() : null,
            'departement' => $departement ? DB::table('departements')->where('id', $departement)->value('libelle') : null,
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'filieres' => DB::table('filieres')->where('actif', 1)->when($faculte, fn ($q) => $q->where('faculte_id', $faculte))
                ->when($departement, fn ($q) => $q->where('departement_id', $departement))->orderBy('libelle')->get(['id', 'code']),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(),
        ]);
    }

    public function statut(Request $request): JsonResponse
    {
        $pv = $this->pvModifiable($request);
        $statut = (string) $request->input('statut');
        if (! $pv || ! isset(Pv::STATUTS[$statut])) {
            return response()->json(['ok' => false, 'erreur' => 'Accès refusé ou statut invalide.'], 403);
        }
        DB::table('pv_sessions')->where('id', $pv->id)->update(['statut' => $statut, 'updated_at' => now()]);
        $this->journal->action($statut === 'publie' ? 'publier' : 'modifier', 'pv', "Statut du PV #{$pv->id} changé : {$pv->statut} → $statut", $pv->id);
        if ($statut === 'publie' && $pv->statut !== 'publie') {
            // Point de départ du délai de réclamation (une simple modification ne le relance plus)
            DB::table('pv_publications')->updateOrInsert(['pv_id' => $pv->id], ['publie_le' => now(), 'publie_par' => $request->user()->id]);
            event(new PvPublie($pv->id));
        }

        return response()->json(['ok' => true]);
    }

    public function titre(Request $request): RedirectResponse
    {
        $pv = $this->pvModifiable($request);
        $titre = trim((string) $request->input('libelle'));
        if ($pv && $titre !== '') {
            DB::table('pv_sessions')->where('id', $pv->id)->update(['libelle' => mb_substr($titre, 0, 200), 'updated_at' => now()]);

            return back()->with('succes', 'Titre du PV mis à jour.');
        }

        return back()->with('erreur', 'Modification impossible.');
    }

    /** PV visé, si l'agent peut le modifier (rôle et périmètre). */
    private function pvModifiable(Request $request): ?object
    {
        $u = $request->user();
        $pv = $this->pv->trouver((int) $request->input('pv_id'));
        $role = $u->aRole('admin') || $u->aRole('sc_centrale') || $u->aRole('sc_entite');
        $perimetre = $pv && (! $u->faculteId() || (int) $pv->faculte_id === $u->faculteId())
            && (! $u->departementId() || (int) $pv->departement_id === $u->departementId());

        return $role && $perimetre ? $pv : null;
    }
}
