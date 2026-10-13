<?php

namespace App\Http\Controllers\Candidature;

use App\Http\Controllers\Controller;
use App\Services\Candidatures;
use App\Services\Identite;
use App\Services\InscriptionEnLigne;
use App\Services\Paiement\KomiPay;
use App\Services\Parametres;
use App\Universites\UniversiteCourante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Inscription académique en ligne, sans compte (ancien étudiant par
 * matricule, nouveau bachelier par n° de dossier ou de dépôt), paiement
 * KomiPay — portage de candidature/pages/inscription.php,
 * inscription_carte.php, recu.php et ajax_check_paiement.php.
 *
 * Différences voulues :
 *  - une inscription en ligne ne se consulte que depuis la session qui
 *    l'a ouverte, par le candidat propriétaire ou par la scolarité : saisir
 *    le matricule d'un étudiant déjà inscrit donnait accès à son reçu (et la
 *    vérification « déjà inscrit » du portail renvoyait son nom à n'importe
 *    qui) ;
 *  - identification limitée à 10 essais par 10 minutes et par adresse IP
 *    (les matricules se suivent) ;
 *  - plus de paiement pour un étudiant sans filière : l'ancien code
 *    encaissait puis n'inscrivait pas l'étudiant, sans le dire ;
 *  - pas de second paiement tant qu'une transaction est en attente ; seuls
 *    les moyens activés sont acceptés (config komipay.moyens) ;
 *  - matricule et inscription créés sous verrou, une seule fois même si
 *    deux confirmations arrivent ensemble (le calcul « MAX + 1 » pouvait
 *    donner deux fois le même numéro) ; mode de paiement de l'inscription
 *    valide (« mynita », « visa_master » n'existent pas dans la base) ;
 *  - reçu : université, contacts et logos de l'université (UDH écrit en
 *    dur), QR code produit dans la page (service tiers avant).
 */
class InscriptionEnLigneController extends Controller
{
    private const SESSION = 'inscriptions_en_ligne';

    public function __construct(private readonly InscriptionEnLigne $service, private readonly KomiPay $komipay, private readonly UniversiteCourante $universite) {}

    public function formulaire(Request $request): View
    {
        return view('candidature.inscription', $this->commun() + ['etape' => 1, 'ins' => null,
            'restriction' => app(Candidatures::class)->config('ins_type_candidat', '')]);
    }

    public function identifier(Request $request): RedirectResponse
    {
        $retour = redirect()->route('candidature.inscription');
        if (! $this->service->ouverte()) {
            return $retour->with('erreur', app(Candidatures::class)->config('message_inscription_fermee', 'Les inscriptions académiques sont actuellement fermées.'));
        }
        $candidat = Auth::guard('candidat')->id();
        $r = $this->service->identifier((string) $request->input('identifiant'), $this->sigle(), $candidat ? (int) $candidat : null);
        if (isset($r['erreur'])) {
            return $retour->withInput()->with('erreur', $r['erreur']);
        }
        if (isset($r['deja'])) {
            return $this->autorise($request, $r['deja'])
                ? redirect()->route('candidature.inscription.etape', ['id' => $r['deja']->id, 'etape' => 5])
                : $retour->with('info', 'Vous êtes déjà inscrit(e) pour cette année. Votre reçu est disponible dans votre espace candidat, ou auprès du service de la scolarité.');
        }
        $request->session()->push(self::SESSION, $r['inscription']);

        return redirect()->route('candidature.inscription.etape', ['id' => $r['inscription'], 'etape' => 2]);
    }

    public function etape(Request $request, int $id, int $etape): View|RedirectResponse
    {
        $ins = $this->inscription($request, $id);
        if ($ins instanceof RedirectResponse) {
            return $ins;
        }
        $etape = match (true) {
            $ins->statut === 'complete' => 5,
            $ins->statut_paiement === 'valide' => 4,
            $ins->reference_paiement && $ins->statut_paiement === 'en_attente' && $etape !== 3 => 4,
            default => max(2, min(3, $etape)),
        };

        return view('candidature.inscription', $this->commun() + ['etape' => $etape, 'ins' => $ins, 'montant' => $this->service->montant((float) $ins->montant),
            'moyens' => array_intersect_key(InscriptionEnLigne::MOYENS, array_flip(config('komipay.moyens')))]);
    }

