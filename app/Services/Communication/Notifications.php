<?php

namespace App\Services\Communication;

use App\Navigation\Menu;
use App\Services\Pv;
use Illuminate\Support\Facades\DB;

/**
 * Notifications multi-canal — anciens envoyerNotification() et
 * notifierResultatsPv() (includes/email_functions.php) : un message part
 * par e-mail et par SMS selon ce qui est configuré et connu du destinataire.
 */
class Notifications
{
    public function __construct(private readonly Courriel $courriel, private readonly Sms $sms, private readonly Pv $pvs, private readonly Menu $menu) {}

    /** @return array{email: bool, sms: bool} */
    public function envoyer(?string $email, ?string $telephone, string $sujet, string $texte, string $contexte = '', ?int $refId = null): array
    {
        return [
            'email' => $email ? $this->courriel->envoyer($email, $sujet, Courriel::texteEnHtml($texte), $contexte, $refId) : false,
            'sms' => $telephone ? $this->sms->envoyer($telephone, $texte, $contexte, $refId) : false,
        ];
    }

    /** Le PV a-t-il déjà été notifié (envoi réel ou simulé) ? */
    public function pvDejaNotifie(int $pvId): bool
    {
        return DB::table('email_logs')->where(['contexte' => 'pv_publie', 'reference_id' => $pvId])->exists()
            || DB::table('sms_logs')->where(['contexte' => 'pv_publie', 'reference_id' => $pvId])->exists();
    }

    /**
     * Prévient les étudiants ayant un résultat dans le PV, et/ou leurs
     * parents liés (comptes actifs), que les résultats sont disponibles.
     *
     * @return array{destinataires: int, emails: int, sms: int}
     */
    public function notifierPv(int $pvId, bool $etudiants = true, bool $parents = true): array
    {
        $bilan = ['destinataires' => 0, 'emails' => 0, 'sms' => 0];
        $pv = $this->pvs->trouver($pvId);
        if (! $pv) {
            return $bilan;
        }
        $sujet = "Résultats disponibles — {$pv->filiere} {$pv->niveau} {$pv->semestre}";
        $application = config('app.name');
        $compter = function (array $r) use (&$bilan) {
            $bilan['destinataires']++;
            $bilan['emails'] += (int) $r['email'];
            $bilan['sms'] += (int) $r['sms'];
        };
        $liste = DB::table('resultats_semestre as rs')->join('etudiants as e', 'e.id', '=', 'rs.etudiant_id')
            ->where('rs.pv_id', $pvId)->distinct()->get(['e.id', 'e.nom', 'e.prenoms', 'e.email', 'e.telephone', 'rs.moyenne_generale', 'rs.valide', 'rs.decision']);
        $avecResultat = app(AlertesParents::class)->active('parents_alerte_resultats');
        $push = app(Push::class);
        foreach ($liste as $et) {
            if ($etudiants) {
                $push->etudiant((int) $et->id, 'Résultats disponibles', "Les résultats du {$pv->semestre} sont publiés.", ['type' => 'resultats']);
                $texte = "Bonjour {$et->prenoms} {$et->nom},\n\nLes résultats du {$pv->semestre} ({$pv->filiere} {$pv->niveau}) sont maintenant disponibles. "
                    ."Connectez-vous à votre portail pour les consulter :\n".route('login')."\n\n$application";
                $compter($this->envoyer($et->email, $et->telephone, $sujet, $texte, 'pv_publie', $pvId));
            }
            if ($parents) {
                $push->parentsDe((int) $et->id, 'Résultats de '.$et->prenoms, "Les résultats du {$pv->semestre} sont publiés"
                    .($et->moyenne_generale !== null && $avecResultat ? ' : moyenne '.number_format((float) $et->moyenne_generale, 2, ',', ' ').'/20.' : '.'), ['type' => 'resultats']);
                $proches = DB::table('parents_etudiants as pe')->join('parents as p', 'p.id', '=', 'pe.parent_id')
                    ->where('pe.etudiant_id', $et->id)->where('p.actif', 1)->get(['p.email', 'p.telephone']);
                foreach ($proches as $p) {
                    // Moyenne et décision dans le message : utile aux parents sans smartphone (réglage « résultats »)
                    $resultat = $avecResultat && $et->moyenne_generale !== null
                        ? ' Moyenne : '.number_format((float) $et->moyenne_generale, 2, ',', ' ').'/20 — '.($et->valide ? 'semestre validé' : ($et->decision ?: 'semestre non validé')).'.' : '';
                    $texte = "Bonjour,\n\nLes résultats de {$et->prenoms} {$et->nom} pour le {$pv->semestre} ({$pv->filiere} {$pv->niveau}) sont disponibles.$resultat "
                        ."Détails sur l'Espace Parents :\n".$this->menu->lien('login_parent')."\n\n$application";
                    $compter($this->envoyer($p->email, $p->telephone, $sujet, $texte, 'pv_publie', $pvId));
                }
            }
        }

        return $bilan;
    }
}
