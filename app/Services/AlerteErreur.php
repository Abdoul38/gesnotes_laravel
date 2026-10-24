<?php

namespace App\Services;

use App\Services\Communication\Courriel;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alerte par e-mail quand une erreur serveur se produit (page qui plante),
 * à l'adresse ALERTE_ERREURS_EMAIL (à défaut SAUVEGARDES_AUTO_ALERTE), sur
 * le serveur de production seulement (APP_ENV=production).
 *
 *  - seules les vraies erreurs : Laravel écarte déjà les 404, 403, formulaires
 *    invalides, sessions expirées… ;
 *  - une même erreur (classe, fichier, ligne) au plus une fois par heure, et
 *    20 alertes par heure au total : pas d'avalanche de messages ;
 *  - contenu : message, emplacement, adresse demandée, utilisateur (rôle),
 *    université, début de la pile d'appels — jamais les données saisies
 *    (mots de passe, notes…) ;
 *  - envoyée par le serveur SMTP de l'université par défaut ; un échec
 *    d'envoi est seulement journalisé.
 */
class AlerteErreur
{
    private static bool $enCours = false;

    public function __construct(private readonly RegistreUniversites $registre, private readonly UniversiteCourante $universite) {}

    public static function adresse(): ?string
    {
        $a = config('gesnotes.alerte_erreurs') ?: config('gesnotes.sauvegardes_auto.alerte');

        return filled($a) ? (string) $a : null;
    }

    public function signaler(Throwable $e): void
    {
        $adresse = self::adresse();
        // Serveur de production seulement (pas les essais sur un PC de développement)
        $actif = app()->runningUnitTests() ? config('gesnotes.alerte_erreurs_tests') : app()->environment('production');
        if (! $adresse || self::$enCours || ! $actif) {
            return;
        }
        $signature = sha1(get_class($e).'|'.$e->getFile().'|'.$e->getLine());
        if (! Cache::add('alerte_erreur|'.$signature, 1, 3600)) {
            return;   // déjà signalée dans l'heure
        }
        Cache::add('alerte_erreur|total', 0, 3600);
        if (Cache::increment('alerte_erreur|total') > 20) {
            return;
        }
        self::$enCours = true;
        try {
            $courant = $this->universiteCourante();
            $requete = app()->runningInConsole() ? null : request();
            $u = $requete?->user();
            $lignes = [
                'Message' => mb_substr($e->getMessage(), 0, 500),
                'Type' => get_class($e),
                'Emplacement' => str_replace(base_path(), '', $e->getFile()).' : ligne '.$e->getLine(),
                'Adresse' => $requete ? $requete->method().' '.$requete->url() : 'commande / tâche planifiée',
                'Université' => $courant,
                'Utilisateur' => $u ? '#'.$u->id.' ('.($u->role ?? '—').')' : '—',
                'Date' => now()->format('d/m/Y H:i:s'),
            ];
            $html = '<h2 style="color:#b91c1c">Erreur sur '.e(config('app.name')).'</h2><table cellpadding="4" style="border-collapse:collapse">'
                .implode('', array_map(fn ($k, $v) => '<tr><td style="color:#555"><b>'.e($k).'</b></td><td>'.e((string) $v).'</td></tr>', array_keys($lignes), $lignes))
                .'</table><p><b>Début de la pile d\'appels :</b></p><pre style="font-size:11px;background:#f3f4f6;padding:8px">'
                .e(implode("\n", array_slice(explode("\n", str_replace(base_path(), '', $e->getTraceAsString())), 0, 12))).'</pre>'
                .'<p style="color:#777;font-size:12px">La même erreur ne sera plus signalée pendant une heure. Détails complets : storage/logs/laravel.log.</p>';
            $this->universite->pour($this->registre->parDefaut(),
                fn () => app(Courriel::class)->envoyer($adresse, '⚠️ Erreur serveur — '.config('app.name').' ('.$courant.')', $html, 'alerte_erreur'));
        } catch (Throwable $x) {
            Log::warning('Alerte d\'erreur non envoyée', ['erreur' => $x->getMessage()]);
        } finally {
            self::$enCours = false;
        }
    }

    private function universiteCourante(): string
    {
        try {
            $u = $this->universite->get();

            return $u->sigle ?: strtoupper($u->code);
        } catch (Throwable) {
            return '—';
        }
    }
}
