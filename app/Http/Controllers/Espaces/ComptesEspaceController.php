<?php

namespace App\Http\Controllers\Espaces;

use App\Espaces\Espaces;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Comptes d'un espace (Ministère : admin / lecteur ; ANAB : admin / agent),
 * réservé à ses administrateurs — portage des pages gerer_comptes et
 * gerer_comptes_anab de l'ancien index.php.
 *
 * Différences voulues : rôle vérifié (n'importe quelle valeur était
 * envoyée à la base) ; mot de passe avec lettres et chiffres, saisi dans un
 * champ masqué (il s'affichait en clair) ; on ne peut désactiver ni son
 * propre compte ni le dernier administrateur actif (en plus du compte
 * principal « mesr » / « anab », déjà protégé).
 */
class ComptesEspaceController extends Controller
{
    public function index(Request $request): View
    {
        $config = $this->config($request);

        return view('espaces.comptes', [
            'comptes' => $config['modele']::orderByDesc('actif')->orderBy('created_at')->get(),
            'roles' => $config['roles'],
            'protege' => $config['protege'],
        ]);
    }

    public function creer(Request $request): RedirectResponse
    {
        $config = $this->config($request);
        $v = Validator::make($request->all(), [
            // Classe du modèle : l'unicité est vérifiée dans la base des comptes (base propre aux espaces le cas échéant)
            'login' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique($config['modele'], 'login')],
            'nom' => ['required', 'string', 'max:100'],
            'role' => ['required', 'in:'.implode(',', array_keys($config['roles']))],
            'mdp' => ['required', 'string', 'max:255', Password::min(8)->letters()->numbers()],
        ], [
            'login.unique' => 'Login déjà utilisé.', 'login.regex' => 'Login : lettres, chiffres, point, tiret ou souligné.',
            'role.*' => 'Rôle invalide.', 'mdp.*' => 'Mot de passe : 8 caractères minimum, avec des lettres et des chiffres.',
            'required' => 'Tous les champs sont requis.',
        ]);
        if ($v->fails()) {
            return $this->retour($request, 'erreur', $v->errors()->first());
        }
        $config['modele']::create([
            'login' => trim((string) $request->input('login')), 'nom_complet' => trim((string) $request->input('nom')),
            'role' => $request->input('role'), 'mot_de_passe' => (string) $request->input('mdp'), 'actif' => 1,
        ]);

        return $this->retour($request, 'succes', 'Compte créé.');
    }

    public function basculer(Request $request): RedirectResponse
    {
        $config = $this->config($request);
        $compte = $config['modele']::find((int) $request->input('compte_id'));
        if (! $compte) {
            return $this->retour($request, 'erreur', 'Compte introuvable.');
        }
        if ($compte->actif) {
            if ($compte->login === $config['protege'] || $compte->id === $this->moi($request)->id) {
                return $this->retour($request, 'erreur', 'Ce compte ne peut pas être désactivé.');
            }
            if ($compte->estAdmin() && ! $config['modele']::where('role', 'admin')->where('actif', 1)->where('id', '!=', $compte->id)->exists()) {
                return $this->retour($request, 'erreur', 'Impossible : c\'est le dernier administrateur actif.');
            }
        }
        $compte->actif = ! $compte->actif;
        $compte->save();

        return $this->retour($request, 'succes', $compte->actif ? 'Compte activé.' : 'Compte désactivé.');
    }

    public function reinitialiser(Request $request): RedirectResponse
    {
        $config = $this->config($request);
        $compte = $config['modele']::find((int) $request->input('compte_id'));
        if (! $compte) {
            return $this->retour($request, 'erreur', 'Compte introuvable.');
        }
        $v = Validator::make($request->all(), ['nouveau_mdp' => ['required', 'string', 'max:255', Password::min(8)->letters()->numbers()]],
            ConnexionEspaceController::MESSAGES_MDP);
        if ($v->fails()) {
            return $this->retour($request, 'erreur', $v->errors()->first());
        }
        $compte->mot_de_passe = (string) $request->input('nouveau_mdp');
        $compte->save();

        return $this->retour($request, 'succes', 'Mot de passe réinitialisé.');
    }

    private function config(Request $request): array
    {
        return Espaces::config(Espaces::courant($request));
    }

    private function moi(Request $request): object
    {
        return Auth::guard($this->config($request)['garde'])->user();
    }

    private function retour(Request $request, string $type, string $message): RedirectResponse
    {
        return redirect()->route(Espaces::courant($request).'.comptes')->with($type, $message);
    }
}
