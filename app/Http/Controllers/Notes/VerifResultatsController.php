<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Pv;
use App\Services\Resultats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Vérification de cohérence des résultats — portage de
 * pages/notes_pv/verif_resultats.php (admin, secrétaire, sc_centrale,
 * sc_entite — ancien hasRole hiérarchique) :
 * compare resultats_semestre au recalcul du moteur (transaction annulée),
 * et « Corriger » recalcule pour de bon.
 *
 * Différences voulues :
 *  - la correction est un POST avec jeton CSRF (l'ancien lien GET ?fix=1
 *    pouvait être déclenché par une simple image piégée) et elle est
 *    journalisée ;
 *  - correction refusée sur un PV verrouillé (comme les autres actions) ;
 *  - un sc_entite sans faculté ne voit plus tous les PV : périmètre identique
 *    à pv_detail (Pv::accessible).
 */
class VerifResultatsController extends Controller
{
    public function __construct(private readonly Pv $pvs, private readonly Resultats $resultats) {}

    public function index(Request $request): View|RedirectResponse
    {
        $u = $request->user();
        $pvId = (int) $request->query('pv_id');
        $pv = $pvId ? $this->pvs->trouver($pvId) : null;
        if ($pvId && (! $pv || ! $this->pvs->accessible($u, $pv))) {
            return redirect()->route('verif_resultats')->with('erreur', 'Accès non autorisé — ce PV appartient à une autre faculté.');
        }

        $liste = DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->when($u->role === 'sc_entite', fn ($q) => $q->where('f.faculte_id', $u->faculteId()))
            ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('s.code')
            ->get(['ps.id', 'ps.type_session', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee', 's.code as sem']);

        $donnees = ['pvs' => $liste, 'pv' => $pv];
        if ($pv) {
            $base = DB::table('resultats_semestre as rs')->join('etudiants as e', 'e.id', '=', 'rs.etudiant_id')->where('rs.pv_id', $pv->id)
                ->orderBy('e.nom')->orderBy('e.prenoms')
                ->get(['rs.etudiant_id', 'e.nom', 'e.prenoms', 'e.numero', 'rs.moyenne_generale', 'rs.credits_obtenus', 'rs.credits_total', 'rs.valide', 'rs.defaillant']);
            $differences = [];
            $identiques = 0;
            foreach ($base as $rs) {
                // Le vrai moteur, dans une transaction annulée : l'outil ne peut pas diverger du calcul réel
                DB::beginTransaction();
                try {
                    $this->resultats->recalculer($pv->id, (int) $rs->etudiant_id);
                    $live = DB::table('resultats_semestre')->where(['pv_id' => $pv->id, 'etudiant_id' => $rs->etudiant_id])->first();
                } catch (Throwable) {
                    $live = null;
                } finally {
                    DB::rollBack();
                }
                if (! $live) {
                    continue; // recalcul impossible (données incomplètes)
                }
                $ecarts = [
                    'moy' => abs((float) $rs->moyenne_generale - (float) $live->moyenne_generale) > 0.01,
                    'creds' => (int) $rs->credits_obtenus !== (int) $live->credits_obtenus,
                    'valide' => (bool) $rs->valide !== (bool) $live->valide,
                    'def' => (bool) $rs->defaillant !== (bool) $live->defaillant,
                ];
                if (in_array(true, $ecarts, true)) {
                    $differences[] = ['et' => $rs, 'live' => $live, 'ecarts' => $ecarts];
                } else {
                    $identiques++;
                }
            }
            $donnees += ['differences' => $differences, 'identiques' => $identiques, 'total' => $base->count(),
                'creditsMax' => (int) $this->pvs->ues($pv)->sum('credits_total')];
        }

        return view('notes.verif_resultats', $donnees);
    }

    public function corriger(Request $request, Journal $journal): RedirectResponse
    {
        $pv = $this->pvs->trouver((int) $request->input('pv_id'));
        if (! $pv || ! $this->pvs->accessible($request->user(), $pv)) {
            return redirect()->route('verif_resultats')->with('erreur', 'Accès non autorisé — ce PV appartient à une autre faculté.');
        }
        if ($pv->verrouille) {
            return redirect()->route('verif_resultats', ['pv_id' => $pv->id])->with('erreur', 'PV verrouillé : déverrouillez-le avant de corriger ses résultats.');
        }
        $etudiants = DB::table('notes')->where('pv_id', $pv->id)->distinct()->pluck('etudiant_id')
            ->merge(DB::table('resultats_semestre')->where('pv_id', $pv->id)->pluck('etudiant_id'))->unique();
        foreach ($etudiants as $et) {
            $this->resultats->recalculer($pv->id, (int) $et);
        }
        $journal->action('recalculer', 'pv', "Correction des résultats du PV « {$pv->libelle} » (".$etudiants->count().' étudiant(s))', (int) $pv->id);

        return redirect()->route('verif_resultats', ['pv_id' => $pv->id])->with('succes', '✅ '.$etudiants->count().' résultat(s) recalculé(s) et corrigé(s).');
    }
}
