<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Redirection des adresses de l'ancienne application (étape 13) : favoris,
 * liens d'anciens e-mails, QR codes déjà imprimés (index.php?page=
 * verifier_diplome&num=…), application mobile (portail2/…).
 *
 * Seules des adresses de cette application sont produites (jamais une
 * adresse reprise de la requête) ; les autres paramètres sont transmis
 * tels quels. Redirection permanente (301) pour les lectures.
 */
class AnciennesAdressesController extends Controller
{
    /** Pages de l'ancien routeur dont la route porte un autre nom. */
    public const PAGES = [
        'login' => 'login', 'logout' => 'login',
        'inscription_en_ligne' => 'candidature.inscription', 'portail_admin' => 'portail_candidats_admin',
        'utilisateur_form' => 'utilisateurs', 'filiere_form' => 'filieres', 'promotion_form' => 'promotions',
        'liste_inscrits_pdf' => 'liste_inscrits.pdf', 'liste_inscrits_excel' => 'liste_inscrits.excel',
        'reclamations_export' => 'reclamations.export', 'resultats' => 'pv',
    ];

    /** Routes à identifiant dans l'adresse : où aller si l'identifiant manque. */
    private const SANS_ID = ['pv_detail' => 'pv', 'reclamation_traitement' => 'reclamations', 'dossier_candidat' => 'portail_candidats_admin'];

    /** Pages de l'ancien portail candidats (candidature/index.php?page=…). */
    public const CANDIDATURE = [
        'accueil' => 'candidature', 'login' => 'candidature.connexion', 'register' => 'candidature.compte', 'logout' => 'candidature',
        'dashboard' => 'candidature.tableau', 'depot' => 'candidature.dossiers', 'mes_dossiers' => 'candidature.dossiers',
        'succes' => 'candidature.dossiers', 'quitus' => 'candidature.dossiers', 'profil' => 'candidature.profil',
        'admin' => 'portail_candidats_admin', 'admin_export' => 'portail_candidats_admin',
        'inscription' => 'candidature.inscription', 'inscription_carte' => 'candidature.inscription',
    ];

    /** index.php?page=… (et la racine du site). */
    public function routeur(Request $request): RedirectResponse
    {
        $page = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $request->query('page')));
        if ($page === '') {
            return redirect()->route('accueil');
        }
        $route = self::PAGES[$page] ?? $page;
        if (! Route::has($route)) {
            return redirect()->route('accueil');
        }

        return $this->vers($request, $route, self::SANS_ID[$route] ?? null);
    }

    /** candidature/index.php?page=… et portail2/index.php?page=… (application mobile). */
    public function candidature(Request $request): RedirectResponse
    {
        $page = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $request->query('page', 'accueil')));
        // Reçu d'inscription en ligne : numéro d'inscription dans ins_id
        if ($page === 'recu' && ($id = (int) $request->query('ins_id')) > 0) {
            return redirect()->route('candidature.inscription.recu', ['id' => $id], 301);
        }

        return redirect()->route(self::CANDIDATURE[$page] ?? 'candidature', [], 301);
    }

    /** Anciens liens directs vers uploads/logos et uploads/photos. */
    public function fichier(string $dossier, string $fichier): RedirectResponse
    {
        return redirect()->route($dossier === 'logos' ? 'fichiers.logo' : 'fichiers.photo', ['fichier' => basename($fichier)], 301);
    }

    private function vers(Request $request, string $route, ?string $repli): RedirectResponse
    {
        $parametres = collect($request->query())->except(['page'])->filter(fn ($v) => is_scalar($v) || is_array($v))->all();
        $attendus = Route::getRoutes()->getByName($route)->parameterNames();
        foreach ($attendus as $nom) {
            if (! isset($parametres[$nom]) || ! ctype_digit((string) $parametres[$nom])) {
                return redirect()->route($repli ?? 'accueil');
            }
        }

        return redirect()->route($route, $parametres, 301);
    }
}
