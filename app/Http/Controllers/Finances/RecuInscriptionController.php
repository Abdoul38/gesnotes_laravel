<?php

namespace App\Http\Controllers\Finances;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Navigation\Menu;
use App\Services\Identite;
use App\Services\Parametres;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Reçu d'inscription (B5) — portage de pages/finances/recu_inscription.php,
 * pour une inscription donnée (ins_id) ou la dernière inscription en cours.
 * Accès (repris) : admin, secrétaire, sc_centrale, sc_entite avec la
 * permission « paiements_voir » ; l'étudiant pour lui-même.
 *
 * Différences voulues :
 *  - seulement pour une inscription « inscrit » : l'ancien reçu déclarait
 *    l'étudiant « officiellement inscrit » même pour une inscription
 *    annulée, ou sans aucune inscription ;
 *  - inscription de la faculté de l'agent ;
 *  - numéro du reçu propre à l'inscription (il ne dépendait que de
 *    l'étudiant : deux années avaient le même numéro) ;
 *  - nom de l'université tiré des paramètres (« Université Djibo Hamani de
 *    Tahoua (UDH) » et une faculté par défaut étaient écrits en dur).
 */
class RecuInscriptionController extends Controller
{
    public function __invoke(Request $request, Perimetre $perimetre, Identite $identite, Parametres $parametres, Menu $menu): View|RedirectResponse
    {
        $u = $request->user();
        $estEtudiant = $u->role === 'etudiant';
        if (! $estEtudiant && ! (in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite'], true) && $u->autorise('paiements_voir'))) {
            return redirect()->route('accueil')->with('erreur', 'Accès non autorisé.');
        }
        $etId = $estEtudiant ? (int) $u->etudiant_id : (int) $request->query('et_id');
        $insId = (int) $request->query('ins_id');
        $faculte = $estEtudiant ? 0 : $perimetre->faculte($u);

        $et = DB::table('etudiants')->find($etId);
        $ins = $et ? DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->where('i.etudiant_id', $etId)->where('i.statut', 'inscrit')
            ->when($insId, fn ($q) => $q->where('i.id', $insId), fn ($q) => $q->orderByDesc('a.libelle'))
            ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
            ->first(['i.id', 'i.montant_paye', 'i.created_at', 'i.date_inscription', 'f.libelle as filiere', 'f.faculte_id',
                DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'), 'n.libelle as niveau', 'a.libelle as annee']) : null;
        if (! $ins) {
            return ($estEtudiant ? redirect($menu->lien('portail_etudiant')) : redirect($menu->lien('inscription_scolarite')))
                ->with('erreur', 'Inscription introuvable (seule une inscription en cours donne un reçu).');
        }

        return view('finances.recu_inscription', [
            'et' => $et,
            'ins' => $ins,
            'numero' => 'INS-'.substr((string) $ins->annee, 0, 4).'-'.str_pad((string) $ins->id, 6, '0', STR_PAD_LEFT),
            'logos' => $identite->logos($ins->faculte_id ? (int) $ins->faculte_id : null),
            'p' => fn (string $cle, string $defaut = '') => $parametres->valeur($cle, $defaut),
        ]);
    }
}
