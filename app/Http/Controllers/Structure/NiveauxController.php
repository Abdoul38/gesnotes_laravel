<?php

namespace App\Http\Controllers\Structure;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Niveaux (L1, DUT1, M2…) — portage de pages/structure/niveaux.php. */
class NiveauxController extends Controller
{
    public function index(): View
    {
        $niveaux = DB::select('
            SELECT n.*,
                (SELECT COUNT(*) FROM promotions p WHERE p.niveau_id=n.id) AS nb_promotions
            FROM niveaux n ORDER BY n.code
        ');

        return view('structure.niveaux', ['niveaux' => $niveaux]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');
        $code = mb_strtoupper(trim((string) $request->input('code')));
        $libelle = trim((string) $request->input('libelle'));

        if ($code === '' || $libelle === '') {
            return $this->retour('erreur', 'Code et libellé requis.');
        }
        // La colonne fait 10 caractères : l'ancien code laissait MySQL tronquer en silence.
        if (mb_strlen($code) > 10) {
            return $this->retour('erreur', 'Le code ne doit pas dépasser 10 caractères.');
        }
        if (DB::table('niveaux')->where('code', $code)->where('id', '!=', $id)->exists()) {
            return $this->retour('erreur', "Le code «{$code}» est déjà utilisé par un autre niveau.");
        }

        if ($id) {
            DB::table('niveaux')->where('id', $id)->update(['code' => $code, 'libelle' => $libelle]);

            return $this->retour('succes', 'Niveau mis à jour.');
        }
        DB::table('niveaux')->insert(['code' => $code, 'libelle' => $libelle]);

        return $this->retour('succes', "Niveau «{$code}» créé.");
    }

    public function supprimer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');

        $nb = DB::table('promotions')->where('niveau_id', $id)->count();
        if ($nb > 0) {
            return $this->retour('erreur', "Impossible — $nb promotion(s) utilisent déjà ce niveau.");
        }
        // En plus de l'ancien contrôle : la suppression effacerait en cascade
        // les tarifs du niveau et retirerait le niveau des inscriptions.
        $liens = array_filter([
            'inscription(s)' => DB::table('inscriptions')->where('niveau_id', $id)->count(),
            'tarif(s)' => DB::table('grille_tarifaire')->where('niveau_id', $id)->count()
                + DB::table('tarifs_niveaux')->where('niveau_id', $id)->count(),
        ]);
        if ($liens) {
            $detail = implode(', ', array_map(fn ($n, $quoi) => "$n $quoi", $liens, array_keys($liens)));

            return $this->retour('erreur', "Impossible — ce niveau est encore utilisé : $detail.");
        }

        DB::table('niveaux')->where('id', $id)->delete();

        return $this->retour('succes', 'Niveau supprimé.');
    }

    private function retour(string $type, string $message): RedirectResponse
    {
        return redirect()->route('niveaux')->with($type, $message);
    }
}
