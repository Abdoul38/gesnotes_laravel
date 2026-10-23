<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Services\Identite;
use App\Services\Parametres;
use App\Services\Pv;
use App\Services\RegistreDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Convocations aux examens — portage de pages/documents/convocations.php
 * (admin, secrétaire, sc_centrale, sc_entite) : choix du PV, puis une page
 * A4 par étudiant avec le calendrier des épreuves (planning_examens).
 *
 * Différences voulues :
 *  - étudiants convoqués = étudiants du PV (App\Services\Pv) : en
 *    rattrapage, ceux déjà admis en session normale ne sont plus convoqués ;
 *  - convocation individuelle seulement pour un étudiant du PV (n'importe
 *    quel identifiant était accepté) ;
 *  - PV du périmètre de l'agent aussi pour un chef de département ;
 *  - numéro enregistré (convocations_emises), le même à chaque
 *    réimpression (avant : rang dans la liste imprimée).
 */
class ConvocationsController extends Controller
{
    public function __invoke(Request $request, Pv $pvs, Identite $identite, Parametres $parametres, RegistreDocuments $registre): View|RedirectResponse
    {
        $u = $request->user();
        if (! $request->filled('pv_id')) {
            return view('documents.convocations_choix', [
                'pvs' => DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                    ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                    ->when($u->role === 'sc_entite', fn ($q) => $q->where('f.faculte_id', $u->faculteId()))
                    ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('ps.type_session')
                    ->get(['ps.id', 'ps.libelle', 'ps.type_session', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']),
            ]);
        }
        $pv = $pvs->trouver((int) $request->query('pv_id'));
        if (! $pv || ! $pvs->accessible($u, $pv)) {
            return redirect()->route('convocations')->with('erreur', 'Accès non autorisé — ce PV appartient à une autre faculté.');
        }
        $masse = $request->has('masse');
        $etudiants = $pvs->etudiants($pv)->when(! $masse, fn ($q) => $q->where('e.id', (int) $request->query('et_id')))->get();
        if (! $masse && $etudiants->isEmpty()) {
            return redirect()->route('convocations')->with('erreur', 'Cet étudiant ne compose pas dans ce PV.');
        }
        $libelles = DB::table('filieres as f')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')->leftJoin('departements as d', 'd.id', '=', 'f.departement_id')
            ->where('f.id', $pv->filiere_id)->first([DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'), DB::raw('COALESCE(d.libelle, f.departement) AS departement')]);
        $faculte = $pv->faculte_id ? (int) $pv->faculte_id : null;

        return view('documents.convocations', [
            'numeros' => $etudiants->mapWithKeys(fn ($e) => [$e->id => $registre->enregistrer('convocations_emises', 'CV'.date('y').'-',
                ['etudiant_id' => $e->id, 'pv_id' => $pv->id], ['date_emission' => now()])]),
            'pv' => $pv,
            'masse' => $masse,
            'etudiants' => $etudiants,
            'matieres' => DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
                // Planning de la promotion, du semestre et de la session du PV
                ->leftJoin('planning_examens as ep', fn ($j) => $j->on('ep.matiere_id', '=', 'm.id')->where(['ep.promotion_id' => $pv->promotion_id,
                    'ep.semestre_id' => $pv->semestre_id, 'ep.session' => $pv->type_session === 'rattrapage' ? 'rattrapage' : 'normale']))
                ->where(['ue.promotion_id' => $pv->promotion_id, 'ue.semestre_id' => $pv->semestre_id, 'm.actif' => 1])
                ->orderBy('ue.ordre')->orderBy('m.ordre')
                ->get(['m.libelle', 'ue.libelle as ue', 'ep.date_examen', 'ep.heure_debut', 'ep.heure_fin', 'ep.salle']),
            'faculte' => $libelles->faculte ?? '',
            'departement' => $libelles->departement ?? '',
            'signataire' => $identite->signataire($faculte),
            'logos' => $identite->logos($faculte),
            'republique' => $parametres->valeur('institution_republique', 'REPUBLIQUE DU NIGER'),
            'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
            'bp' => $parametres->valeur('institution_bp', 'BP : 255 Tahoua-Niger'),
            'ville' => $parametres->valeur('institution_ville', 'Tahoua'),
        ]);
    }
}
