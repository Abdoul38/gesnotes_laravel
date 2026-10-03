<?php

namespace App\Services\Communication;

use App\Services\Parametres;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Envoi d'e-mails avec le serveur SMTP configuré pour l'université
 * (Paramètres › Notif EMAIL) — remplace l'ancien includes/email_functions.php
 * (envoyerEmail). Tant que l'envoi est désactivé ou sans serveur, le message
 * est seulement journalisé (« simulé »), comme avant.
 *
 * Différences voulues :
 *  - envoi par Symfony Mailer au lieu d'un client SMTP fait main : en-têtes
 *    encodés et protégés (un sujet contenant un retour à la ligne ajoutait
 *    des en-têtes), « dot-stuffing » du corps (une ligne réduite à « . »
 *    terminait le message et laissait passer d'autres commandes SMTP) ;
 *  - le journal (email_logs) ne contient plus l'échange SMTP : celui-ci
 *    comprenait l'authentification, donc le mot de passe SMTP (en base64) ;
 *  - adresse d'expédition contrôlée.
 */
class Courriel
{
    /** Transport imposé (tests) ; sinon construit d'après les paramètres. */
    private ?TransportInterface $transport = null;

    public function __construct(private readonly Parametres $parametres) {}

    public function utiliserTransport(?TransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    public function actif(): bool
    {
        return $this->parametres->valeur('email_actif', '0') === '1' && $this->parametres->valeur('email_smtp_host') !== '';
    }

    /** Envoie un e-mail HTML ; vrai seulement s'il est réellement parti. */
    public function envoyer(string $destinataire, string $sujet, string $corpsHtml, string $contexte = '', ?int $refId = null): bool
    {
        $destinataire = trim($destinataire);
        if (! filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $sujet = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $sujet)), 0, 255);
        if ($this->transport === null && ! $this->actif()) {
            $this->journaliser($destinataire, $sujet, 'simule', $contexte, $refId, 'Aucun serveur SMTP configuré ou désactivé — message journalisé uniquement.');

            return false;
        }
        try {
            $user = $this->parametres->valeur('email_smtp_user');
            $expediteur = $this->parametres->valeur('email_from_adresse', $user);
            if (! filter_var($expediteur, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException("Adresse d'expédition invalide dans les paramètres.");
            }
            $email = (new Email)
                ->from(new Address($expediteur, $this->parametres->valeur('email_from_nom', 'EDUFILE')))
                ->to($destinataire)->subject($sujet)->html($corpsHtml);
            (new Mailer($this->transport ?? $this->transportSmtp()))->send($email);
            $this->journaliser($destinataire, $sujet, 'envoye', $contexte, $refId, 'Accepté par le serveur SMTP.');

            return true;
        } catch (Throwable $e) {
            // Message d'erreur seul (jamais l'échange complet, qui contient l'authentification)
            $this->journaliser($destinataire, $sujet, 'echec', $contexte, $refId, mb_substr($e->getMessage(), 0, 1000));

            return false;
        }
    }

    /** Texte brut → HTML : échappé, liens cliquables, retours à la ligne (ancien envoyerNotification). */
    public static function texteEnHtml(string $texte): string
    {
        $echappe = htmlspecialchars($texte, ENT_QUOTES, 'UTF-8');

        return nl2br(preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#0F2942;text-decoration:underline">$1</a>', $echappe));
    }

    private function transportSmtp(): EsmtpTransport
    {
        $chiffrement = $this->parametres->valeur('email_chiffrement', 'tls');
        $transport = new EsmtpTransport(
            $this->parametres->valeur('email_smtp_host'),
            (int) $this->parametres->valeur('email_smtp_port', '587'),
            $chiffrement === 'ssl',
        );
        if ($chiffrement === 'none') {
            $transport->setAutoTls(false);
        }
        if (($user = $this->parametres->valeur('email_smtp_user')) !== '') {
            $transport->setUsername($user);
            $transport->setPassword($this->parametres->brut('email_smtp_pass'));
        }

        return $transport;
    }

    private function journaliser(string $destinataire, string $sujet, string $statut, string $contexte, ?int $refId, string $detail): void
    {
        DB::table('email_logs')->insert([
            'destinataire' => mb_substr($destinataire, 0, 150), 'sujet' => $sujet, 'statut' => $statut,
            'contexte' => mb_substr($contexte, 0, 50) ?: null, 'reference_id' => $refId, 'reponse_brute' => $detail,
        ]);
    }
}
