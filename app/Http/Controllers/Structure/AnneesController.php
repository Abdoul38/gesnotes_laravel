<?php

namespace App\Http\Controllers\Structure;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Années académiques — portage de pages/structure/annees.php. */
class AnneesController extends Controller
{
    public function index(): View
    {
        $annees = DB::select("
            SELECT a.*,
               (SELECT COUNT(*) FROM promotions p WHERE p.annee_id=a.id) AS nb_promos,
               (SELECT COUNT(DISTINCT i.etudiant_id) FROM inscriptions i JOIN promotions p ON p.id=i.promotion_id WHERE p.annee_id=a.id AND i.statut='inscrit') AS nb_etudiants
            FROM annees_academiques a ORDER BY a.libelle DESC
        ");

        return view('structure.annees', ['annees' => $annees]);
    }

    public function creer(Request $request): RedirectResponse
    {
        $libelle = trim((string) $request->input('libelle'));
        if (! preg_match('/^\d{4}-\d{4}$/', $libelle)) {
            return $this->retour('erreur', 'Format attendu : 2024-2025');
        }
        try {
            DB::table('annees_academiques')->insert([
                'libelle' => $libelle,
                'date_debut' => $request->input('date_debut') ?: null,
                'date_fin' => $request->input('date_fin') ?: null,
            ]);
        } catch (QueryException) {
            return $this->retour('erreur', 'Cette année existe déjà.');
        }

        return $this->retour('succes', "Année $libelle créée.");
    }

    public function activer(Request $request, Journal $journal): RedirectResponse
    {
        $id = (int) $request->input('id');
        DB::transaction(function () use ($id) {
            DB::table('annees_academiques')->update(['active' => 0]);
            DB::table('annees_academiques')->where('id', $id)->update(['active' => 1]);
        });
        $journal->audit('ANNEE_ACTIVER', 'annees_academiques', $id);

        return $this->retour('succes', 'Année activée.');
    }

    public function supprimer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');

        // La suppression d'une année efface EN CASCADE ses promotions,
        // inscriptions pédagogiques, emploi du temps, affectations et tarifs.
        // L'ancienne page ne masquait le bouton qu'à l'écran : la règle
        // « aucune promotion » est désormais vérifiée aussi par le serveur.
        if (DB::table('promotions')->where('annee_id', $id)->exists()) {
            return $this->retour('erreur', 'Impossible : des promotions sont rattachées à cette année.');
        }
        $supprimees = DB::table('annees_academiques')->where('id', $id)->where('active', 0)->delete();

        return $supprimees
            ? $this->retour('succes', 'Année supprimée.')
            : $this->retour('erreur', 'Année non supprimée : une année active ne peut pas être supprimée.');
    }

    private function retour(string $type, string $message): RedirectResponse
    {
        return redirect()->route('annees')->with($type, $message);
    }
}
