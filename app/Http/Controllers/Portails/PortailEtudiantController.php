<?php

namespace App\Http\Controllers\Portails;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Journal;
use App\Services\Pv;
use App\Services\Releves;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Portail étudiant — portage de pages/etudiants/portail_etudiant.php :
 * l'étudiant consulte ses résultats par semestre et le détail de ses notes,
 * et met à jour ses coordonnées ; le personnel (admin, secrétaire,
 * sc_centrale, sc_entite, doyen, chef de département) consulte le portail
 * d'un étudiant.
 *
 * Différences voulues :
 *  - l'étudiant ne voit que les PV publiés : les PV seulement « finalisés »
 *    (avant publication et avant l'annonce « résultats disponibles »)
 *    étaient déjà visibles ;
 *  - résultats et observations enregistrés par le moteur (la page refaisait
 *    son propre calcul de défaillance « en direct ») ;
 *  - personnel : étudiants de son périmètre (un sc_entite consultait toute
 *    l'université ; seuls doyen et chef de département étaient limités) ;
 *  - changement de l'e-mail de connexion ou du mot de passe : mot de passe
 *    actuel exigé, nouveau mot de passe d'au moins 8 caractères avec lettres
 *    et chiffres, e-mail contrôlé et unique ; un mot de passe non confirmé
 *    était ignoré en affichant « Profil mis à jour » ;
 *  - onglets « Dossier » et « Carte » réservés au personnel (pour un
 *    étudiant ils renvoyaient aussitôt sur le portail).
 */
class PortailEtudiantController extends Controller
{
    private const PERSONNEL = ['admin', 'secretaire', 'sc_centrale', 'sc_entite', 'doyen', 'chef_departement'];

    public function __construct(private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    public function index(Request $request, Pv $pvs, Releves $releves): View|RedirectResponse
    {
        $u = $request->user();
        $personnel = $this->personnel($u);
        if (! $personnel && $u->role !== 'etudiant') {
            return redirect()->route('accueil')->with('erreur', 'Accès non autorisé.');
        }
        $onglet = in_array($request->query('tab'), ['notes', 'dossier', 'carte', 'profil'], true) ? $request->query('tab') : 'notes';
        if (! $personnel && in_array($onglet, ['dossier', 'carte'], true)) {
            $onglet = 'notes';
        }

        $etId = $personnel ? (int) $request->query('et_id') : (int) $u->etudiant_id;
        $requete = DB::table('etudiants as e')->where('e.id', $etId);
        $et = $etId ? (($personnel ? $this->perimetre->limiterEtudiants($requete, $u) : $requete)->first(['e.*'])) : null;
        if ($personnel && $etId && ! $et) {
            return redirect()->route('portail_etudiant')->with('erreur', 'Cet étudiant ne relève pas de votre périmètre.');
        }
        $donnees = ['personnel' => $personnel, 'onglet' => $onglet, 'et' => $et, 'q' => trim((string) $request->query('q', '')), 'resultats' => collect()];

        if ($personnel && ! $et && $donnees['q'] !== '') {
            $q = $donnees['q'];
            $donnees['resultats'] = $this->perimetre->limiterEtudiants(DB::table('etudiants as e'), $u)
                ->where(fn ($w) => $w->where('e.nom', 'like', "%$q%")->orWhere('e.prenoms', 'like', "%$q%")->orWhere('e.numero', 'like', "%$q%"))
                ->orderBy('e.nom')->limit(10)->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero']);
        }
        if (! $et) {
            return view('portails.etudiant', $donnees + ['historique' => collect(), 'pv' => null, 'resultat' => null, 'lignes' => []]);
        }
        if ($request->isMethod('get') && ! $request->has('pv_id')) {
            $this->journal->action('consulter', 'etudiant', 'Consultation portail étudiant'.($personnel ? ' (vue admin)' : ''), (int) $et->id);
        }

        // Semestres : un rattrapage n'est montré que si la session normale n'a pas été validée (ancienne règle)
        $lignes = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->where('rs.etudiant_id', $et->id)->when(! $personnel, fn ($q) => $q->where('ps.statut', 'publie'))
            ->orderByDesc('a.libelle')->orderBy('s.code')->orderBy('ps.type_session')
            ->get(['ps.id as pv_id', 'ps.type_session', 'a.libelle as annee', 's.libelle as semestre', 's.code as sem_code',
                'rs.moyenne_generale', 'rs.mention', 'rs.decision', 'rs.valide', 'rs.credits_obtenus']);
        $historique = $lignes->groupBy(fn ($l) => $l->annee.'|'.$l->sem_code)
            ->flatMap(fn ($sessions) => $sessions->contains(fn ($s) => $s->type_session === 'normale' && $s->valide)
                ? $sessions->where('type_session', 'normale') : $sessions)
            ->groupBy('annee');

        $pv = null;
        $resultat = null;
        $detail = [];
        $pvId = (int) $request->query('pv_id');
        if ($pvId && $lignes->contains('pv_id', $pvId)) {
            $pv = $pvs->trouver($pvId);
            $resultat = DB::table('resultats_semestre')->where(['pv_id' => $pvId, 'etudiant_id' => $et->id])->first();
            $notes = DB::table('notes')->where(['pv_id' => $pvId, 'etudiant_id' => $et->id])->get()->keyBy('matiere_id');
            $detail = $releves->lignes($pvs->ues($pv), $pvs->choixOptions($pv), $notes, (int) $et->id);
        }

        return view('portails.etudiant', $donnees + ['historique' => $historique, 'pvId' => $pvId, 'pv' => $pv, 'resultat' => $resultat, 'lignes' => $detail,
            'nbSemestres' => $historique->flatten(1)->count(), 'emailCompte' => $personnel ? null : $u->email]);
    }

    /** Coordonnées, e-mail de connexion, mot de passe — l'étudiant lui-même. */
    public function profil(Request $request): RedirectResponse
    {
        $u = $request->user();
        $retour = redirect()->route('portail_etudiant', ['tab' => 'profil']);
        if ($u->role !== 'etudiant' || ! $u->etudiant_id) {
            return $retour->with('erreur', 'Accès non autorisé.');
        }
        $v = Validator::make($request->all(), [
            'telephone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ ().-]*$/'],
            'adresse' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:150',
            'mot_de_passe_actuel' => 'nullable|string',
            'mdp' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ], ['mdp.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.'],
            ['telephone' => 'téléphone', 'mdp' => 'nouveau mot de passe']);
        if ($v->fails()) {
            return $retour->with('erreur', $v->errors()->first());
        }
        $d = $v->validated();
        $email = trim($d['email'] ?? '');
        $changeEmail = $email !== '' && mb_strtolower($email) !== mb_strtolower($u->email);
        $changeMdp = ($d['mdp'] ?? '') !== '';
        if (($changeEmail || $changeMdp) && ! Hash::check((string) ($d['mot_de_passe_actuel'] ?? ''), $u->mot_de_passe)) {
            return $retour->with('erreur', "Mot de passe actuel incorrect : l'e-mail et le mot de passe n'ont pas été modifiés.");
        }
        if ($changeEmail && Utilisateur::where('email', $email)->where('id', '!=', $u->id)->exists()) {
            return $retour->with('erreur', 'Cette adresse e-mail est déjà utilisée par un autre compte.');
        }

        DB::table('etudiants')->where('id', $u->etudiant_id)->update(['telephone' => trim($d['telephone'] ?? '') ?: null, 'adresse' => trim($d['adresse'] ?? '') ?: null]);
        if ($changeEmail) {
            $u->email = $email;
        }
        if ($changeMdp) {
            $u->mot_de_passe = $d['mdp'];
        }
        $u->save();
        if ($changeMdp) {
            $request->session()->regenerate();
        }
        $this->journal->action('modifier', 'etudiant', 'Mise à jour profil (portail étudiant)'
            .($changeEmail ? ', e-mail de connexion changé' : '').($changeMdp ? ', mot de passe changé' : ''), (int) $u->etudiant_id);

        return $retour->with('succes', $changeMdp ? 'Profil et mot de passe mis à jour.' : 'Profil mis à jour.');
    }

    private function personnel(Utilisateur $u): bool
    {
        return in_array($u->role, self::PERSONNEL, true) && $u->autorise('portail_etudiant');
    }
}
