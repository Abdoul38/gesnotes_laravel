<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Administration\RolesController;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * « Mon compte » (clic sur son nom, en bas du menu) : profil de l'utilisateur
 * connecté et changement de son mot de passe — nouveau, pour tous les rôles
 * (seul le rectorat avait un formulaire).
 *
 * Sécurité : mot de passe actuel exigé, 5 essais faux par quart d'heure et
 * par compte, nouveau mot de passe de 8 caractères minimum avec lettres et
 * chiffres, différent de l'actuel (et du matricule pour un étudiant) ;
 * session renouvelée et changement journalisé.
 */
class MonCompteController extends Controller
{
    private const ESSAIS_MAX = 5;

    public function __construct(private readonly Journal $journal) {}

    public function afficher(Request $request): View
    {
        $u = $request->user();

        return view('auth.mon_compte', [
            'u' => $u,
            'role' => RolesController::ROLES[$u->role] ?? ['⚫', $u->role, '#f3f4f6', '#374151'],
            'faculte' => $u->faculte,
            'departement' => $u->departement,
            'matricule' => $u->etudiant_id ? DB::table('etudiants')->where('id', $u->etudiant_id)->value('numero') : null,
            'enseignant' => $u->enseignant_id ? DB::table('enseignants')->where('id', $u->enseignant_id)->first() : null,
        ]);
    }

    public function changerMotDePasse(Request $request): RedirectResponse
    {
        $u = $request->user();
        $retour = redirect()->route('mon_compte');
        $cle = 'mon_compte|'.$u->id;
        if (RateLimiter::tooManyAttempts($cle, self::ESSAIS_MAX)) {
            return $retour->with('erreur', 'Trop d\'essais : réessayez dans '.ceil(RateLimiter::availableIn($cle) / 60).' minute(s).');
        }
        if (! Hash::check((string) $request->input('mot_de_passe_actuel'), $u->mot_de_passe)) {
            RateLimiter::hit($cle, 900);

            return $retour->with('erreur', 'Mot de passe actuel incorrect.');
        }
        $nouveau = (string) $request->input('mot_de_passe');
        $v = Validator::make($request->all(), ['mot_de_passe' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)->letters()->numbers()]], [
            'mot_de_passe.confirmed' => 'Les deux nouveaux mots de passe ne correspondent pas.',
            'mot_de_passe.*' => 'Le nouveau mot de passe doit contenir au moins 8 caractères, avec des lettres et des chiffres.',
        ]);
        if ($v->fails()) {
            return $retour->with('erreur', $v->errors()->first());
        }
        $matricule = $u->etudiant_id ? (string) DB::table('etudiants')->where('id', $u->etudiant_id)->value('numero') : '';
        if (Hash::check($nouveau, $u->mot_de_passe) || ($matricule !== '' && $nouveau === $matricule)) {
            return $retour->with('erreur', 'Choisissez un mot de passe différent de l\'actuel'.($matricule !== '' ? ' et de votre matricule.' : '.'));
        }

        RateLimiter::clear($cle);
        $u->mot_de_passe = $nouveau; // haché par le modèle (cast « hashed »)
        $u->save();
        $request->session()->regenerate();
        $this->journal->action('modifier', 'utilisateur', 'Changement de son mot de passe (Mon compte)', (int) $u->id);

        return $retour->with('succes', 'Votre mot de passe a été modifié.');
    }
}
