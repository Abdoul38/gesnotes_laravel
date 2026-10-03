<?php

namespace App\Http\Controllers\Portails;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Models\ParentEleve;
use App\Models\Utilisateur;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Comptes parents — portage de pages/comptes/gestion_parents.php (admin,
 * secrétaire, sc_centrale, sc_entite, doyen, chef de département ; ancien
 * hasRole) : création, modification, (dés)activation, enfants liés.
 *
 * Différences voulues :
 *  - un agent limité à une faculté (ou un département) ne lie que des
 *    étudiants de son périmètre : il pouvait lier n'importe quel étudiant à
 *    un compte créé avec son propre numéro et lire ses notes, paiements et
 *    présences ;
 *  - il ne voit que les parents ayant un enfant dans son périmètre (ou aucun
 *    enfant), et ne modifie, réinitialise ou désactive qu'un compte dont tous
 *    les enfants sont dans son périmètre ;
 *  - délier et (dés)activer en POST avec jeton CSRF (simples liens avant) ;
 *    délier vérifie que le lien appartient bien au parent ;
 *  - mot de passe : 8 caractères au moins, lettres et chiffres (4 avant) ;
 *    e-mail et lien contrôlés ; actions journalisées ; recherche d'étudiant
 *    affichée sans HTML.
 */
class GestionParentsController extends Controller
{
    private const LIENS = ['pere' => 'Père', 'mere' => 'Mère', 'tuteur' => 'Tuteur', 'parent' => 'Autre'];

    public function __construct(private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $q = trim((string) $request->query('q', ''));
        $parents = $this->visibles($u)
            ->when($q !== '', fn ($r) => $r->where(fn ($w) => $w->where('p.nom', 'like', "%$q%")->orWhere('p.prenoms', 'like', "%$q%")->orWhere('p.telephone', 'like', "%$q%")))
            ->orderByDesc('p.created_at')->limit(500)
            ->get(['p.*', DB::raw('(SELECT COUNT(*) FROM parents_etudiants pe WHERE pe.parent_id = p.id) AS nb_enfants')]);
        $choisi = (int) $request->query('parent_id') ? $this->visibles($u)->where('p.id', (int) $request->query('parent_id'))->first(['p.*']) : null;

        return view('portails.gestion_parents', [
            'parents' => $parents,
            'q' => $q,
            'choisi' => $choisi,
            'modifiable' => $choisi ? $this->modifiable($u, (int) $choisi->id) : false,
            'enfants' => $choisi ? DB::table('parents_etudiants as pe')->join('etudiants as e', 'e.id', '=', 'pe.etudiant_id')
                ->where('pe.parent_id', $choisi->id)->orderBy('e.nom')->get(['pe.id as lien_id', 'pe.lien', 'e.id as etudiant_id', 'e.nom', 'e.prenoms', 'e.numero']) : collect(),
            'liens' => self::LIENS,
        ]);
    }

    public function creer(Request $request): RedirectResponse
    {
        $d = $this->valider($request, true);
        if ($d instanceof RedirectResponse) {
            return $d;
        }
        if (ParentEleve::where('telephone', $d['telephone'])->exists()) {
            return redirect()->route('gestion_parents')->with('erreur', 'Ce numéro de téléphone est déjà utilisé par un autre compte parent.');
        }
        $parent = ParentEleve::create(['nom' => $d['nom'], 'prenoms' => $d['prenoms'], 'telephone' => $d['telephone'], 'email' => $d['email'],
            'mot_de_passe_hash' => $d['mot_de_passe'], 'actif' => 1]);
        $this->journal->action('creer', 'parent', "Compte parent créé : {$d['prenoms']} {$d['nom']} ({$d['telephone']})", (int) $parent->id);

        return redirect()->route('gestion_parents', ['parent_id' => $parent->id])->with('succes', "Compte parent créé pour {$d['prenoms']} {$d['nom']}.");
    }

