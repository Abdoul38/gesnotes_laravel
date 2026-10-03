<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Navigation\Menu;
use App\Services\Identite;
use App\Services\Journal;
use App\Services\Parametres;
use App\Services\Pv;
use App\Services\Releves;
use App\Services\RelevesEmis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Relevé de notes (individuel ou tous les validés du PV) — portage de
 * pages/notes_pv/releve_notes.php. Une page A4 par étudiant, QR de
 * vérification et inscription au registre releves_emis.
 *
 * Différences voulues :
 *  - observations, moyenne, mention et crédits tirés des résultats
 *    enregistrés par le moteur (l'ancienne page re-jugeait les défaillances
 *    « en direct » avec une règle différente de celle du moteur) ;
 *  - un étudiant ne sort son relevé que pour un PV publié ;
 *  - l'étudiant demandé doit avoir un résultat dans ce PV ;
 *  - l'enseignant n'accède qu'aux PV où il a une matière (périmètre de
 *    pv_detail), plus à n'importe quel étudiant ;
 *  - numéro de relevé attribué sous verrou (voir RelevesEmis).
 */
class ReleveNotesController extends Controller
{
    public function __invoke(Request $request, Pv $pvs, Identite $identite, Parametres $parametres, RelevesEmis $registre, Releves $calcul, Journal $journal, Menu $menu): View|RedirectResponse
    {
        $u = $request->user();
        $estEtudiant = $u->role === 'etudiant';
        $pv = $pvs->trouver((int) $request->query('pv_id'));
        if ($estEtudiant) {
            $refus = match (true) {
                ! $u->etudiant_id => 'Aucun dossier étudiant lié.',
                ! $pv || $pv->statut !== 'publie' => 'Relevé non disponible : les résultats de ce PV ne sont pas encore publiés.',
                $request->filled('et_id') && (int) $request->query('et_id') !== (int) $u->etudiant_id => 'Accès refusé.',
                default => null,
            };
            if ($refus) {
                return redirect($menu->lien('portail_etudiant'))->with('erreur', $refus);
            }
        } elseif (! $pv || ! $pvs->accessible($u, $pv)) {
            return redirect()->route('pv')->with('erreur', 'Accès refusé — ce relevé ne relève pas de votre périmètre.');
        }

        $masse = ! $estEtudiant && $request->has('masse');
        $requete = DB::table('resultats_semestre as rs')->join('etudiants as e', 'e.id', '=', 'rs.etudiant_id')->where('rs.pv_id', $pv->id)
            ->select('e.*', 'rs.moyenne_generale', 'rs.mention', 'rs.valide', 'rs.credits_obtenus');
        if ($masse) {
            $requete->where('rs.valide', 1)->orderBy('rs.rang')->orderBy('e.nom');
        } else {
            $requete->where('e.id', $estEtudiant ? $u->etudiant_id : (int) $request->query('et_id'));
        }
        $etudiants = $requete->get();
        if (! $masse && $etudiants->isEmpty()) {
            return $estEtudiant
                ? redirect($menu->lien('portail_etudiant'))->with('erreur', 'Aucun résultat pour vous dans ce PV.')
                : redirect()->route('pv_detail', $pv->id)->with('erreur', "Cet étudiant n'a pas de résultat dans ce PV.");
        }

        $ues = $pvs->ues($pv);
        $choix = $pvs->choixOptions($pv);
        $notes = DB::table('notes')->where('pv_id', $pv->id)->whereIn('etudiant_id', $etudiants->pluck('id'))->get()->groupBy('etudiant_id');
        $libelles = DB::table('filieres as f')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')->leftJoin('departements as d', 'd.id', '=', 'f.departement_id')
            ->where('f.id', $pv->filiere_id)->first([DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'), DB::raw('COALESCE(d.libelle, f.departement) AS departement')]);

        $releves = $etudiants->map(function ($et) use ($ues, $choix, $notes, $pv, $registre, $journal, $menu, $calcul) {
            $lignes = $calcul->lignes($ues, $choix, $notes->get($et->id, collect())->keyBy('matiere_id'), (int) $et->id);
            $nbLignes = collect($lignes)->sum(fn ($l) => 1 + $l['matieres']->count());
            $numero = $registre->enregistrer($et, $pv, $et->mention, $et->moyenne_generale);
            $journal->action('creer', 'releve', "Relevé généré : $numero — {$et->nom} {$et->prenoms}", (int) $et->id);

            return ['et' => $et, 'lignes' => $lignes, 'numero' => $numero, 'urlVerification' => $menu->lien('verifier_diplome', ['num' => $numero]),
                'taille' => $nbLignes >= 35 ? 'tres-compact' : ($nbLignes >= 25 ? 'compact' : '')];
        });

        return view('notes.releve', [
            'pv' => $pv,
            'masse' => $masse,
            'releves' => $releves,
            'faculte' => $libelles->faculte ?? '',
            'departement' => $libelles->departement ?? '',
            'logos' => $identite->logos($pv->faculte_id ? (int) $pv->faculte_id : null),
            'signataire' => $identite->signataire($pv->faculte_id ? (int) $pv->faculte_id : null),
            'republique' => $parametres->valeur('institution_republique', 'REPUBLIQUE DU NIGER'),
            'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
            'bp' => $parametres->valeur('institution_bp', 'BP : 255 Tahoua-Niger'),
            'ville' => $parametres->valeur('institution_ville', 'Tahoua'),
        ]);
    }
}
