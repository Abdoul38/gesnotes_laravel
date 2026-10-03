<?php

namespace App\Services\Paiement;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client de l'API KomiPay (B2C standard) — portage de
 * candidature/includes/komipay.php : jeton, paiement Mobile Money, STA
 * (MyNita, Amana), carte bancaire, vérification d'une transaction.
 *
 * Différences voulues :
 *  - identifiants lus dans .env (ils étaient en clair dans le dépôt) ;
 *  - jeton mis en cache côté serveur (il l'était dans la session de
 *    chaque visiteur) ;
 *  - le journal ne garde que le code HTTP et le message, jamais la réponse
 *    complète ;
 *  - vérification d'une transaction limitée à 25 secondes (jusqu'à
 *    5 minutes avant : la page restait bloquée).
 */
class KomiPay
{
    public function configure(): bool
    {
        return filled(config('komipay.login')) && filled(config('komipay.api_key'));
    }

    /** Mobile Money opérateur (synchrone : ok = paiement effectué). */
    public function payerMobileMoney(string $operateur, float $montant, string $telephone, string $nom, string $reference): array
    {
        $r = $this->appel('b2c_standard', ['mobile_money' => $operateur, 'api_key' => config('komipay.api_key'),
            'montant_a_payer' => $this->montant($montant), 'numero_telephone_payeur' => $telephone, 'nom_prenom_payeur' => $nom,
            'reference_externe' => $reference, 'auto_confirm' => '1']);

        return ['ok' => ! empty($r['statut']), 'reference_transaction' => $r['reference_transaction'] ?? null,
            'message' => ($r['message'] ?? '') ?: (! empty($r['code']) ? 'Erreur KomiPay code '.$r['code'] : "Paiement refusé par l'opérateur (réponse vide).")];
    }

    /** MyNita (nita_transfert) / Amana (amana_transfert) : le payeur valide ensuite sur son compte. */
    public function payerSTA(string $operateur, float $montant, string $telephone, string $pays, string $reference): array
    {
        $r = $this->appel('b2c_standard', ['mobile_money' => $operateur, 'api_key' => config('komipay.api_key'),
            'montant_a_payer' => $this->montant($montant), 'numero_telephone_payeur' => $telephone, 'pays_payeur' => $pays,
            'reference_externe' => $reference]);

        return ['ok' => ! empty($r['statut']), 'reference_transaction' => $r['reference_transaction'] ?? null,
            'code_achat' => $r['code_achat'] ?? null, 'message' => $r['message'] ?? ''];
    }

    /**
     * Carte bancaire : CVV chiffré par KomiPay puis paiement. Les données de
     * carte ne sont ni stockées ni journalisées.
     */
    public function payerCarte(array $carte, float $montant, string $nom, string $reference, array $navigateur): array
    {
        $cvv = $this->appel('crypt-cvv', ['api_key' => config('komipay.api_key'), 'cvv_number' => $carte['cvv']], true);
        if (empty($cvv['statut']) || empty($cvv['cvv_encrpyt'])) {
            return ['ok' => false, 'message' => $cvv['message'] ?? 'Échec du cryptage CVV.'];
        }
        $r = $this->appel('b2c_standard', ['mobile_money' => 'bank_card', 'api_key' => config('komipay.api_key'), 'nom_prenom_payeur' => $nom,
            'numero_carte_bancaire' => $carte['numero'], 'date_expiration' => $carte['expiration'], 'cvv_number' => $cvv['cvv_encrpyt'],
            'montant_a_payer' => $this->montant($montant), 'reference_externe' => $reference,
            'javaEnabled' => (bool) ($navigateur['javaEnabled'] ?? false), 'javascriptEnabled' => true,
            'screenHeight' => (string) ($navigateur['screenHeight'] ?? 1080), 'screenWidth' => (string) ($navigateur['screenWidth'] ?? 1920),
            'TZ' => (string) ($navigateur['TZ'] ?? 0), 'challengeWindowSize' => (string) ($navigateur['challengeWindowSize'] ?? '05')], true);

        return ['ok' => ! empty($r['statut']), 'etat' => $r['etat'] ?? null, 'reference_transaction' => $r['dataTransaction']['reference_transaction'] ?? null,
            'redirect_portail_auth' => $r['redirect_portail_auth'] ?? null, 'message' => $r['message'] ?? 'Échec du paiement par carte.'];
    }

    /** Statut d'une transaction : 'Success', 'Echec' ou 'Inconnu'. */
    public function verifier(string $reference, int $timeout = 25): array
    {
        $r = $this->appel('check-transaction-status', ['apikey' => config('komipay.api_key'), 'reference_transaction' => $reference], false, $timeout);

        return ['ok' => ! empty($r['statut']), 'statut_transaction' => $r['data']['transactionStatus'] ?? 'Inconnu', 'message' => $r['message'] ?? ''];
    }

    private function jeton(): ?string
    {
        $cle = 'komipay_jeton_'.md5(config('komipay.env').'|'.config('komipay.login'));
        if ($jeton = Cache::get($cle)) {
            return $jeton;
        }
        $r = $this->requete($this->base().'/generateToken', ['login' => config('komipay.login'), 'password' => config('komipay.password'),
            'api_key' => config('komipay.api_key')], []);
        if (empty($r['statut']) || empty($r['token'])) {
            return null;
        }
        Cache::put($cle, $r['token'], now()->addMinutes(25)); // valable 30 minutes

        return $r['token'];
    }

    private function appel(string $chemin, array $corps, bool $avecKeypass = false, int $timeout = 20): array
    {
        if (! $this->configure()) {
            return ['statut' => false, 'message' => "Le paiement en ligne n'est pas configuré. Contactez la scolarité."];
        }
        $jeton = $this->jeton();
        if (! $jeton) {
            return ['statut' => false, 'message' => 'Service de paiement indisponible pour le moment. Réessayez plus tard.'];
        }
        $entetes = ['Authorization' => 'Bearer '.$jeton] + ($avecKeypass ? ['keypass' => config('komipay.keypass')] : []);

        return $this->requete($this->base().'/customers-api-kp/v1/'.$chemin, $corps, $entetes, $timeout);
    }

    private function requete(string $url, array $corps, array $entetes, int $timeout = 20): array
    {
        try {
            $reponse = Http::withHeaders($entetes)->acceptJson()->connectTimeout(10)->timeout($timeout)->post($url, $corps);
        } catch (Throwable $e) {
            Log::warning('KomiPay injoignable', ['url' => parse_url($url, PHP_URL_PATH), 'erreur' => $e->getMessage()]);

            return ['statut' => false, 'message' => 'Le service de paiement met du temps à répondre. Veuillez réessayer dans quelques instants.'];
        }
        $donnees = $reponse->json();
        Log::info('KomiPay', ['url' => parse_url($url, PHP_URL_PATH), 'http' => $reponse->status(),
            'statut' => is_array($donnees) ? ($donnees['statut'] ?? null) : null, 'message' => is_array($donnees) ? mb_substr((string) ($donnees['message'] ?? ''), 0, 200) : null]);

        return is_array($donnees) ? $donnees : ['statut' => false, 'message' => 'Réponse KomiPay invalide (HTTP '.$reponse->status().').'];
    }

    private function base(): string
    {
        return config('komipay.env') === 'live' ? config('komipay.url_live') : config('komipay.url_sandbox');
    }

    /** Format « 5 000 » attendu par KomiPay. */
    private function montant(float $montant): string
    {
        return number_format((int) $montant, 0, '.', ' ');
    }
}
