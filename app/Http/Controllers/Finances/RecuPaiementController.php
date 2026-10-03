<?php

namespace App\Http\Controllers\Finances;

use App\Http\Controllers\Controller;
use App\Navigation\Menu;
use App\Services\Identite;
use App\Services\Paiements;
use App\Services\Parametres;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Reçu d'un paiement (A5) — portage de pages/finances/recu_paiement.php.
 * Accès (repris) : admin, secrétaire, sc_centrale, sc_entite avec la
 * permission « paiements_voir » ; l'étudiant pour ses propres paiements.
 *
 * Différence voulue : un agent de faculté ne voit que les reçus des
 * étudiants de sa faculté (tous avant).
 */
class RecuPaiementController extends Controller
{
    public function __invoke(Request $request, Paiements $paiements, Identite $identite, Parametres $parametres, Menu $menu): View|RedirectResponse
    {
        $u = $request->user();
        $id = (int) $request->query('id');
        if ($u->role === 'etudiant') {
            $p = DB::table('paiements as p')->join('etudiants as e', 'e.id', '=', 'p.etudiant_id')
                ->where('p.id', $id)->where('p.etudiant_id', (int) $u->etudiant_id)->first(['p.*', 'e.nom', 'e.prenoms', 'e.numero']);
            $retour = redirect($menu->lien('portail_etudiant'));
        } else {
            if (! (in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite'], true) && $u->autorise('paiements_voir'))) {
                return redirect()->route('accueil')->with('erreur', 'Accès non autorisé.');
            }
            $p = $paiements->trouver($u, $id);
            $retour = redirect()->route('paiements');
        }
        if (! $p) {
            return $retour->with('erreur', 'Paiement introuvable.');
        }
        $ins = DB::table('inscriptions as i')->join('promotions as pr', 'pr.id', '=', 'i.promotion_id')->join('filieres as f', 'f.id', '=', 'pr.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'pr.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'pr.annee_id')
            ->where('i.etudiant_id', $p->etudiant_id)->where('i.statut', 'inscrit')->orderByDesc('a.libelle')
            ->first(['f.libelle as filiere', 'f.faculte_id', 'n.code as niveau', 'a.libelle as annee']);

        return view('finances.recu_paiement', [
            'p' => $p,
            'ins' => $ins,
            'logos' => $identite->logos($ins?->faculte_id ? (int) $ins->faculte_id : null),
            'institution' => $parametres->valeur('institution_nom', 'UNIVERSITÉ DJIBO HAMANI DE TAHOUA'),
            'service' => $parametres->valeur('institution_service', 'Service de la Scolarité'),
            'bp' => $parametres->valeur('institution_bp', 'BP : 255 Tahoua-Niger'),
        ]);
    }
}
