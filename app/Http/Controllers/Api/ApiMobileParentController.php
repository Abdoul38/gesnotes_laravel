<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Licence;
use App\Services\SuiviEtudiant;
use App\Universites\UniversiteCourante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * API de l'Espace parent de l'application mobile : mêmes comptes que
 * l'Espace Parents du site (téléphone + mot de passe, créés par la
 * scolarité), même format de réponse que l'API étudiant
 * {success, message, data}, jeton « Bearer » de 30 jours (empreinte SHA-256
 * dans mobile_tokens_parents).
 *
 * Lecture seule : le parent ne voit que les enfants liés à son compte
 * (parents_etudiants) — notes et résultats des PV finalisés ou publiés,
 * présences, paiements, emploi du temps, examens, annonces. Compte
 * désactivé : ses sessions sont refusées.
 */
class ApiMobileParentController extends Controller
{
    private const ACTIONS = ['login', 'logout', 'profil', 'enfant', 'resultats', 'presences', 'paiements', 'emploi_du_temps', 'examens',
        'annonces', 'mot_de_passe'];

    /** Actions qui portent sur un enfant (paramètre et_id). */
    private const PAR_ENFANT = ['enfant', 'resultats', 'presences', 'paiements', 'emploi_du_temps', 'examens', 'annonces'];

    private ?object $parent = null;

    private ?object $enfant = null;

    private ?string $jeton = null;

    public function __construct(private readonly UniversiteCourante $universite, private readonly SuiviEtudiant $suivi) {}

    public function __invoke(Request $request, Licence $licence): JsonResponse
    {
        if ($request->isMethod('options')) {
            return $this->cors(response()->json(null));
        }
        $action = (string) ($request->query('action') ?? $request->input('action', ''));
        if (! in_array($action, self::ACTIONS, true)) {
            return $this->erreur('Action inconnue', 404);
        }
        if (! $licence->verifierInstallation()['valide']) {
            return $this->erreur("Service indisponible : licence de l'établissement invalide. Contactez la scolarité.", 503);
        }
        if ($action !== 'login') {
            $this->parent = $this->authentifier($request);
            if (! $this->parent) {
                return $this->erreur($request->bearerToken() ? 'Session expirée, veuillez vous reconnecter' : 'Token manquant', 401);
            }
        }
        if (in_array($action, self::PAR_ENFANT, true)) {
            $this->enfant = $this->enfants()->firstWhere('id', (int) ($request->query('et_id') ?? $request->input('et_id')));
            if (! $this->enfant) {
                return $this->erreur("Cet étudiant n'est pas lié à votre compte.", 403);
            }
        }
        try {
            return $this->cors($this->{lcfirst(str_replace('_', '', ucwords($action, '_')))}($request));
        } catch (Throwable $e) {
            Log::error('api_mobile_parents', ['action' => $action, 'erreur' => $e->getMessage()]);

            return $this->erreur('Erreur interne. Réessayez plus tard.', 500);
        }
    }

    // ── Compte ────────────────────────────────────────────────

    private function login(Request $request): JsonResponse
    {
        $telephone = preg_replace('/[^0-9+]/', '', (string) $request->input('telephone'));
        $motDePasse = (string) $request->input('password');
        if ($telephone === '' || $motDePasse === '') {
            return $this->erreur('Téléphone et mot de passe requis');
        }
        $cle = 'api_mobile_parents|'.$this->universite->get()->code.'|'.$request->ip().'|'.$telephone;
        if (RateLimiter::tooManyAttempts($cle, 5)) {
            return $this->erreur('Trop de tentatives. Réessayez dans '.(int) ceil(RateLimiter::availableIn($cle) / 60).' minute(s).', 429);
        }
        $parent = DB::table('parents')->where('telephone', $telephone)->first();
        // Mot de passe vérifié avant de révéler qu'un compte est désactivé (comme le site)
        if (! $parent || ! Hash::check($motDePasse, $parent->mot_de_passe_hash)) {
            RateLimiter::hit($cle, 300);

            return $this->erreur('Numéro de téléphone ou mot de passe incorrect.');
        }
        if (! $parent->actif) {
            return $this->erreur("Ce compte a été désactivé. Contactez l'établissement.", 403);
        }
        RateLimiter::clear($cle);
        $jeton = bin2hex(random_bytes(32));
        DB::table('mobile_tokens_parents')->insert(['parent_id' => $parent->id, 'token' => hash('sha256', $jeton),
            'device_info' => mb_substr(trim((string) $request->input('device', 'Flutter')), 0, 200), 'expires_at' => now()->addDays(30), 'created_at' => now()]);
        DB::table('parents')->where('id', $parent->id)->update(['derniere_connexion' => now()]);
        $this->parent = $parent;

        return $this->ok(['token' => $jeton, 'parent' => $this->infosParent()], 'Connexion réussie');
    }

    private function logout(): JsonResponse
    {
        DB::table('mobile_tokens_parents')->where('token', hash('sha256', (string) $this->jeton))->delete();

        return $this->ok([], 'Déconnecté');
    }

    /** Le parent et ses enfants (avec leur inscription de l'année). */
    private function profil(): JsonResponse
    {
        return $this->ok($this->infosParent());
    }

