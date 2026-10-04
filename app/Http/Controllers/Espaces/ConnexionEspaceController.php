<?php

namespace App\Http\Controllers\Espaces;

use App\Espaces\Espaces;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Connexion, déconnexion et mot de passe des espaces Ministère et ANAB —
 * portage des blocs IS_MINISTERE / IS_ANAB de l'ancien index.php.
 *
 * Différences voulues : déconnexion en POST ; 5 essais par identifiant et
 * adresse puis 5 min d'attente ; nouveau mot de passe avec lettres et
 * chiffres. (L'ancien formulaire de connexion écrivait son jeton CSRF dans
 * l'attribut action : le jeton n'était jamais envoyé et la connexion
 * répondait « Session expirée ».)
 */
class ConnexionEspaceController extends Controller
{
    private const ESSAIS = 5;

    private const BLOCAGE_SECONDES = 300;

    public const MESSAGES_MDP = [
        'nouveau_mdp.required' => 'Mot de passe : 8 caractères minimum, avec des lettres et des chiffres.',
        'nouveau_mdp.min' => 'Mot de passe : 8 caractères minimum, avec des lettres et des chiffres.',
        'nouveau_mdp.letters' => 'Mot de passe : 8 caractères minimum, avec des lettres et des chiffres.',
        'nouveau_mdp.numbers' => 'Mot de passe : 8 caractères minimum, avec des lettres et des chiffres.',
    ];

    public function formulaire(Request $request): View|RedirectResponse
    {
        $code = Espaces::courant($request);
        if ($this->compte($request)) {
            return redirect()->route("$code.accueil");
        }

        return view('espaces.connexion');
    }

    public function connecter(Request $request): RedirectResponse
    {
        $code = Espaces::courant($request);
        $config = Espaces::LISTE[$code];
        $login = mb_substr(trim((string) $request->input('user')), 0, 50);
        $cle = "espace:$code:".mb_strtolower($login).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($cle, self::ESSAIS)) {
            return back()->withInput($request->only('user'))
                ->with('erreur', 'Trop de tentatives. Réessayez dans '.ceil(RateLimiter::availableIn($cle) / 60).' minute(s).');
        }

        $compte = $config['modele']::where('login', $login)->where('actif', 1)->first();
        if (! $compte || ! Hash::check((string) $request->input('pass'), $compte->mot_de_passe)) {
            RateLimiter::hit($cle, self::BLOCAGE_SECONDES);
            Log::warning("Connexion $code échouée", ['login' => $login, 'ip' => $request->ip()]);

            return back()->withInput($request->only('user'))->with('erreur', 'Identifiants incorrects.');
        }
        RateLimiter::clear($cle);
        Auth::guard($config['garde'])->login($compte);
        $request->session()->regenerate();
        $request->session()->put("{$code}_activite", time());
        $compte->forceFill(['derniere_connexion' => now()])->save();

        return redirect()->route("$code.accueil");
    }

    public function deconnecter(Request $request): RedirectResponse
    {
        $code = Espaces::courant($request);
        Auth::guard(Espaces::LISTE[$code]['garde'])->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route("$code.connexion");
    }

    public function formulaireMotDePasse(): View
    {
        return view('espaces.mot_de_passe');
    }

    public function changerMotDePasse(Request $request): RedirectResponse
    {
        $compte = $this->compte($request);
        if (! Hash::check((string) $request->input('ancien_mdp'), $compte->mot_de_passe)) {
            return back()->with('erreur', 'Ancien mot de passe incorrect.');
        }
        $v = Validator::make($request->all(), ['nouveau_mdp' => ['required', 'string', 'max:255', 'same:confirmer_mdp', Password::min(8)->letters()->numbers()]],
            ['nouveau_mdp.same' => 'Les mots de passe ne correspondent pas.'] + self::MESSAGES_MDP);
        if ($v->fails()) {
            return back()->with('erreur', $v->errors()->first());
        }
        $compte->mot_de_passe = (string) $request->input('nouveau_mdp');
        $compte->save();

        return back()->with('succes', 'Mot de passe changé avec succès.');
    }

    private function compte(Request $request): ?object
    {
        return Auth::guard(Espaces::LISTE[Espaces::courant($request)]['garde'])->user();
    }
}
