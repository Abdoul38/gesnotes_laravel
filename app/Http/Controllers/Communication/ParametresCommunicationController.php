<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Services\Communication\AlertesParents;
use App\Services\Communication\Courriel;
use App\Services\Communication\Sms;
use App\Services\Journal;
use App\Services\Parametres;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Configuration des envois — portage de pages/admin/parametres_email.php
 * (serveur SMTP) et pages/admin/parametres_sms.php (passerelle SMS), avec
 * envoi de test, résumé et journal des 20 derniers envois.
 *
 * Différences voulues :
 *  - réservé à l'administrateur : un agent de faculté, un doyen ou un chef de
 *    département pouvait remplacer le serveur SMTP de toute l'université
 *    (et recevoir ainsi les liens de réinitialisation de mot de passe de
 *    tous les comptes) ou la passerelle SMS (qui recevait aussi la clé API) ;
 *  - la clé API SMS n'est plus renvoyée au navigateur (elle s'affichait en
 *    clair) : laissée vide, elle est conservée, comme le mot de passe SMTP ;
 *  - passerelle SMS en https obligatoire ; hôte, port, adresses et nom
 *    d'expéditeur contrôlés ; modifications journalisées (sans les secrets).
 */
class ParametresCommunicationController extends Controller
{
    public function __construct(private readonly Parametres $parametres, private readonly Journal $journal) {}

    public function email(): View
    {
        return view('communication.parametres_email', [
            'p' => fn (string $cle, string $defaut = '') => $this->parametres->valeur($cle, $defaut),
            'motDePasseDefini' => $this->parametres->brut('email_smtp_pass') !== '',
        ] + $this->journal('email_logs'));
    }

    public function sms(): View
    {
        return view('communication.parametres_sms', [
            'p' => fn (string $cle, string $defaut = '') => $this->parametres->valeur($cle, $defaut),
            'cleDefinie' => $this->parametres->brut('sms_api_key') !== '',
        ] + $this->journal('sms_logs'));
    }

    public function enregistrerEmail(Request $request): RedirectResponse
    {
        $v = Validator::make($request->all(), [
            'email_smtp_host' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'email_smtp_port' => 'nullable|integer|min:1|max:65535',
            'email_smtp_user' => 'nullable|string|max:190',
            'email_smtp_pass' => 'nullable|string|max:255',
            'email_from_adresse' => 'nullable|email|max:190',
            'email_from_nom' => ['nullable', 'string', 'max:100', 'regex:/^[^\r\n<>"]*$/'],
            'email_chiffrement' => 'nullable|in:tls,ssl,none',
        ], [], ['email_smtp_host' => 'serveur SMTP', 'email_smtp_port' => 'port', 'email_from_adresse' => "adresse d'expédition", 'email_from_nom' => "nom d'expéditeur"]);
        if ($v->fails()) {
            return redirect()->route('parametres_email')->with('erreur', 'Paramètres non enregistrés : '.$v->errors()->first());
        }
        $d = $v->validated();
        $champs = [
            'email_smtp_host' => trim($d['email_smtp_host'] ?? ''),
            'email_smtp_port' => (string) ($d['email_smtp_port'] ?? 587),
            'email_smtp_user' => trim($d['email_smtp_user'] ?? ''),
            'email_from_adresse' => trim($d['email_from_adresse'] ?? ''),
            'email_from_nom' => trim($d['email_from_nom'] ?? '') ?: 'EDUFILE',
            'email_chiffrement' => $d['email_chiffrement'] ?? 'tls',
            'email_actif' => $request->boolean('email_actif') ? '1' : '0',
        ];
        // Le mot de passe ne change que si un nouveau est saisi (ancienne règle)
        if (trim($d['email_smtp_pass'] ?? '') !== '') {
            $champs['email_smtp_pass'] = trim($d['email_smtp_pass']);
        }
        $this->enregistrer($champs);
        $this->journal->action('modifier', 'parametres', 'Configuration e-mail : serveur '.($champs['email_smtp_host'] ?: '—').', envoi '
            .($champs['email_actif'] === '1' ? 'actif' : 'simulé').(isset($champs['email_smtp_pass']) ? ', mot de passe changé' : ''));

        return redirect()->route('parametres_email')->with('succes', 'Paramètres email enregistrés.');
    }