    public function payer(Request $request, int $id): RedirectResponse
    {
        $ins = $this->inscription($request, $id);
        if ($ins instanceof RedirectResponse) {
            return $ins;
        }
        $ici = redirect()->route('candidature.inscription.etape', ['id' => $id, 'etape' => 3]);
        $suite = redirect()->route('candidature.inscription.etape', ['id' => $id, 'etape' => 4]);
        if ($ins->statut_paiement === 'valide' || $ins->statut === 'complete') {
            return $suite;
        }
        $moyen = (string) $request->input('moyen');
        $enAttente = $ins->reference_paiement && $ins->statut_paiement === 'en_attente' && ! $this->service->expire($ins);
        if (! $enAttente && $moyen === 'visa_master' && in_array($moyen, config('komipay.moyens'), true)) {
            return redirect()->route('candidature.inscription.carte', ['id' => $id]);
        }
        $r = $this->service->lancerPaiement($ins, $moyen, (string) $request->input('telephone'));
        if (! $r['ok']) {
            return ($enAttente ? $suite : $ici)->with('erreur', $r['message']);
        }

        return $suite;
    }

    public function carte(Request $request, int $id): View|RedirectResponse
    {
        $ins = $this->inscription($request, $id);
        if ($ins instanceof RedirectResponse) {
            return $ins;
        }
        if (! in_array('visa_master', config('komipay.moyens'), true) || $ins->statut_paiement === 'valide') {
            return redirect()->route('candidature.inscription.etape', ['id' => $id, 'etape' => 3]);
        }

        return view('candidature.inscription_carte', $this->commun() + ['ins' => $ins, 'montant' => $this->service->montant((float) $ins->montant)]);
    }

    public function payerCarte(Request $request, int $id): RedirectResponse
    {
        $ins = $this->inscription($request, $id);
        if ($ins instanceof RedirectResponse) {
            return $ins;
        }
        $ici = redirect()->route('candidature.inscription.carte', ['id' => $id]);
        if (! in_array('visa_master', config('komipay.moyens'), true) || $ins->statut_paiement === 'valide') {
            return redirect()->route('candidature.inscription.etape', ['id' => $id, 'etape' => 4]);
        }
        $titulaire = trim((string) $request->input('nom_titulaire'));
        $numero = preg_replace('/\D/', '', (string) $request->input('numero_carte'));
        $expiration = trim((string) $request->input('expiration'));
        $cvv = trim((string) $request->input('cvv'));
        $erreurs = array_filter([
            $titulaire === '' ? 'Le nom du titulaire est obligatoire.' : null,
            strlen($numero) < 15 || strlen($numero) > 16 ? 'Numéro de carte invalide.' : null,
            ! preg_match('#^(0[1-9]|1[0-2])/\d{2}$#', $expiration) ? "Date d'expiration invalide (format MM/AA)." : null,
            ! preg_match('/^\d{3}$/', $cvv) ? 'CVV invalide (3 chiffres).' : null,
        ]);
        if ($erreurs) {
            return $ici->with('erreur', implode(' ', $erreurs));
        }
        $r = $this->komipay->payerCarte(['numero' => implode('-', str_split($numero, 4)), 'expiration' => $expiration, 'cvv' => $cvv],
            $this->service->montant((float) $ins->montant)['total'], $titulaire, 'INS'.$id.'-'.date('ymdHis'), $request->only(['javaEnabled', 'screenHeight', 'screenWidth', 'TZ']));
        unset($numero, $cvv);
        if (! $r['ok']) {
            return $ici->with('erreur', 'Paiement refusé : '.$r['message']);
        }
        DB::table('inscriptions_en_ligne')->where('id', $id)->update(['moyen_paiement' => 'visa_master', 'reference_paiement' => $r['reference_transaction'],
            'statut_paiement' => $r['etat'] === 'SUCCESS' ? 'valide' : 'en_attente', 'date_paiement' => $r['etat'] === 'SUCCESS' ? now() : null, 'updated_at' => now()]);
        // Authentification 3-D Secure : adresse de la banque fournie par KomiPay (https uniquement)
        if ($r['redirect_portail_auth'] && str_starts_with((string) $r['redirect_portail_auth'], 'https://')) {
            return redirect()->away($r['redirect_portail_auth']);
        }

        return redirect()->route('candidature.inscription.etape', ['id' => $id, 'etape' => 4]);
    }

