<?php

namespace App\Http\Controllers\Portails;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * « Mes examens » — planning des examens vu par l'enseignant, en lecture
 * seule : les examens de ses matières (affectations de l'année) et ceux
 * qu'il surveille. Le planning se saisit dans « Planning examens ».
 */
class ExamensEnseignantController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $ens = (int) $request->user()->enseignant_id;
        if (! $ens || ! DB::table('enseignants')->where('id', $ens)->exists()) {
            return redirect()->route('accueil')->with('erreur', 'Aucun dossier enseignant lié à votre compte.');
        }
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeId = $annees->firstWhere('id', (int) $request->query('annee_id'))?->id ?? $annees->firstWhere('active', 1)?->id ?? $annees->first()?->id ?? 0;

        $examens = DB::table('examens_planning as ep')
            ->join('pv_sessions as ps', 'ps.id', '=', 'ep.pv_id')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('semestres as s', 's.id', '=', 'ps.semestre_id')->join('matieres as m', 'm.id', '=', 'ep.matiere_id')
            ->leftJoin('enseignants as s1', 's1.id', '=', 'ep.surveillant_1')->leftJoin('enseignants as s2', 's2.id', '=', 'ep.surveillant_2')
            ->where('p.annee_id', $anneeId)
            ->where(fn ($w) => $w->where('ep.surveillant_1', $ens)->orWhere('ep.surveillant_2', $ens)
                ->orWhereExists(fn ($s) => $s->from('affectations as af')->whereColumn('af.matiere_id', 'ep.matiere_id')
                    ->whereColumn('af.annee_id', 'p.annee_id')->where('af.enseignant_id', $ens)))
            ->orderBy('ep.date_examen')->orderBy('ep.heure_debut')
            ->get(['ep.date_examen', 'ep.heure_debut', 'ep.heure_fin', 'ep.salle', 'ep.notes', 'ep.surveillant_1', 'ep.surveillant_2',
                'm.libelle as matiere', 'f.libelle as filiere', 'n.code as niveau', 's.libelle as semestre', 'ps.type_session',
                's1.nom as s1_nom', 's1.prenoms as s1_prenoms', 's2.nom as s2_nom', 's2.prenoms as s2_prenoms',
                DB::raw('EXISTS (SELECT 1 FROM affectations af WHERE af.matiere_id = ep.matiere_id AND af.annee_id = p.annee_id AND af.enseignant_id = '.$ens.') AS ma_matiere')]);

        return view('portails.examens_enseignant', [
            'annees' => $annees,
            'anneeId' => $anneeId,
            'examens' => $examens,
            'enseignantId' => $ens,
            'aujourdhui' => now()->toDateString(),
        ]);
    }
}
