<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Identite;
use App\Services\Parametres;
use App\Services\Pv;
use App\Services\Resultats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * PV imprimable (A4 paysage) — portage de pages/notes_pv/pv_imprimer.php.
 * Accès : admin, secrétaire, sc_centrale ; sc_entite et doyen de la faculté
 * du PV ; chef de département de son département.
 *
 * Différences voulues :
 *  - le PV imprimé reprend les résultats ENREGISTRÉS par le moteur (l'ancien
 *    recalculait à l'affichage décisions et crédits avec d'autres règles) ;
 *  - mêmes étudiants que le détail du PV : l'ancien n'imprimait, en session
 *    normale, que ceux de la promotion exacte (filière ET niveau) et omettait
 *    ceux qui composaient le semestre en avance ;
 *  - nom de l'université et ville tirés des paramètres (« Tahoua » était écrit
 *    en dur dans la ligne de date).
 */
class PvImpressionController extends Controller
{
    public function __invoke(Request $request, Pv $pvs, Resultats $resultats, Identite $identite, Parametres $parametres): View|RedirectResponse
    {
        $u = $request->user();
        $pv = $pvs->trouver((int) $request->query('id'));
        $ok = $pv && match ($u->role) {
            'admin', 'secretaire', 'sc_centrale' => true,
            'sc_entite', 'doyen' => $u->faculteId() > 0 && (int) $pv->faculte_id === $u->faculteId(),
            'chef_departement' => $u->departementId() > 0 && (int) $pv->departement_id === $u->departementId(),
            default => false,
        };
        if (! $ok) {
            return redirect()->route('pv')->with('erreur', 'Accès refusé — ce PV ne relève pas de votre périmètre.');
        }
        $libelles = DB::table('filieres as f')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')->leftJoin('departements as d', 'd.id', '=', 'f.departement_id')
            ->where('f.id', $pv->filiere_id)->first([DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'), DB::raw('COALESCE(d.libelle, f.departement) AS departement')]);

        $filtre = (int) $request->query('et_id');
        $tous = $pvs->etudiants($pv)->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero']);
        $etudiants = $filtre ? $tous->where('id', $filtre)->values() : $tous;
        $ids = $etudiants->pluck('id');
        $resSem = DB::table('resultats_semestre')->where('pv_id', $pv->id)->whereIn('etudiant_id', $tous->pluck('id'))->get()->keyBy('etudiant_id');

        return view('notes.pv.impression', [
            // Format du papier : A4 paysage par défaut, A3 paysage pour les PV très chargés
            'format' => $request->query('format') === 'a3' ? 'a3' : 'a4',
            'pv' => $pv,
            'faculte' => $libelles->faculte ?? '',
            'departement' => $libelles->departement ?? '',
            'ues' => $pvs->ues($pv),
            'etudiants' => $etudiants,
            'tous' => $tous,
            'filtre' => $filtre,
            'notes' => DB::table('notes')->where('pv_id', $pv->id)->whereIn('etudiant_id', $ids)->get()->groupBy('etudiant_id')->map->keyBy('matiere_id'),
            'resUe' => DB::table('resultats_ue')->where('pv_id', $pv->id)->whereIn('etudiant_id', $ids)->get()->groupBy('etudiant_id')->map->keyBy('ue_id'),
            'resSem' => $resSem,
            // Repêchages en vigueur, imprimés sous le tableau (trace officielle)
            'repechages' => Schema::hasTable('repechages') ? DB::table('repechages as r')->join('etudiants as e', 'e.id', '=', 'r.etudiant_id')
                ->join('matieres as m', 'm.id', '=', 'r.matiere_id')->where(['r.pv_id' => $pv->id, 'r.annule' => 0])->whereIn('r.etudiant_id', $ids)
                ->orderBy('e.nom')->orderBy('e.prenoms')->get(['r.*', 'e.nom', 'e.prenoms', 'e.numero', 'm.libelle as matiere']) : collect(),
            'choix' => $pvs->choixOptions($pv),
            'seuil' => (float) $resultats->regle('seuil_validation', (int) $pv->annee_id, '10'),
            'logos' => $identite->logos($pv->faculte_id ? (int) $pv->faculte_id : null),
            'signataire' => $identite->signataire($pv->faculte_id ? (int) $pv->faculte_id : null),
            'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
            'ville' => $parametres->valeur('institution_ville', 'Tahoua'),
        ]);
    }
}
