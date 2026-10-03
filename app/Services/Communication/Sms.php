<?php

namespace App\Services\Communication;

use App\Services\Parametres;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Envoi de SMS par la passerelle configurée (Paramètres › Notif SMS) —
 * remplace l'ancien includes/sms_functions.php (envoyerSMS) : requête POST
 * JSON {to, message, sender_id} avec la clé en « Bearer ». Sans passerelle
 * active, le message est seulement journalisé (« simulé »).
 *
 * Différences voulues : adresse de la passerelle en https obligatoire (la
 * clé API partait vers n'importe quelle adresse saisie) ; numéro contrôlé
 * (6 à 20 chiffres) ; réponse du fournisseur tronquée dans le journal.
 */
class Sms
{
    public function __construct(private readonly Parametres $parametres) {}

    public function actif(): bool
    {
        return $this->parametres->valeur('sms_actif', '0') === '1'
            && self::urlValide($this->parametres->valeur('sms_provider_url'))
            && $this->parametres->brut('sms_api_key') !== '';
    }

    public static function urlValide(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://');
    }

    public static function numero(string $numero): string
    {
        $n = preg_replace('/[^0-9+]/', '', $numero);

        return preg_match('/^\+?\d{6,20}$/', $n) ? $n : '';
    }

    public function envoyer(string $numero, string $message, string $contexte = '', ?int $refId = null): bool
    {
        $numero = self::numero($numero);
        if ($numero === '') {
            return false;
        }
        if (! $this->actif()) {
            $this->journaliser($numero, $message, 'simule', $contexte, $refId, 'Aucun fournisseur SMS configuré ou désactivé — message journalisé uniquement.');

            return false;
        }
        try {
            $reponse = Http::timeout(10)->withToken($this->parametres->brut('sms_api_key'))->acceptJson()
                ->post($this->parametres->valeur('sms_provider_url'), [
                    'to' => $numero, 'message' => $message, 'sender_id' => $this->parametres->valeur('sms_sender_id', 'EDUFILE'),
                ]);
            $succes = $reponse->successful();
            $this->journaliser($numero, $message, $succes ? 'envoye' : 'echec', $contexte, $refId, 'HTTP '.$reponse->status().' — '.mb_substr($reponse->body(), 0, 900));

            return $succes;
        } catch (Throwable $e) {
            $this->journaliser($numero, $message, 'echec', $contexte, $refId, 'Erreur réseau : '.mb_substr($e->getMessage(), 0, 900));

            return false;
        }
    }

    private function journaliser(string $numero, string $message, string $statut, string $contexte, ?int $refId, string $detail): void
    {
        DB::table('sms_logs')->insert([
            'numero' => mb_substr($numero, 0, 30), 'message' => $message, 'statut' => $statut,
            'contexte' => mb_substr($contexte, 0, 50) ?: null, 'reference_id' => $refId, 'reponse_brute' => $detail,
        ]);
    }
}
