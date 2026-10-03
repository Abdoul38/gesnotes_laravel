<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Pv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Accès rapide à la saisie — portage de pages/notes_pv/notes.php : liste
 * des PV ni verrouillés ni publiés, dans le périmètre de l'agent.
 * Différence voulue : avancement calculé sur le nombre réel de matières (il
 * supposait 2 matières par PV).
 */
class SaisieNotesController extends Controller
{
    public function __invoke(Request $request, Pv $pvs): View|RedirectResponse
    {
        if ($request->filled('pv_id')) {
            return redirect()->route('pv_detail', ['id' => (int) $request->query('pv_id')]);
        }
        $u = $request->user();

        return view('notes.saisie', [
            'pvs' => DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                ->join('semestres as s', 's.id', '=', 'ps.semestre_id')
                ->where('ps.verrouille', 0)->where('ps.statut', '!=', 'publie')
                ->when($u->faculteId(), fn ($q, $f) => $q->where('f.faculte_id', $f))
                ->when($u->departementId(), fn ($q, $d) => $q->where('f.departement_id', $d))
                ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('n.code')
                ->get(['ps.id', 'ps.libelle', 'ps.type_session', 'ps.statut', 'f.code as fcode', 'n.code as niveau', 'a.libelle as annee', 's.libelle as sem_label',
                    DB::raw("(SELECT COUNT(DISTINCT ip.etudiant_id) FROM inscriptions_pedagogiques ip JOIN inscriptions i ON i.id = ip.inscription_id
                        JOIN promotions pe ON pe.id = i.promotion_id WHERE pe.filiere_id = p.filiere_id AND ip.annee_id = p.annee_id
                        AND ip.semestre_id = ps.semestre_id AND ip.statut = 'actif') AS nb_inscrits"),
                    DB::raw('(SELECT COUNT(*) FROM notes nt WHERE nt.pv_id = ps.id) AS nb_notes'),
                    DB::raw('(SELECT COUNT(*) FROM matieres m JOIN unites_enseignement ue ON ue.id = m.ue_id
                        WHERE ue.promotion_id = ps.promotion_id AND ue.semestre_id = ps.semestre_id AND m.actif = 1) AS nb_matieres')]),
            'faculte' => $u->faculteId() ? DB::table('facultes')->where('id', $u->faculteId())->first() : null,
            'departement' => $u->departementId() ? DB::table('departements')->where('id', $u->departementId())->value('libelle') : null,
            'gestionnaire' => $pvs->gestionnaire($u),
        ]);
    }
}