    /** Suivi automatique du paiement (étape 4). */
    public function statut(Request $request, int $id): JsonResponse
    {
        $ins = $this->inscription($request, $id);
        if ($ins instanceof RedirectResponse) {
            return response()->json(['ok' => false, 'message' => 'Inscription introuvable.'], 404);
        }
        $statut = $this->service->suivre($ins);

        return response()->json(['ok' => true, 'statut' => $statut] + ($statut === 'expire' ? ['message' => 'Le délai de paiement a expiré.'] : []));
    }

    public function confirmer(Request $request, int $id): RedirectResponse
    {
        $ins = $this->inscription($request, $id);
        if ($ins instanceof RedirectResponse) {
            return $ins;
        }
        // Vérification réelle auprès de KomiPay — jamais sur la seule parole du navigateur
        $r = $this->service->confirmer($ins);
        if (! $r['ok']) {
            return redirect()->route('candidature.inscription.etape', ['id' => $id, 'etape' => ($r['recommencer'] ?? false) ? 3 : 4])->with('erreur', $r['message']);
        }

        return redirect()->route('candidature.inscription.etape', ['id' => $id, 'etape' => 5]);
    }

    public function recu(Request $request, Identite $identite, Parametres $parametres, int $id): View|RedirectResponse
    {
        $ins = $this->inscription($request, $id);
        if ($ins instanceof RedirectResponse) {
            return $ins;
        }
        if ($ins->statut !== 'complete') {
            return redirect()->route('candidature.inscription.etape', ['id' => $id, 'etape' => 4])->with('erreur', 'Reçu disponible après la validation du paiement.');
        }
        $u = $this->universite->get();
        $logos = $identite->logos();

        return view('candidature.recu', ['ins' => $ins, 'montant' => $this->service->montant((float) $ins->montant), 'universite' => $u,
            'logoGauche' => $logos['gauche'], 'logoDroit' => $logos['droite'], 'telephone' => $parametres->valeur('institution_tel', ''),
            'adresse' => $parametres->valeur('institution_bp', trim($u->ville.', Niger', ', ')), 'email' => $parametres->valeur('institution_email', ''),
            'ville' => $u->ville ?: 'Niamey', 'moyen' => InscriptionEnLigne::MOYENS[$ins->moyen_paiement] ?? $ins->moyen_paiement]);
    }

    /** Inscription consultable par ce visiteur, avec ses données ; sinon retour à l'identification. */
    private function inscription(Request $request, int $id): object
    {
        $ins = $this->service->trouver($id);

        return $ins && $this->autorise($request, $ins) ? $ins
            : redirect()->route('candidature.inscription')->with('erreur', 'Identifiez-vous pour poursuivre votre inscription.');
    }

    /** Session qui l'a ouverte, candidat propriétaire connecté, ou personnel de la scolarité. */
    private function autorise(Request $request, object $ins): bool
    {
        if (in_array((int) $ins->id, array_map('intval', (array) $request->session()->get(self::SESSION, [])), true)) {
            return true;
        }
        $candidat = Auth::guard('candidat')->user();
        if ($candidat && $ins->candidat_id && (int) $ins->candidat_id === (int) $candidat->id
            && $request->session()->get('candidat_universite') === $this->universite->get()->code) {
            return true;
        }

        return in_array(Auth::guard('web')->user()?->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite'], true);
    }

    private function commun(): array
    {
        return ['universite' => $this->universite->get(), 'sigle' => $this->sigle(), 'annee' => $this->service->anneeActive()?->libelle ?? ''];
    }

    private function sigle(): string
    {
        $u = $this->universite->get();

        return $u->sigle ?: strtoupper($u->code);
    }
}