    private function motDePasse(Request $request): JsonResponse
    {
        $hash = (string) DB::table('parents')->where('id', $this->parent->id)->value('mot_de_passe_hash');
        if (! Hash::check((string) $request->input('actuel'), $hash)) {
            return $this->erreur('Mot de passe actuel incorrect.');
        }
        $v = Validator::make(['mdp' => (string) $request->input('nouveau')], ['mdp' => ['required', Password::min(8)->letters()->numbers()]], [], ['mdp' => 'nouveau mot de passe']);
        if ($v->fails()) {
            return $this->erreur($v->errors()->first());
        }
        DB::transaction(function () use ($request) {
            DB::table('parents')->where('id', $this->parent->id)->update(['mot_de_passe_hash' => Hash::make((string) $request->input('nouveau'))]);
            // Les autres appareils sont déconnectés
            DB::table('mobile_tokens_parents')->where('parent_id', $this->parent->id)->where('token', '!=', hash('sha256', (string) $this->jeton))->delete();
        });

        return $this->ok([], 'Mot de passe modifié.');
    }

    // ── Suivi d'un enfant ─────────────────────────────────────

    /** Tableau de bord : inscription, dernier résultat, présence, reste à payer, prochains examens. */
    private function enfant(): JsonResponse
    {
        $id = (int) $this->enfant->id;
        $notes = $this->suivi->notes($id);
        $presences = $this->suivi->presences($id);
        $paiements = $this->suivi->paiements($id);

        $etudiant = clone $this->enfant;
        $etudiant->photo_url = $etudiant->photo ? URL::temporarySignedRoute('api_mobile.photo', now()->addHour(), ['etudiant' => $id]) : null;
        unset($etudiant->photo);

        return $this->ok([
            'etudiant' => $etudiant,
            'inscription' => $this->suivi->inscription($id),
            'dernier_resultat' => $notes['resultats']->first(),
            'taux_presence' => $presences['taux'],
            'absences' => $presences['absences'],
            'reste_a_payer' => $paiements['reste'],
            'prochains_examens' => collect($this->suivi->examens($id))->where('passe', false)->take(3)->values(),
            'nb_annonces' => $this->suivi->annonces($this->enfant)->count(),
        ]);
    }

    private function resultats(Request $request): JsonResponse
    {
        return $this->ok($this->suivi->notes((int) $this->enfant->id, (int) $request->query('annee_id')));
    }

    private function presences(): JsonResponse
    {
        return $this->ok($this->suivi->presences((int) $this->enfant->id));
    }

    private function paiements(): JsonResponse
    {
        return $this->ok($this->suivi->paiements((int) $this->enfant->id));
    }

    private function emploiDuTemps(): JsonResponse
    {
        return $this->ok($this->suivi->emploiDuTemps((int) $this->enfant->id));
    }

    private function examens(): JsonResponse
    {
        return $this->ok($this->suivi->examens((int) $this->enfant->id));
    }

    private function annonces(): JsonResponse
    {
        return $this->ok($this->suivi->annonces($this->enfant)->orderByDesc('epinglee')->orderByDesc('created_at')->limit(50)
            ->get(['id', 'titre', 'contenu', 'type', 'epinglee', 'date_debut', 'date_fin', 'created_at']));
    }

    // ── Outils ────────────────────────────────────────────────

    /** Parent du jeton, si le compte est actif. */
    private function authentifier(Request $request): ?object
    {
        $jeton = (string) $request->bearerToken();
        if ($jeton === '' || strlen($jeton) > 128) {
            return null;
        }
        $ligne = DB::table('mobile_tokens_parents as mt')->join('parents as p', 'p.id', '=', 'mt.parent_id')
            ->where('mt.token', hash('sha256', $jeton))->where('mt.expires_at', '>', now())->where('p.actif', 1)
            ->first(['mt.id as jeton_id', 'p.id', 'p.nom', 'p.prenoms', 'p.telephone', 'p.email']);
        if (! $ligne) {
            return null;
        }
        DB::table('mobile_tokens_parents')->where('id', $ligne->jeton_id)->update(['last_used' => now()]);
        $this->jeton = $jeton;

        return $ligne;
    }

    /** Enfants liés au compte (colonnes utiles aux écrans et aux annonces). */
    private function enfants()
    {
        return DB::table('parents_etudiants as pe')->join('etudiants as e', 'e.id', '=', 'pe.etudiant_id')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->where('pe.parent_id', $this->parent->id)->orderBy('e.nom')->orderBy('e.prenoms')
            ->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero', 'e.sexe', 'e.photo', 'e.filiere_id', 'f.libelle as filiere', 'pe.lien']);
    }

    private function infosParent(): array
    {
        return [
            'id' => $this->parent->id, 'nom' => $this->parent->nom, 'prenoms' => $this->parent->prenoms,
            'telephone' => $this->parent->telephone, 'email' => $this->parent->email,
            'enfants' => $this->enfants()->map(function ($e) {
                $ins = $this->suivi->inscription((int) $e->id);
                $e->photo_url = $e->photo ? URL::temporarySignedRoute('api_mobile.photo', now()->addHour(), ['etudiant' => $e->id]) : null;
                $e->niveau = $ins->niveau ?? null;
                $e->annee = $ins->annee ?? null;
                $e->filiere = $ins->filiere ?? $e->filiere;
                unset($e->photo);

                return $e;
            })->values(),
        ];
    }

    private function ok(mixed $data = [], string $message = 'OK'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function erreur(string $message, int $code = 400): JsonResponse
    {
        return $this->cors(response()->json(['success' => false, 'message' => $message, 'data' => null], $code, [], JSON_UNESCAPED_UNICODE));
    }

    private function cors(JsonResponse $r): JsonResponse
    {
        $r->headers->set('Access-Control-Allow-Origin', '*');
        $r->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $r->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');

        return $r;
    }
}
