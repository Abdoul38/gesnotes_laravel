<?php

namespace App\Http\Controllers\Rapports;

use App\Http\Controllers\Controller;
use App\Services\Pilotage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Tableau de bord de pilotage : réussite, notes non finalisées, retards de
 * saisie par enseignant, présence. Recteur, administration et scolarité
 * centrale : toute l'université (filtre par faculté) ; doyen et agent de
 * faculté : leur faculté ; chef de département : son département.
 */
class PilotageController extends Controller
{
    public function __invoke(Request $request, Pilotage $pilotage): View
    {
        $u = $request->user();
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeId = $annees->firstWhere('id', (int) $request->query('annee_id'))?->id ?? $annees->firstWhere('active', 1)?->id ?? $annees->first()?->id ?? 0;
        $global = in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'recteur'], true);
        $faculteId = $global ? ((int) $request->query('faculte_id') ?: null) : ($u->faculteId() ?: -1);
        $departementId = $u->role === 'chef_departement' ? ($u->departementId() ?: -1) : null;

        return view('rapports.pilotage', $pilotage->donnees((int) $anneeId, $faculteId, $departementId) + [
            'annees' => $annees,
            'anneeId' => $anneeId,
            'facultes' => $global ? DB::table('facultes')->orderBy('libelle')->get(['id', 'libelle']) : collect(),
            'faculteId' => $faculteId,
            'perimetre' => match (true) {
                $departementId !== null => DB::table('departements')->where('id', $departementId)->value('libelle') ?? 'Département',
                ! $global => DB::table('facultes')->where('id', $faculteId)->value('libelle') ?? 'Faculté',
                $faculteId !== null => DB::table('facultes')->where('id', $faculteId)->value('libelle'),
                default => 'Toute l\'université',
            },
            'route' => $request->route()->getName(),
            'rectorat' => $u->role === 'recteur',
        ]);
    }
}
