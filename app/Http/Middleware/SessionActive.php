<?php

namespace App\Http\Middleware;

use App\Espaces\Espaces;
use App\Navigation\Menu;
use App\Universites\UniversiteCourante;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pour un utilisateur connecté :
 *  - déconnexion après 30 min d'inactivité (ancien session_start_secure()),
 *    avec le message « session expirée » sur la page de connexion ;
 *  - déconnexion immédiate d'un compte désactivé (nouveau : l'ancienne
 *    application ne le vérifiait qu'à la connexion) ;
 *  - étudiant et recteur cantonnés à leur portail et aux pages publiques
 *    (ancien index.php) ;
 *  - session liée à une seule université.
 */
class SessionActive
{
    /** Routes ouvertes sans compte du personnel. */
    private const PUBLIQUES = ['candidature', 'candidature.*', 'verifier_diplome', 'login_parent', 'login_parent.*', 'portail_parent', 'portail_parent.*', 'logout_parent', 'fichiers.logo'];

    public function __construct(
        private readonly Menu $menu,
        private readonly UniversiteCourante $universite,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Espaces Ministère / ANAB : sessions et comptes propres (middleware « espace »)
        if (Espaces::courant($request)) {
            return $next($request);
        }

        // Session ouverte dans une autre université (même cookie) : on la
        // ferme AVANT de charger l'utilisateur, sinon l'identifiant de
        // l'université A serait lu dans la base de l'université B.
        $codeSession = $request->session()->get('universite');
        if ($codeSession !== null && $codeSession !== $this->universite->get()->code) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $utilisateur = $request->user();
        if (! $utilisateur) {
            return $next($request);
        }

        $limite = config('gesnotes.inactivite_minutes') * 60;
        $derniere = (int) $request->session()->get('derniere_activite', time());
        $expiree = time() - $derniere > $limite || ! $utilisateur->actif;

        // Espaces publics (portail candidats, Espace Parents, vérification) :
        // ils ont leur propre session ; une session du personnel expirée y est
        // simplement fermée, sans renvoyer vers la connexion du personnel ni
        // effacer la session du candidat ou du parent, et l'étudiant ou le
        // recteur connecté peut s'y rendre (inscription en ligne par matricule).
        if ($request->routeIs(...self::PUBLIQUES)) {
            if ($expiree) {
                Auth::guard('web')->logout();
                $request->session()->forget('derniere_activite');
            }

            return $next($request);
        }

        if ($expiree) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login', $utilisateur->actif ? ['expired' => 1] : []);
        }
        $request->session()->put('derniere_activite', time());

        // Étudiant et recteur : leur portail (et ses actions), la déconnexion et la vérification publique.
        // Un portail pas encore migré s'ouvre dans l'ancienne application.
        $portail = ['etudiant' => 'portail_etudiant', 'recteur' => 'rectorat'][$utilisateur->role] ?? null;
        if ($portail && ! $request->routeIs($portail, $portail.'.*', 'logout', 'verifier_diplome')) {
            return Route::has($portail) ? redirect()->route($portail) : redirect()->away($this->menu->ancienneUrl($portail));
        }

        return $next($request);
    }
}
