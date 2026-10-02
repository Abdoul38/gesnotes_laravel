<?php

namespace App\Http\Controllers;

use App\Models\AnneeAcademique;
use App\Services\TableauDeBord;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Tableau de bord — portage de pages/accueil.php. */
class AccueilController extends Controller
{
    public function __invoke(Request $request, TableauDeBord $tableau): View
    {
        $annees = AnneeAcademique::orderByDesc('libelle')->get();
        $anneeActiveId = (int) $annees->firstWhere('active', true)?->id;

        // Filtre d'affichage seulement : l'année active du système ne change pas.
        // Identifiant inconnu → repli sur l'année active.
        $anneeVueId = (int) $request->query('annee_vue', $anneeActiveId);
        if (! $annees->contains('id', $anneeVueId)) {
            $anneeVueId = $anneeActiveId;
        }

        return view('accueil', [
            'annees' => $annees,
            'anneeVueId' => $anneeVueId,
            's' => $tableau->pour($request->user(), $anneeVueId),
        ]);
    }
}
