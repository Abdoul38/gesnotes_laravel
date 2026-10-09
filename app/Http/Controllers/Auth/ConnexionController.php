<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Navigation\Menu;
use App\Services\Journal;
use App\Services\Parametres;
use App\Support\SessionPersonnel;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Connexion au back-office — portage de pages/comptes/login.php et logout.php.
 */
class ConnexionController extends Controller
{
    private const ESSAIS_MAX = 5;

    private const BLOCAGE_SECONDES = 300;

    public function __construct(
        private readonly Journal $journal,
        private readonly UniversiteCourante $universite,
    ) {}

    public function formulaire(Request $request, Parametres $parametres): View
    {
        return view('auth.connexion', [
            'attente' => RateLimiter::availableIn($this->cle($request)) * (int) $this->bloque($request),
            'logo' => $parametres->valeur('logo_universite'),
            'expiree' => $request->query('expired') === '1',
        ]);
    }

    public function connecter(Request $request, Menu $menu): RedirectResponse
    {
        if ($this->bloque($request)) {
            return back(); // le formulaire affiche le temps d'attente
        }

        $email = trim((string) $request->input('email'));
        $motDePasse = (string) $request->input('mot_de_passe');
        if ($email === '' || $motDePasse === '') {
            return back()->withInput($request->only('email'))->with('erreur', 'Veuillez remplir tous les champs.');
        }

        $utilisateur = Utilisateur::where('email', $email)->where('actif', 1)->first();
        if (! $utilisateur || ! Hash::check($motDePasse, $utilisateur->mot_de_passe)) {
            RateLimiter::hit($this->cle($request), self::BLOCAGE_SECONDES);
            usleep(500000); // pause anti-force brute, comme l'ancien code
            Log::warning('Tentative de connexion échouée', ['email' => $email, 'ip' => $request->ip()]);

            return back()->withInput($request->only('email'))->with('erreur', 'Email ou mot de passe incorrect.');
        }

        RateLimiter::clear($this->cle($request));
        Auth::login($utilisateur);
        $request->session()->regenerate();
        $request->session()->put([
            'universite' => $this->universite->get()->code,
            'derniere_activite' => time(),
        ]);

        // Étudiant : mot de passe provisoire (jamais connecté) ou encore égal à son
        // matricule → nouveau mot de passe obligatoire avant d'accéder au portail
        $aChanger = $utilisateur->role === 'etudiant' && ($utilisateur->derniere_connexion === null
            || $motDePasse === (string) DB::table('etudiants')->where('id', (int) $utilisateur->etudiant_id)->value('numero'));
        $utilisateur->forceFill(['derniere_connexion' => now()])->saveQuietly();
        $this->journal->audit('LOGIN', 'utilisateurs', $utilisateur->id);
        $this->journal->action('connexion', 'auth', 'Connexion réussie', $utilisateur->id);

        if ($aChanger) {
            $request->session()->put('mdp_a_changer', true);

            return redirect()->route('mot_de_passe_initial');
        }

        // Même destination selon le rôle que l'ancien login.php ;
        // un portail pas encore migré s'ouvre dans l'ancienne application.
        $destination = match ($utilisateur->role) {
            'etudiant' => 'portail_etudiant',
            'enseignant', 'chef_departement' => 'portail_enseignant',
            'recteur' => 'rectorat',
            default => 'accueil',
        };

        return redirect()->to($menu->lien($destination));
    }

    public function deconnecter(Request $request): RedirectResponse
    {
        $this->journal->audit('LOGOUT', 'utilisateurs', Auth::id());
        SessionPersonnel::fermer($request);

        return redirect()->route('login');
    }

    private function cle(Request $request): string
    {
        return 'connexion|'.$this->universite->get()->code.'|'.$request->ip();
    }

    private function bloque(Request $request): bool
    {
        return RateLimiter::tooManyAttempts($this->cle($request), self::ESSAIS_MAX);
    }
}
