<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Services\CleVerification;
use App\Services\Parametres;
use App\Services\RelevesEmis;
use App\Universites\UniversiteCourante;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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
 *    ou le code de vérification imprimé sur le document — y compris pour le
 *    personnel connecté (il retrouve les documents dans le registre) ;
 *  - numéro seul : AUCUNE réponse (ni « authentique », ni « introuvable ») —
 *    il faut aussi le code de vérification, ou le nom du titulaire (documents
 *    imprimés avant le code : authenticité, type, initiales, année). On ne
 *    peut donc plus parcourir les numéros, qui se suivent, pour savoir
 *    lesquels existent.
 *
 * Ajout : pour un relevé de notes, les notes (par UE et matière) et le
 * résultat du semestre, tels qu'imprimés — mêmes conditions que le détail.
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
        $code = mb_substr(trim((string) $request->query('code', '')), 0, 20);
        $nom = mb_substr(trim((string) $request->query('nom', '')), 0, 100);
        $qr = $cles->valide($numero, (string) $request->query('cle', ''));
        $donnees = ['numero' => $numero, 'code' => $code, 'nom' => $nom, 'qr' => $qr, 'resultat' => null, 'type' => null, 'attente' => 0, 'invalide' => false,
            'notesReleve' => null, 'preuveManquante' => false,
            'complet' => $qr || $cles->codeValide($numero, $code),
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
                    // Sans code ni QR code : réponse seulement si le nom du titulaire correspond — sinon rien,
                    // que le numéro existe ou non (pas de parcours des numéros)
                    if (! $donnees['complet'] && ! ($donnees['resultat'] && $nom !== '' && self::memeNom($donnees['resultat'], $nom))) {
                        [$donnees['resultat'], $donnees['type']] = [null, null];
                        $donnees['preuveManquante'] = $code === '' && $nom === '';
                    }
                    // Relevé : ses notes, seulement avec la clé du QR code ou le code de vérification
                    if ($donnees['type'] === 'releve' && $donnees['complet']) {
                        $donnees['notesReleve'] = app(RelevesEmis::class)->contenuPourVerification($donnees['resultat']);
                    }
                }
            }
        }

        return response()->view('documents.verifier', $donnees, $statut)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Nom saisi = nom de famille du titulaire, ou nom et prénoms (dans un ordre ou l'autre) ; sans accents ni casse. */
    private static function memeNom(object $r, string $saisi): bool
    {
        $n = fn (string $v) => preg_replace('/[^a-z]/', '', strtolower(Str::ascii($v)));
        $saisi = $n($saisi);

        return $saisi !== '' && in_array($saisi, [$n($r->nom), $n($r->nom.$r->prenoms), $n($r->prenoms.$r->nom)], true);
    }
}
