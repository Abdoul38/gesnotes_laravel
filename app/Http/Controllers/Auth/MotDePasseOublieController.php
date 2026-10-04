<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Communication\Courriel;
use App\Services\Journal;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Mot de passe oublié du personnel — portage de pages/comptes/
 * mot_de_passe_oublie.php et reinitialiser_mot_de_passe.php : jeton à
 * usage unique (empreinte SHA-256 en base, table password_resets), valable
 * 1 heure, même message que l'adresse existe ou non.
 *
 * Différences voulues : limite de 5 demandes par adresse IP et par
 * 15 minutes tenue côté serveur (l'ancienne était en session : il suffisait
 * de supprimer le cookie) ; une nouvelle demande, ou un changement réussi,
 * annule les liens encore valides ; mot de passe de 8 caractères avec
 * lettres et chiffres (6 quelconques avant) ; prénom échappé dans l'e-mail.
 */
class MotDePasseOublieController extends Controller
{
    private const DEMANDES_MAX = 5;

    private const FENETRE_SECONDES = 900;

    public function __construct(private readonly UniversiteCourante $universite, private readonly Journal $journal) {}

    public function formulaire(Request $request): View
    {
        return view('auth.mot_de_passe_oublie', ['bloque' => RateLimiter::tooManyAttempts($this->cle($request), self::DEMANDES_MAX)]);
    }

    public function envoyer(Request $request, Courriel $courriel): RedirectResponse
    {
        if (RateLimiter::tooManyAttempts($this->cle($request), self::DEMANDES_MAX)) {
            return back();
        }
        RateLimiter::hit($this->cle($request), self::FENETRE_SECONDES);

        $email = mb_substr(trim((string) $request->input('email')), 0, 150);
        $u = $email !== '' ? DB::table('utilisateurs')->where('email', $email)->where('actif', 1)->first(['id', 'prenoms', 'nom']) : null;
        if ($u) {
            $jeton = bin2hex(random_bytes(32));
            DB::transaction(function () use ($u, $jeton) {
                DB::table('password_resets')->where('user_id', $u->id)->where('used', 0)->update(['used' => 1]);
                DB::table('password_resets')->insert(['user_id' => $u->id, 'token_hash' => hash('sha256', $jeton), 'expires_at' => now()->addHour()]);
            });
            $lien = route('reinitialiser_mot_de_passe', ['token' => $jeton]);
            $corps = '<p>Bonjour '.e(trim($u->prenoms.' '.$u->nom) ?: '').',</p>'
                .'<p>Une demande de réinitialisation de mot de passe a été effectuée pour votre compte '.e($this->universite->get()->nomApplication()).'.</p>'
                .'<p><a href="'.e($lien).'">Cliquez ici pour choisir un nouveau mot de passe</a></p>'
                ."<p>Ce lien expire dans 1 heure. Si vous n'êtes pas à l'origine de cette demande, ignorez simplement ce message.</p>";
            $courriel->envoyer($email, 'Réinitialisation de votre mot de passe', $corps, 'reset_password', (int) $u->id);
            $this->journal->action('modifier', 'auth', "Demande de réinitialisation de mot de passe envoyée (utilisateur #{$u->id})", (int) $u->id);
        }

        // Toujours le même message : ne pas révéler quelles adresses sont enregistrées
        return back()->with('succes', 'Si cette adresse existe dans notre système, un lien de réinitialisation vient de lui être envoyé. Vérifiez aussi vos courriers indésirables.');
    }

    public function formulaireReinitialisation(Request $request): View
    {
        return view('auth.reinitialiser_mot_de_passe', ['token' => (string) $request->query('token'), 'valide' => $this->demande((string) $request->query('token')) !== null]);
    }

    public function reinitialiser(Request $request): RedirectResponse|View
    {
        $token = (string) $request->input('token');
        $demande = $this->demande($token);
        if (! $demande) {
            return view('auth.reinitialiser_mot_de_passe', ['token' => '', 'valide' => false]);
        }
        $v = Validator::make($request->all(), ['mot_de_passe' => ['required', 'string', 'max:255', 'same:mot_de_passe2', Password::min(8)->letters()->numbers()]], [
            'mot_de_passe.same' => 'Les deux mots de passe ne correspondent pas.',
            'mot_de_passe.*' => 'Le mot de passe doit contenir au moins 8 caractères, avec des lettres et des chiffres.',
        ]);
        if ($v->fails()) {
            return redirect()->route('reinitialiser_mot_de_passe', ['token' => $token])->with('erreur', $v->errors()->first());
        }
        DB::transaction(function () use ($demande, $request) {
            DB::table('utilisateurs')->where('id', $demande->user_id)->update(['mot_de_passe' => Hash::make((string) $request->input('mot_de_passe'))]);
            // Ce lien et tous les autres encore valides pour ce compte
            DB::table('password_resets')->where('user_id', $demande->user_id)->update(['used' => 1]);
        });
        $this->journal->action('modifier', 'auth', "Mot de passe réinitialisé avec succès (utilisateur #{$demande->user_id})", (int) $demande->user_id);

        return redirect()->route('login')->with('succes', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');
    }

    /** Demande valide (non utilisée, non expirée, compte actif) pour ce jeton. */
    private function demande(string $token): ?object
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        return DB::table('password_resets as pr')->join('utilisateurs as u', 'u.id', '=', 'pr.user_id')
            ->where('pr.token_hash', hash('sha256', $token))->where('pr.used', 0)->where('pr.expires_at', '>', now())->where('u.actif', 1)
            ->first(['pr.id', 'pr.user_id']);
    }

    private function cle(Request $request): string
    {
        return 'mdp_oublie|'.$this->universite->get()->code.'|'.$request->ip();
    }
}
