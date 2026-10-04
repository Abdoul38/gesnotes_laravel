<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Premier mot de passe d'un étudiant : obligatoire à la première connexion
 * (mot de passe provisoire remis à la génération du compte) ou tant que le
 * mot de passe est encore le matricule (ancienne règle). Tant qu'il n'est
 * pas changé, SessionActive ne laisse accéder qu'à cette page.
 */
class MotDePasseInitialController extends Controller
{
    public function formulaire(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('mdp_a_changer')) {
            return redirect()->route('accueil');
        }

        return view('auth.mot_de_passe_initial');
    }

    public function enregistrer(Request $request, Journal $journal): RedirectResponse
    {
        if (! $request->session()->get('mdp_a_changer')) {
            return redirect()->route('accueil');
        }
        $u = $request->user();
        $matricule = (string) DB::table('etudiants')->where('id', (int) $u->etudiant_id)->value('numero');
        $nouveau = (string) $request->input('mot_de_passe');
        $v = Validator::make($request->all(), ['mot_de_passe' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)->letters()->numbers()]], [
            'mot_de_passe.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'mot_de_passe.*' => 'Le mot de passe doit contenir au moins 8 caractères, avec des lettres et des chiffres.',
        ]);
        if ($v->fails()) {
            return back()->with('erreur', $v->errors()->first());
        }
        if (($matricule !== '' && $nouveau === $matricule) || Hash::check($nouveau, $u->mot_de_passe)) {
            return back()->with('erreur', 'Choisissez un mot de passe différent de votre matricule et du mot de passe provisoire.');
        }
        DB::table('utilisateurs')->where('id', $u->id)->update(['mot_de_passe' => Hash::make($nouveau)]);
        $request->session()->forget('mdp_a_changer');
        $request->session()->regenerate();
        $journal->action('modifier', 'auth', 'Premier mot de passe choisi', (int) $u->id);

        return redirect()->route($u->role === 'etudiant' ? 'portail_etudiant' : 'accueil')->with('succes', 'Votre mot de passe est enregistré.');
    }
}
