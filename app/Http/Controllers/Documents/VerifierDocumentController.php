<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Services\CleVerification;
use App\Services\Parametres;
use App\Universites\UniversiteCourante;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Vérification publique d'un document (diplôme, attestation, relevé,
 * certificat, carte) par son numéro — portage de
 * pages/documents/verifier_diplome.php. Page PUBLIQUE : c'est elle que
 * visent les QR codes imprimés.
 *
 * Différences voulues :
 *  - nombre de vérifications limité par adresse IP (les numéros se suivent :
 *    sans limite, un robot pouvait parcourir R26-000001, R26-000002… et
 *    relever nom, mention et moyenne de tous les étudiants) ;
 *  - numéro contrôlé (lettres, chiffres, tirets, 30 caractères) ;
 *  - page exclue des moteurs de recherche (noindex) ;
 *  - plus de création de tables à l'exécution (elles sont dans le schéma) ;
 *  - détail (nom complet, mention, filière…) seulement avec la clé du QR code
 *    ou pour le personnel connecté ; sinon : authenticité, type, initiales, année.
 */
class VerifierDocumentController extends Controller
{
    /** Registres, dans l'ordre de recherche (un numéro n'existe que dans un seul). */
    private const REGISTRES = [
        'diplome' => 'diplomes_emis',
        'attestation' => 'attestations_emises',
        'releve' => 'releves_emis',
        'certificat' => 'certificats_emis',
        'carte' => 'cartes_emises',
    ];

    public const ESSAIS_MAX = 30;

    public const FENETRE_SECONDES = 600;

    public function __invoke(Request $request, Parametres $parametres, UniversiteCourante $universite, CleVerification $cles): Response
    {
        $numero = mb_substr(trim((string) $request->query('num', '')), 0, 60);
        $personnel = $request->user() && $request->user()->role !== 'etudiant';
        $donnees = ['numero' => $numero, 'resultat' => null, 'type' => null, 'attente' => 0, 'invalide' => false,
            'complet' => $personnel || $cles->valide($numero, (string) $request->query('cle', '')),
            'institution' => $parametres->valeur('institution_nom', 'EDUFILE')];
        $statut = 200;

        if ($numero !== '') {
            $cle = 'verification|'.$universite->get()->code.'|'.$request->ip();
            if (RateLimiter::tooManyAttempts($cle, self::ESSAIS_MAX)) {
                $donnees['attente'] = max(1, (int) ceil(RateLimiter::availableIn($cle) / 60));
                $statut = 429;
            } else {
                RateLimiter::hit($cle, self::FENETRE_SECONDES);
                if (! preg_match('/^[A-Za-z0-9-]{1,30}$/', $numero)) {
                    $donnees['invalide'] = true;
                } else {
                    foreach (self::REGISTRES as $type => $table) {
                        if ($r = DB::table($table)->where('numero', $numero)->first()) {
                            $donnees['resultat'] = $r;
                            $donnees['type'] = $type;
                            break;
                        }
                    }
                }
            }
        }

        return response()->view('documents.verifier', $donnees, $statut)->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