    public function enregistrerSms(Request $request): RedirectResponse
    {
        $v = Validator::make($request->all(), [
            'sms_provider_url' => 'nullable|string|max:255',
            'sms_api_key' => 'nullable|string|max:255',
            'sms_sender_id' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ]*$/'],
        ], [], ['sms_sender_id' => "nom de l'expéditeur"]);
        $url = trim((string) $request->input('sms_provider_url'));
        if ($v->fails() || ($url !== '' && ! Sms::urlValide($url))) {
            return redirect()->route('parametres_sms')->with('erreur', 'Paramètres non enregistrés : '
                .($v->fails() ? $v->errors()->first() : "l'adresse de la passerelle doit être une URL https."));
        }
        $champs = [
            'sms_provider_url' => $url,
            'sms_sender_id' => trim((string) $request->input('sms_sender_id')) ?: 'EDUFILE',
            'sms_actif' => $request->boolean('sms_actif') ? '1' : '0',
        ];
        // Alertes envoyées aux parents (chaque type activable)
        foreach (array_keys(AlertesParents::REGLAGES) as $cle) {
            $champs[$cle] = $request->boolean($cle) ? '1' : '0';
        }
        if (trim((string) $request->input('sms_api_key')) !== '') {
            $champs['sms_api_key'] = trim((string) $request->input('sms_api_key'));
        }
        $this->enregistrer($champs);
        $this->journal->action('modifier', 'parametres', 'Configuration SMS : passerelle '.($url ?: '—').', envoi '
            .($champs['sms_actif'] === '1' ? 'actif' : 'simulé').(isset($champs['sms_api_key']) ? ', clé changée' : ''));

        return redirect()->route('parametres_sms')->with('succes', 'Paramètres SMS enregistrés.');
    }

    public function testerEmail(Request $request, Courriel $courriel): RedirectResponse
    {
        $adresse = trim((string) $request->input('email_test'));
        if (! filter_var($adresse, FILTER_VALIDATE_EMAIL)) {
            return redirect()->route('parametres_email')->with('erreur', 'Adresse email de test invalide.');
        }
        $ok = $courriel->envoyer($adresse, 'Test EDUFILE', '<p>Ceci est un email de test envoyé depuis EDUFILE.</p>', 'test');

        return redirect()->route('parametres_email')->with($ok ? 'succes' : 'erreur', $ok
            ? "Email de test envoyé avec succès à $adresse."
            : "Échec de l'envoi — voir le journal ci-dessous pour le détail, ou vérifiez la configuration du serveur.");
    }

    public function testerSms(Request $request, Sms $sms): RedirectResponse
    {
        $numero = Sms::numero((string) $request->input('numero_test'));
        if ($numero === '') {
            return redirect()->route('parametres_sms')->with('erreur', 'Merci de saisir un numéro valide pour le test.');
        }
        $ok = $sms->envoyer($numero, 'Ceci est un message de test envoyé depuis EDUFILE.', 'test');

        return redirect()->route('parametres_sms')->with($ok ? 'succes' : 'erreur', $ok
            ? "SMS de test envoyé avec succès à $numero."
            : "Échec de l'envoi — voir le journal ci-dessous pour le détail, ou vérifiez qu'un fournisseur est bien configuré et activé.");
    }

    private function enregistrer(array $champs): void
    {
        foreach ($champs as $cle => $valeur) {
            DB::table('parametres')->upsert([['cle' => $cle, 'valeur' => $valeur]], ['cle'], ['valeur']);
        }
        $this->parametres->oublier();
    }

    private function journal(string $table): array
    {
        return [
            'logs' => DB::table($table)->orderByDesc('id')->limit(20)->get(),
            'stats' => DB::table($table)->selectRaw("SUM(statut='envoye') AS envoyes, SUM(statut='echec') AS echecs, SUM(statut='simule') AS simules")->first(),
        ];
    }
}