    public function modifier(Request $request): RedirectResponse
    {
        $parent = $this->parentModifiable($request);
        if ($parent instanceof RedirectResponse) {
            return $parent;
        }
        $d = $this->valider($request, false, $parent->id);
        if ($d instanceof RedirectResponse) {
            return $d;
        }
        if (ParentEleve::where('telephone', $d['telephone'])->where('id', '!=', $parent->id)->exists()) {
            return redirect()->route('gestion_parents', ['parent_id' => $parent->id])->with('erreur', 'Ce numéro de téléphone est déjà utilisé par un autre compte.');
        }
        $parent->fill(['nom' => $d['nom'], 'prenoms' => $d['prenoms'], 'telephone' => $d['telephone'], 'email' => $d['email']]);
        $reinitialise = ($d['nouveau_mot_de_passe'] ?? '') !== '';
        if ($reinitialise) {
            $parent->mot_de_passe_hash = $d['nouveau_mot_de_passe'];
        }
        $parent->save();
        $this->journal->action('modifier', 'parent', "Compte parent modifié : {$d['prenoms']} {$d['nom']}".($reinitialise ? ' (mot de passe réinitialisé)' : ''), (int) $parent->id);

        return redirect()->route('gestion_parents', ['parent_id' => $parent->id])
            ->with('succes', $reinitialise ? 'Informations mises à jour et mot de passe réinitialisé.' : 'Informations mises à jour.');
    }

    public function basculer(Request $request): RedirectResponse
    {
        $parent = $this->parentModifiable($request);
        if ($parent instanceof RedirectResponse) {
            return $parent;
        }
        $parent->actif = ! $parent->actif;
        $parent->save();
        $this->journal->action($parent->actif ? 'activer' : 'desactiver', 'parent', "Compte parent : {$parent->prenoms} {$parent->nom}", (int) $parent->id);

        return redirect()->route('gestion_parents', ['parent_id' => $parent->id])->with('succes', 'Statut du compte modifié.');
    }

    public function lier(Request $request): RedirectResponse
    {
        $u = $request->user();
        $parent = $this->visibles($u)->where('p.id', (int) $request->input('parent_id'))->first(['p.id']);
        $etudiantId = (int) $request->input('etudiant_id');
        $lien = (string) $request->input('lien', 'parent');
        if (! $parent) {
            return redirect()->route('gestion_parents')->with('erreur', 'Compte parent introuvable.');
        }
        $retour = redirect()->route('gestion_parents', ['parent_id' => $parent->id]);
        if (! isset(self::LIENS[$lien]) || ! $this->perimetre->limiterEtudiants(DB::table('etudiants as e')->where('e.id', $etudiantId), $u)->exists()) {
            return $retour->with('erreur', 'Choisissez un étudiant de votre périmètre dans la liste.');
        }
        if (DB::table('parents_etudiants')->where(['parent_id' => $parent->id, 'etudiant_id' => $etudiantId])->exists()) {
            return $retour->with('erreur', 'Cet étudiant est déjà lié à ce compte parent.');
        }
        DB::table('parents_etudiants')->insert(['parent_id' => $parent->id, 'etudiant_id' => $etudiantId, 'lien' => $lien]);
        $this->journal->action('lier', 'parent', "Étudiant #$etudiantId lié au compte parent #{$parent->id} ($lien)", (int) $parent->id);

        return $retour->with('succes', 'Enfant lié au compte parent.');
    }

    public function delier(Request $request): RedirectResponse
    {
        $u = $request->user();
        $parentId = (int) $request->input('parent_id');
        $lien = DB::table('parents_etudiants')->where(['id' => (int) $request->input('lien_id'), 'parent_id' => $parentId])->first();
        $retour = redirect()->route('gestion_parents', ['parent_id' => $parentId]);
        if (! $lien || ! $this->perimetre->limiterEtudiants(DB::table('etudiants as e')->where('e.id', $lien->etudiant_id), $u)->exists()) {
            return $retour->with('erreur', 'Lien introuvable.');
        }
        DB::table('parents_etudiants')->where('id', $lien->id)->delete();
        $this->journal->action('delier', 'parent', "Étudiant #{$lien->etudiant_id} délié du compte parent #$parentId", $parentId);

        return $retour->with('succes', 'Enfant délié du compte.');
    }

