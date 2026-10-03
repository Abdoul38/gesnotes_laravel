<?php

namespace App\Http\Controllers\Portails;

use App\Http\Controllers\Controller;
use App\Models\ParentEleve;
use App\Services\Parametres;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Connexion à l'Espace Parents — portage de pages/comptes/login_parent.php
 * (numéro de téléphone + mot de passe). Comme l'original : mot de passe
 * vérifié avant de révéler qu'un compte est désactivé, essais limités.
 *
 * Différences voulues : session distincte du personnel (garde « parent »),
 * liée à l'université, expirant après inactivité (voir ParentConnecte) ;
 * déconnexion en POST ; 5 essais puis 5 minutes d'attente par adresse IP
 * (règle de la connexion du personnel).
 */
class ConnexionParentController extends Controller
{
    private const ESSAIS_MAX = 5;

    private const BLOCAGE_SECONDES = 300;

    public function __construct(private readonly UniversiteCourante $universite) {}

    public function formulaire(Request $request, Parametres $parametres): View|RedirectResponse
    {
        if (Auth::guard('parent')->check() && $request->session()->get('parent_universite') === $this->universite->get()->code) {
            return redirect()->route('portail_parent');
        }

        return view('portails.connexion_parent', [
            'institution' => $parametres->valeur('institution_nom', config('app.name')),
            'attente' => $this->bloque($request) ? (int) ceil(RateLimiter::availableIn($this->cle($request)) / 60) : 0,
            'expiree' => $request->query('expired') === '1',
        ]);
    }

    public function connecter(Request $request): RedirectResponse
    {
        if ($this->bloque($request)) {
            return back();
        }
        $telephone = preg_replace('/[^0-9+]/', '', (string) $request->input('telephone'));
        $motDePasse = (string) $request->input('mot_de_passe');
        $parent = $telephone !== '' ? ParentEleve::where('telephone', $telephone)->first() : null;

        if (! $parent || $motDePasse === '' || ! Hash::check($motDePasse, $parent->mot_de_passe_hash)) {
            RateLimiter::hit($this->cle($request), self::BLOCAGE_SECONDES);
            usleep(500000);
            Log::warning('Connexion parent échouée', ['telephone' => $telephone, 'ip' => $request->ip()]);

            return back()->withInput($request->only('telephone'))->with('erreur', 'Numéro de téléphone ou mot de passe incorrect.');
        }
        if (! $parent->actif) {
            return back()->withInput($request->only('telephone'))->with('erreur', "Ce compte a été désactivé. Contactez l'établissement.");
        }

        RateLimiter::clear($this->cle($request));
        Auth::guard('parent')->login($parent);
        $request->session()->regenerate();
        $request->session()->put(['parent_universite' => $this->universite->get()->code, 'parent_activite' => time()]);
        $parent->forceFill(['derniere_connexion' => now()])->saveQuietly();

        return redirect()->route('portail_parent');
    }

    public function deconnecter(Request $request): RedirectResponse
    {
        Auth::guard('parent')->logout();
        $request->session()->forget(['parent_universite', 'parent_activite']);
        $request->session()->regenerateToken();

        return redirect()->route('login_parent');
    }

    private function cle(Request $request): string
    {
        return 'connexion_parent|'.$this->universite->get()->code.'|'.$request->ip();
    }

    private function bloque(Request $request): bool
    {
        return RateLimiter::tooManyAttempts($this->cle($request), self::ESSAIS_MAX);
    }
}
