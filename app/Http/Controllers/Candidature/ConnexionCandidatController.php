<?php

namespace App\Http\Controllers\Candidature;

use App\Http\Controllers\Controller;
use App\Models\Candidat;
use App\Services\Candidatures;
use App\Services\Parametres;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Portail candidats : accueil public, connexion, création de compte —
 * portage de candidature/pages/accueil.php (et de ses copies login.php,
 * register.php).
 *
 * Différences voulues :
 *  - session propre au portail (garde « candidat »), liée à l'université,
 *    renouvelée à la connexion, expirant après inactivité : elle était
 *    partagée avec GesNotes et jamais renouvelée (fixation de session) ;
 *  - 5 essais de connexion puis 5 minutes d'attente par adresse IP (aucune
 *    limite avant) ;
 *  - mot de passe : 8 caractères, lettres et chiffres (6 caractères avant) ;
 *  - déconnexion en POST ; coordonnées et textes de l'université lus dans
 *    ses paramètres (adresse et contacts de l'UDH écrits en dur ailleurs).
 */
class ConnexionCandidatController extends Controller
{
    private const ESSAIS_MAX = 5;

    private const BLOCAGE_SECONDES = 300;

    public function __construct(private readonly UniversiteCourante $universite, private readonly Candidatures $candidatures) {}

    public function accueil(Request $request, Parametres $parametres): View|RedirectResponse
    {
        if ($this->connecte($request)) {
            return redirect()->route('candidature.tableau');
        }

        return view('candidature.accueil', $this->commun($parametres) + [
            'slides' => array_values(array_filter([1, 2, 3, 4], fn ($n) => $this->cheminSlide($n) !== null)),
        ]);
    }

    public function formulaireConnexion(Request $request, Parametres $parametres): View|RedirectResponse
    {
        if ($this->connecte($request)) {
            return redirect()->route('candidature.tableau');
        }

        return view('candidature.authentification', $this->commun($parametres) + ['mode' => 'connexion',
            'attente' => $this->bloque($request) ? (int) ceil(RateLimiter::availableIn($this->cle($request)) / 60) : 0]);
    }

    public function formulaireCompte(Request $request, Parametres $parametres): View|RedirectResponse
    {
        if ($this->connecte($request)) {
            return redirect()->route('candidature.tableau');
        }

        return view('candidature.authentification', $this->commun($parametres) + ['mode' => 'compte', 'attente' => 0]);
    }

    public function connecter(Request $request): RedirectResponse
    {
        if ($this->bloque($request)) {
            return back();
        }
        $email = mb_strtolower(trim((string) $request->input('email')));
        $motDePasse = (string) $request->input('mdp');
        $candidat = $email !== '' ? Candidat::where('email', $email)->first() : null;
        if (! $candidat || $motDePasse === '' || ! Hash::check($motDePasse, $candidat->mot_de_passe) || ! $candidat->actif) {
            RateLimiter::hit($this->cle($request), self::BLOCAGE_SECONDES);
            usleep(500000);
            Log::warning('Connexion candidat échouée', ['email' => $email, 'ip' => $request->ip()]);

            return back()->withInput($request->only('email'))->with('erreur', 'Email ou mot de passe incorrect.');
        }
        RateLimiter::clear($this->cle($request));
        $this->ouvrirSession($request, $candidat);

        return redirect()->route('candidature.tableau');
    }

    public function creerCompte(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $v = Validator::make($request->all(), [
            'nom' => 'required|string|max:100', 'prenom' => 'required|string|max:150',
            'email' => 'required|email|max:150', 'telephone' => 'nullable|string|max:30',
            'mdp' => ['required', 'same:mdp2', Password::min(8)->letters()->numbers()],
        ], ['nom.required' => 'Le nom est requis.', 'prenom.required' => 'Le prénom est requis.', 'email.*' => 'Email invalide.',
            'mdp.same' => 'Les mots de passe ne correspondent pas.'], ['mdp' => 'mot de passe']);
        if ($v->fails()) {
            return back()->withInput($request->except('mdp', 'mdp2'))->with('erreur', $v->errors()->first());
        }
        if (Candidat::where('email', $request->input('email'))->exists()) {
            return back()->withInput($request->except('mdp', 'mdp2'))->with('erreur', 'Cet email est déjà utilisé.');
        }
        $candidat = Candidat::create([
            'nom' => mb_strtoupper(trim((string) $request->input('nom'))),
            'prenom' => mb_convert_case(mb_strtolower(trim((string) $request->input('prenom'))), MB_CASE_TITLE),
            'email' => $request->input('email'), 'telephone' => trim((string) $request->input('telephone')),
            'mot_de_passe' => (string) $request->input('mdp'), 'actif' => 1,
        ]);
        $this->ouvrirSession($request, $candidat);

        return redirect()->route('candidature.tableau');
    }

    public function deconnecter(Request $request): RedirectResponse
    {
        Auth::guard('candidat')->logout();
        $request->session()->forget(['candidat_universite', 'candidat_activite']);
        $request->session()->regenerateToken();

        return redirect()->route('candidature');
    }

    /** Image du carrousel propre à l'université (ancien candidature/assets/img/<sigle>/slide-N.jpg). */
    public function slide(int $n): BinaryFileResponse
    {
        $chemin = $this->cheminSlide($n);
        abort_unless($chemin, 404);

        return new BinaryFileResponse($chemin, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400']);
    }

    private function cheminSlide(int $n): ?string
    {
        $racine = config('gesnotes.ancienne_app_chemin');
        $u = $this->universite->get();
        $chemin = $racine ? rtrim($racine, '/\\')."/candidature/assets/img/".strtolower($u->sigle ?: $u->code)."/slide-$n.jpg" : null;

        return $n >= 1 && $n <= 4 && $chemin && is_file($chemin) ? $chemin : null;
    }

    private function commun(Parametres $parametres): array
    {
        $u = $this->universite->get();

        return ['universite' => $u, 'annee' => $this->candidatures->annee(), 'ouvert' => $this->candidatures->ouvert(),
            'messageFerme' => $this->candidatures->config('message_ferme', 'Les dépôts sont temporairement fermés.'),
            'adresse' => $parametres->valeur('institution_bp', trim($u->ville.', Niger', ', ')),
            'email' => $parametres->valeur('institution_email', ''), 'telephone' => $parametres->valeur('institution_tel', '')];
    }

    private function ouvrirSession(Request $request, Candidat $candidat): void
    {
        Auth::guard('candidat')->login($candidat);
        $request->session()->regenerate();
        $request->session()->put(['candidat_universite' => $this->universite->get()->code, 'candidat_activite' => time()]);
    }

    private function connecte(Request $request): bool
    {
        return Auth::guard('candidat')->check() && $request->session()->get('candidat_universite') === $this->universite->get()->code;
    }

    private function cle(Request $request): string
    {
        return 'connexion_candidat|'.$this->universite->get()->code.'|'.$request->ip();
    }

    private function bloque(Request $request): bool
    {
        return RateLimiter::tooManyAttempts($this->cle($request), self::ESSAIS_MAX);
    }
}
