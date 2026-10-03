<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Parametres;
use App\Services\Pv;
use App\Services\Releves;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Relevés de notes individuels — portage de pages/notes_pv/releves.php :
 * choix du PV, liste des étudiants validés (lien vers le relevé officiel),
 * aperçu du relevé dans l'application.
 *
 * Différences voulues :
 *  - l'aperçu affiche les observations enregistrées par le moteur (l'ancien
 *    recalcul « en direct » ignorait la compensation : une matière AC
 *    apparaissait NV) et masque les options non choisies ;
 *  - périmètre (faculté, département, enseignant) vérifié aussi pour la liste ;
 *  - liste = étudiants validés dans le PV (comme le relevé « tous les
 *    validés »), sans exiger une inscription pédagogique « actif » ;
 *  - ville et institution tirées des paramètres (« Tahoua » était en dur).
 */
class RelevesController extends Controller
{
    public function __invoke(Request $request, Pv $pvs, Releves $calcul, Parametres $parametres): View|RedirectResponse
    {
        $u = $request->user();
        $pvId = (int) $request->query('pv_id');
        $q = trim((string) $request->query('q', ''));
        $pv = $pvId ? $pvs->trouver($pvId) : null;
        if ($pvId && (! $pv || ! $pvs->accessible($u, $pv))) {
            return redirect()->route('releves')->with('erreur', 'Accès refusé — ce PV ne relève pas de votre périmètre.');
        }

        $etId = (int) $request->query('et_id');
        if ($pv && $etId) {
            $resultat = DB::table('resultats_semestre')->where(['pv_id' => $pv->id, 'etudiant_id' => $etId])->first();
            $etudiant = DB::table('etudiants')->find($etId);
            if (! $resultat || ! $etudiant) {
                return redirect()->route('releves', ['pv_id' => $pv->id])->with('erreur', "Cet étudiant n'a pas de résultat dans ce PV.");
            }
            $notes = DB::table('notes')->where(['pv_id' => $pv->id, 'etudiant_id' => $etId])->get()->keyBy('matiere_id');

            return view('notes.releves_apercu', [
                'pv' => $pv,
                'et' => $etudiant,
                'sr' => $resultat,
                'lignes' => $calcul->lignes($pvs->ues($pv), $pvs->choixOptions($pv), $notes, $etId),
                'faculte' => DB::table('facultes')->where('id', $pv->faculte_id)->value('libelle') ?? $pv->faculte,
                'republique' => $parametres->valeur('institution_republique', 'République du Niger'),
                'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
                'ville' => $parametres->valeur('institution_ville', 'Tahoua'),
            ]);
        }

        $listePvs = DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->when($u->faculteId(), fn ($r, $f) => $r->where('f.faculte_id', $f))
            ->when($u->departementId(), fn ($r, $d) => $r->where('f.departement_id', $d))
            ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('s.code')
            ->get(['ps.id', 'ps.libelle', 'ps.type_session', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee', 's.code as sem']);

        $etudiants = collect();
        if ($pv) {
            $etudiants = DB::table('resultats_semestre as rs')->join('etudiants as e', 'e.id', '=', 'rs.etudiant_id')
                ->where('rs.pv_id', $pv->id)->where('rs.valide', 1)
                ->when($q !== '', fn ($r) => $r->where(fn ($w) => $w->where('e.nom', 'like', "%$q%")->orWhere('e.prenoms', 'like', "%$q%")->orWhere('e.numero', 'like', "%$q%")))
                ->orderBy('e.nom')->orderBy('e.prenoms')->get(['e.id', 'e.numero', 'e.nom', 'e.prenoms']);
        }

        return view('notes.releves', ['pvs' => $listePvs, 'pvId' => $pv?->id, 'q' => $q, 'etudiants' => $etudiants]);
    }
}
