<?php

namespace App\Http\Controllers\Candidature;

use App\Http\Controllers\Controller;
use App\Services\Candidatures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Espace du candidat — portage de candidature/pages/dashboard.php,
 * mes_dossiers.php et profil.php.
 *
 * Différences voulues :
 *  - le détail d'un dossier est rendu par le serveur : la page envoyait au
 *    navigateur toutes les colonnes des dossiers, notes internes de
 *    l'administration comprises, et les insérait en HTML ;
 *  - pièces servies par l'application au seul candidat (elles étaient dans
 *    un dossier public : quiconque avait le nom du fichier les ouvrait) ;
 *  - profil : nouveau mot de passe seulement avec le mot de passe actuel,
 *    8 caractères, lettres et chiffres ; confirmation différente signalée
 *    (elle était ignorée en silence) ;
 *  - un dossier « incomplet » peut être complété et soumis à nouveau.
 */
class EspaceCandidatController extends Controller
{
    public function __construct(private readonly Candidatures $candidatures) {}

    public function tableau(Request $request): View
    {
        $candidat = $request->user('candidat');
        $dossiers = $this->candidatures->dossiersDe($candidat->id);
        $brouillon = $dossiers->first(fn ($d) => in_array($d->statut, Candidatures::MODIFIABLES, true));
        $accepte = $dossiers->first(fn ($d) => $d->statut === 'accepte');
        $matricule = $accepte ? DB::table('inscriptions_en_ligne')->where(['candidature_id' => $accepte->id, 'statut_paiement' => 'valide'])->value('matricule') : null;

        return view('candidature.tableau', ['candidat' => $candidat, 'dossiers' => $dossiers, 'brouillon' => $brouillon,
            'accepte' => $accepte, 'matricule' => $matricule, 'ouvert' => $this->candidatures->ouvert(), 'annee' => $this->candidatures->annee()]);
    }

    public function dossiers(Request $request): View
    {
        $id = (int) $request->query('voir');

        return view('candidature.dossiers', ['dossiers' => $this->candidatures->dossiersDe($request->user('candidat')->id),
            'detail' => $id ? $this->candidatures->dossier($id, $request->user('candidat')->id) : null]);
    }

    public function profil(Request $request): View
    {
        return view('candidature.profil', ['candidat' => $request->user('candidat')]);
    }

    public function majProfil(Request $request): RedirectResponse
    {
        $candidat = $request->user('candidat');
        $v = Validator::make($request->all(), [
            'nom' => 'required|string|max:100', 'prenom' => 'required|string|max:150', 'telephone' => 'nullable|string|max:30',
            'mdp' => ['nullable', 'same:mdp2', Password::min(8)->letters()->numbers()],
        ], ['required' => 'Nom et prénom sont requis.', 'mdp.same' => 'La confirmation ne correspond pas au nouveau mot de passe.'], ['mdp' => 'nouveau mot de passe']);
        if ($v->fails()) {
            return back()->with('erreur', $v->errors()->first());
        }
        $nouveau = (string) $request->input('mdp', '');
        if ($nouveau !== '' && ! Hash::check((string) $request->input('mdp_actuel'), $candidat->mot_de_passe)) {
            return back()->with('erreur', 'Mot de passe actuel incorrect.');
        }
        $candidat->fill(['nom' => mb_strtoupper(trim((string) $request->input('nom'))),
            'prenom' => mb_convert_case(mb_strtolower(trim((string) $request->input('prenom'))), MB_CASE_TITLE),
            'telephone' => trim((string) $request->input('telephone'))]);
        if ($nouveau !== '') {
            $candidat->mot_de_passe = $nouveau;
        }
        $candidat->save();

        return back()->with('succes', $nouveau !== '' ? 'Profil et mot de passe mis à jour.' : 'Profil mis à jour.');
    }

    /** Pièce d'un de SES dossiers. */
    public function piece(Request $request, int $id, string $type): BinaryFileResponse
    {
        $dossier = $this->candidatures->dossier($id, $request->user('candidat')->id);
        abort_unless($dossier && isset(Candidatures::PIECES[$type]), 404);

        return self::servirPiece((string) $dossier->{Candidatures::PIECES[$type][0]});
    }

    /** Sert une pièce du dossier privé des candidats (nom simple, extension autorisée). */
    public static function servirPiece(string $fichier): BinaryFileResponse
    {
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $extension = strtolower(pathinfo($fichier, PATHINFO_EXTENSION));
        $racine = config('gesnotes.dossier_pieces_candidats');
        $dossier = $racine ? realpath($racine) : false;
        $chemin = $dossier && preg_match('/^[A-Za-z0-9._-]+$/', $fichier) && isset($types[$extension]) ? realpath($dossier.'/'.$fichier) : false;
        abort_unless($chemin && is_file($chemin) && str_starts_with($chemin, $dossier.DIRECTORY_SEPARATOR), 404);

        return new BinaryFileResponse($chemin, 200, ['Content-Type' => $types[$extension], 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store'], public: false);
    }
}