    /** Parents visibles : un enfant dans le périmètre, ou aucun enfant (tous pour un compte sans restriction). */
    private function visibles(Utilisateur $u)
    {
        $q = DB::table('parents as p');
        if (! $this->restreint($u)) {
            return $q;
        }

        return $q->where(fn ($w) => $w
            ->whereNotExists(fn ($s) => $s->from('parents_etudiants as pe0')->whereColumn('pe0.parent_id', 'p.id'))
            ->orWhereExists(fn ($s) => $this->perimetre->limiterEtudiants(
                $s->from('parents_etudiants as pe1')->join('etudiants as e', 'e.id', '=', 'pe1.etudiant_id')->whereColumn('pe1.parent_id', 'p.id'), $u)));
    }

    /** Modifiable : tous les enfants du compte sont dans le périmètre. */
    private function modifiable(Utilisateur $u, int $parentId): bool
    {
        if (! $this->restreint($u)) {
            return true;
        }
        $enfants = DB::table('parents_etudiants')->where('parent_id', $parentId)->pluck('etudiant_id');

        return $enfants->isEmpty()
            || $this->perimetre->limiterEtudiants(DB::table('etudiants as e')->whereIn('e.id', $enfants), $u)->count() === $enfants->count();
    }

    private function restreint(Utilisateur $u): bool
    {
        return $this->perimetre->faculte($u) > 0 || ($u->role === 'chef_departement' && $u->departementId() > 0);
    }

    private function parentModifiable(Request $request): ParentEleve|RedirectResponse
    {
        $parent = ParentEleve::find((int) $request->input('parent_id'));
        if (! $parent || ! $this->visibles($request->user())->where('p.id', $parent->id)->exists()) {
            return redirect()->route('gestion_parents')->with('erreur', 'Compte parent introuvable.');
        }
        if (! $this->modifiable($request->user(), (int) $parent->id)) {
            return redirect()->route('gestion_parents', ['parent_id' => $parent->id])
                ->with('erreur', "Ce compte est aussi lié à un étudiant hors de votre périmètre : seule la scolarité centrale peut le modifier.");
        }

        return $parent;
    }

    private function valider(Request $request, bool $creation, ?int $parentId = null): array|RedirectResponse
    {
        $request->merge(['telephone' => preg_replace('/[^0-9+]/', '', (string) $request->input('telephone'))]);
        $v = Validator::make($request->all(), [
            'nom' => 'required|string|max:100', 'prenoms' => 'required|string|max:100',
            'telephone' => ['required', 'regex:/^\+?\d{6,20}$/'], 'email' => 'nullable|email|max:150',
            'mot_de_passe' => $creation ? ['required', Password::min(8)->letters()->numbers()] : 'nullable',
            'nouveau_mot_de_passe' => ['nullable', Password::min(8)->letters()->numbers()],
        ], ['required' => 'Nom, prénoms, téléphone'.($creation ? ' et mot de passe' : '').' sont requis.', 'telephone.regex' => 'Numéro de téléphone invalide.'],
            ['mot_de_passe' => 'mot de passe', 'nouveau_mot_de_passe' => 'nouveau mot de passe']);
        if ($v->fails()) {
            return redirect()->route('gestion_parents', $parentId ? ['parent_id' => $parentId] : [])->with('erreur', $v->errors()->first());
        }
        $d = $v->validated();
        $d['nom'] = trim($d['nom']);
        $d['prenoms'] = trim($d['prenoms']);
        $d['email'] = trim($d['email'] ?? '') ?: null;

        return $d;
    }
}
