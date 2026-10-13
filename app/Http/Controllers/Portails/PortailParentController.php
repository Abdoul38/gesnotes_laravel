<?php

namespace App\Http\Controllers\Portails;

use App\Http\Controllers\Controller;
use App\Services\Pv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Espace Parents — portage de pages/comptes/portail_parent.php : pour chaque
 * enfant lié, résultats par semestre, paiements, présence ; gestion du compte
 * (e-mail, mot de passe).
 *
 * Différences voulues :
 *  - résultats des seuls PV publiés (tous les PV, brouillons compris, étaient
 *    visibles avant la délibération) ;
 *  - formulaire du compte protégé par jeton CSRF (il n'en avait pas) ;
 *  - nouveau mot de passe : 8 caractères au moins, lettres et chiffres (4
 *    suffisaient) ; e-mail contrôlé ; déconnexion en POST.
 */
class PortailParentController extends Controller
{
    public function index(Request $request): View
    {
        $parent = Auth::guard('parent')->user();
        $enfants = DB::table('parents_etudiants as pe')->join('etudiants as e', 'e.id', '=', 'pe.etudiant_id')
            ->where('pe.parent_id', $parent->id)->orderBy('e.nom')->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero', 'pe.lien']);
        if ($enfants->isEmpty()) {
            return view('portails.parent', ['parent' => $parent, 'enfants' => $enfants, 'etudiant' => null]);
        }
        $etudiant = $enfants->firstWhere('id', (int) $request->query('et_id')) ?? $enfants->first();

        // Une ligne par (promotion, semestre) : le rattrapage remplace la session normale (ancienne règle)
        $resultats = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->where('rs.etudiant_id', $etudiant->id)->whereIn('ps.statut', Pv::CONSULTABLES)->whereIn('ps.type_session', ['normale', 'rattrapage'])
            ->orderByDesc('a.libelle')->orderByDesc('s.code')
            ->get(['rs.*', 's.code as sem_code', 'a.libelle as annee', 'f.libelle as filiere', 'n.code as niveau', 'ps.type_session', 'p.id as promotion_id'])
            ->groupBy(fn ($r) => $r->promotion_id.'-'.$r->sem_code)
            ->map(fn ($g) => $g->firstWhere('type_session', 'rattrapage') ?? $g->first())->values();
        $paiements = DB::table('paiements')->where('etudiant_id', $etudiant->id)->orderByDesc('date_paiement')->limit(20)->get();
        $presences = DB::table('presences as pr')->join('seances as se', 'se.id', '=', 'pr.seance_id')->join('matieres as m', 'm.id', '=', 'se.matiere_id')
            ->where('pr.etudiant_id', $etudiant->id)->orderByDesc('se.date_seance')->limit(30)
            ->get(['pr.statut', 'pr.motif', 'se.date_seance', 'se.type_seance', 'm.libelle as matiere']);

        return view('portails.parent', [
            'parent' => $parent, 'enfants' => $enfants, 'etudiant' => $etudiant, 'resultats' => $resultats,
            'paiements' => $paiements, 'totalPaye' => (float) $paiements->sum('montant_paye'), 'totalDu' => (float) $paiements->sum('montant_du'),
            'presences' => $presences,
            'tauxPresence' => $presences->isEmpty() ? null : (int) round($presences->where('statut', 'present')->count() / $presences->count() * 100),
        ]);
    }

    public function compte(Request $request): RedirectResponse
    {
        $parent = Auth::guard('parent')->user();
        $retour = redirect()->to(route('portail_parent').'#mon-compte');
        $v = Validator::make($request->all(), [
            'email' => 'nullable|email|max:150',
            'mot_de_passe_actuel' => 'required|string',
            'nouveau_mot_de_passe' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ], ['nouveau_mot_de_passe.confirmed' => 'La confirmation ne correspond pas au nouveau mot de passe.',
            'mot_de_passe_actuel.required' => 'Mot de passe actuel incorrect.'], ['nouveau_mot_de_passe' => 'nouveau mot de passe']);
        if ($v->fails()) {
            return $retour->with('erreur_compte', $v->errors()->first());
        }
        $d = $v->validated();
        if (! Hash::check($d['mot_de_passe_actuel'], $parent->mot_de_passe_hash)) {
            return $retour->with('erreur_compte', 'Mot de passe actuel incorrect.');
        }
        $parent->email = trim($d['email'] ?? '') ?: null;
        if (($d['nouveau_mot_de_passe'] ?? '') !== '') {
            $parent->mot_de_passe_hash = $d['nouveau_mot_de_passe'];
        }
        $parent->save();
        if (($d['nouveau_mot_de_passe'] ?? '') !== '') {
            $request->session()->regenerate();
        }

        return $retour->with('succes_compte', 'Informations mises à jour avec succès.');
    }
}
