<?php

namespace App\Http\Controllers\Portails;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Structure\EmploiTempsController as EDT;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * « Mon emploi du temps » — séances de l'enseignant (toutes promotions),
 * par jour, en lecture seule : l'emploi du temps se saisit dans
 * « Emploi du temps ». Les séances simultanées (deux promotions sur le même
 * créneau) sont toutes affichées et signalées.
 */
class EmploiTempsEnseignantController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $ens = (int) $request->user()->enseignant_id;
        if (! $ens || ! DB::table('enseignants')->where('id', $ens)->exists()) {
            return redirect()->route('accueil')->with('erreur', 'Aucun dossier enseignant lié à votre compte.');
        }
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeId = $annees->firstWhere('id', (int) $request->query('annee_id'))?->id ?? $annees->firstWhere('active', 1)?->id ?? $annees->first()?->id ?? 0;
        $semestres = DB::table('semestres')->orderBy('code')->get(['id', 'code', 'libelle']);
        $semId = (int) $semestres->firstWhere('id', (int) $request->query('sem_id'))?->id;

        $seances = DB::table('emploi_temps as et')
            ->join('promotions as p', 'p.id', '=', 'et.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'et.semestre_id')
            ->leftJoin('matieres as m', 'm.id', '=', 'et.matiere_id')
            ->where(['et.enseignant_id' => $ens, 'et.annee_id' => $anneeId])
            ->when($semId, fn ($q) => $q->where('et.semestre_id', $semId))
            ->orderByRaw("FIELD(et.jour, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi')")->orderBy('et.heure_debut')
            ->get(['et.jour', 'et.heure_debut', 'et.heure_fin', 'et.salle', 'et.type_seance', 'et.couleur', 'm.libelle as matiere',
                'f.libelle as filiere', 'n.code as niveau', 's.code as semestre']);
        // Chevauchement : une séance commence avant la fin d'une autre le même jour
        foreach ($seances->groupBy('jour') as $jour) {
            $jour->values()->each(function ($s, $i) use ($jour) {
                $s->conflit = $jour->values()->contains(fn ($t, $k) => $k !== $i && $t->heure_debut < $s->heure_fin && $s->heure_debut < $t->heure_fin);
            });
        }

        return view('portails.emploi_temps_enseignant', [
            'annees' => $annees,
            'anneeId' => $anneeId,
            'semestres' => $semestres,
            'semId' => $semId,
            'jours' => collect(EDT::JOURS)->mapWithKeys(fn ($j) => [$j => $seances->where('jour', $j)->values()]),
            'nb' => $seances->count(),
            'heures' => round($seances->sum(fn ($s) => (strtotime($s->heure_fin) - strtotime($s->heure_debut)) / 3600), 1),
        ]);
    }
}
