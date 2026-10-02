<?php

namespace App\Http\Controllers\Structure;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Promotions — portage de pages/structure/promotions.php.
 * (L'action « creer_promo » de l'ancienne page n'était appelée par aucun
 * formulaire : non reprise.)
 */
class PromotionsController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $faculteId = $this->faculteFiltree($request->user());

        // Par défaut l'année active ; « 0 » = toutes les années.
        $anneeFiltre = $request->has('annee_id')
            ? (int) $request->query('annee_id')
            : (int) DB::table('annees_academiques')->where('active', 1)->value('id');

        $promos = DB::table('promotions as p')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->select('p.*', 'f.code as fcode', 'f.libelle as filiere', 'n.code as niveau', 'n.libelle as niv_libelle', 'a.libelle as annee', 'a.active as annee_active')
            ->selectRaw("(SELECT COUNT(*) FROM inscriptions i WHERE i.promotion_id=p.id AND i.statut='inscrit') AS nb_inscrits")
            ->selectRaw('(SELECT COUNT(*) FROM pv_sessions ps WHERE ps.promotion_id=p.id) AS nb_pv')
            ->selectRaw('(SELECT COUNT(DISTINCT ue.id) FROM unites_enseignement ue WHERE ue.promotion_id=p.id) AS nb_ue')
            ->when($faculteId, fn ($q) => $q->where('f.faculte_id', $faculteId))
            ->when($anneeFiltre, fn ($q) => $q->where('a.id', $anneeFiltre))
            ->orderByDesc('a.libelle')->orderBy('f.code')->orderBy('n.code')
            ->get();

        return view('structure.promotions', [
            'promos' => $promos,
            'anneeFiltre' => $anneeFiltre,
            'filieres' => $this->filieres($request->user())->orderBy('code')->get(),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(),
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
        ]);
    }

    public function supprimer(Request $request): RedirectResponse
    {
        abort_unless($request->user()->aRole('admin'), 403);
        $id = (int) $request->input('id');
        // Efface en cascade inscriptions, PV, notes et UE de la promotion
        // (voulu : « Supprimer cette promotion et toutes ses données ? »).
        DB::table('promotions')->where('id', $id)->delete();
        $this->journal->audit('PROMO_SUPPRIMER', 'promotions', $id);

        return $this->retour('succes', 'Promotion supprimée.');
    }

    /** Une promotion pour chaque combinaison filière × niveau (doublons ignorés). */
    public function generer(Request $request): RedirectResponse
    {
        $anneeId = (int) $request->input('annee_id');
        if (! $anneeId) {
            return $this->retour('erreur', 'Veuillez sélectionner une année.');
        }

        // Vide = toutes. Toujours limité aux filières actives du périmètre
        // de l'utilisateur (l'ancienne page prenait toutes les facultés).
        $autorisees = $this->filieres($request->user())->pluck('id')->all();
        $filieres = array_map('intval', (array) $request->input('filieres_ids', []));
        $filieres = $filieres ? array_values(array_intersect($filieres, $autorisees)) : $autorisees;
        $niveaux = array_map('intval', (array) $request->input('niveaux_ids', []))
            ?: DB::table('niveaux')->pluck('id')->all();

        $creees = 0;
        $existantes = 0;
        foreach ($filieres as $f) {
            foreach ($niveaux as $n) {
                $ajout = DB::table('promotions')->insertOrIgnore(['filiere_id' => $f, 'niveau_id' => $n, 'annee_id' => $anneeId, 'effectif_prevu' => 0]);
                $ajout ? $creees++ : $existantes++;
            }
        }

        return $this->retour('succes', "$creees promotion(s) créée(s)".($existantes ? " — $existantes déjà existante(s)" : '').'.');
    }

    /** Recopie la liste filière × niveau d'une année vers une autre. */
    public function copier(Request $request): RedirectResponse
    {
        $source = (int) $request->input('annee_source');
        $destination = (int) $request->input('annee_dest');
        if (! $source || ! $destination || $source === $destination) {
            return $this->retour('erreur', 'Années invalides.');
        }

        $faculteId = $this->faculteFiltree($request->user());
        $promos = DB::table('promotions as p')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->where('p.annee_id', $source)
            ->when($faculteId, fn ($q) => $q->where('f.faculte_id', $faculteId))
            ->get(['p.filiere_id', 'p.niveau_id']);

        $creees = 0;
        foreach ($promos as $p) {
            $creees += DB::table('promotions')->insertOrIgnore(['filiere_id' => $p->filiere_id, 'niveau_id' => $p->niveau_id, 'annee_id' => $destination, 'effectif_prevu' => 0]);
        }

        return $this->retour('succes', "$creees promotion(s) copiée(s) vers la nouvelle année.");
    }

    /** Faculté de l'utilisateur, si la colonne filieres.faculte_id existe (0 = toutes). */
    private function faculteFiltree(Utilisateur $u): int
    {
        return ($u->faculteId() && Schema::hasColumn('filieres', 'faculte_id')) ? $u->faculteId() : 0;
    }

    private function filieres(Utilisateur $u)
    {
        $faculteId = $this->faculteFiltree($u);

        return DB::table('filieres')->where('actif', 1)->when($faculteId, fn ($q) => $q->where('faculte_id', $faculteId));
    }

    private function retour(string $type, string $message): RedirectResponse
    {
        return redirect()->route('promotions')->with($type, $message);
    }
}
