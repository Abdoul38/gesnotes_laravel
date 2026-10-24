<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Navigation\Menu;
use App\Services\Communication\Notifications;
use App\Services\Communication\Push;
use App\Services\Journal;
use App\Services\Paiements;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Relances des impayés — portage de pages/communication/relances.php :
 * paiements en attente ou partiels depuis plus de N jours, relance (e-mail,
 * SMS) de l'étudiant et de ses parents liés, individuelle ou groupée
 * (permission « paiements_gerer »).
 *
 * Différences voulues :
 *  - seulement les impayés du périmètre de l'agent et encore dus (n'importe
 *    quel identifiant de paiement était accepté, même soldé) ;
 *  - le bouton d'une ligne relance bien cet étudiant : son formulaire était
 *    imbriqué dans celui de la relance groupée, et le navigateur envoyait la
 *    ligne… du dernier étudiant de la liste ;
 *  - contact = e-mail et téléphone de la fiche étudiant, à défaut l'e-mail du
 *    compte (seul l'e-mail du compte, souvent généré, était utilisé, et
 *    jamais de SMS : la table utilisateurs n'a pas de téléphone) ;
 *  - relance groupée : un étudiant déjà relancé dans les dernières 24 h est
 *    sauté (protection contre les envois répétés) ; envois journalisés.
 */
class RelancesController extends Controller
{
    private const SEUILS = [7 => '7 jours', 15 => '15 jours', 30 => '30 jours', 60 => '60 jours'];

    public function __construct(private readonly Paiements $paiements) {}

    public function index(Request $request): View
    {
        $seuil = max(1, min(3650, (int) $request->query('seuil', 30)));
        $retards = $this->impayes($request, $seuil)
            ->selectRaw('DATEDIFF(NOW(), p.created_at) AS jours_retard')->orderByDesc('jours_retard')->get();

        return view('communication.relances', [
            'retards' => $retards,
            'seuil' => $seuil,
            'seuils' => self::SEUILS,
            'totalImpaye' => $retards->sum(fn ($r) => (float) $r->montant_du - (float) $r->montant_paye),
            'nbSansContact' => $retards->filter(fn ($r) => ! $r->email && ! $r->telephone)->count(),
            'peutRelancer' => $request->user()->autorise('paiements_gerer'),
        ]);
    }

    public function relancer(Request $request, Notifications $notifications, Journal $journal, Menu $menu): RedirectResponse
    {
        $groupe = $request->input('mode') === 'groupe';
        $ids = $groupe ? array_map('intval', (array) $request->input('paiement_ids', [])) : [(int) $request->input('paiement_id')];
        $retour = redirect()->route('relances', ['seuil' => (int) $request->input('seuil', 30) ?: null]);
        if (! array_filter($ids)) {
            return $retour->with('erreur', 'Sélectionnez au moins un étudiant.');
        }
        $lignes = $this->impayes($request, 0)->whereIn('p.id', $ids)->get();
        if ($lignes->isEmpty()) {
            return $retour->with('erreur', 'Paiement introuvable ou déjà réglé.');
        }

        $ok = $echecs = $sautes = 0;
        $dernierMessage = '';
        foreach ($lignes as $p) {
            if ($groupe && $p->derniere_relance && strtotime($p->derniere_relance) > time() - 86400) {
                $sautes++;

                continue;
            }
            [$succes, $dernierMessage] = $this->relancerUn($p, $notifications, $menu);
            $succes ? $ok++ : $echecs++;
        }
        $journal->action('envoyer', 'relance', "Relance(s) d'impayés : $ok réussie(s), $echecs échec(s), $sautes sautée(s)");

        if (! $groupe) {
            return $retour->with($ok ? 'succes' : 'erreur', $dernierMessage);
        }

        return $retour->with($echecs ? 'erreur' : 'succes', "Relances envoyées : $ok réussie(s)"
            .($echecs ? ", $echecs échec(s) (vérifiez les contacts et la configuration email/SMS)" : '')
            .($sautes ? ", $sautes déjà relancé(s) dans les dernières 24 h" : ''));
    }

    /** @return array{0: bool, 1: string} */
    private function relancerUn(object $p, Notifications $notifications, Menu $menu): array
    {
        if (! $p->email && ! $p->telephone) {
            return [false, "Pas d'email ni de téléphone pour {$p->nom} {$p->prenoms}."];
        }
        $reste = number_format((float) $p->montant_du - (float) $p->montant_paye, 0, '.', ' ');
        $type = ucfirst($p->type_paiement);
        $application = config('app.name');
        $sujet = "Rappel de paiement — $application";
        $texte = "Bonjour {$p->prenoms} {$p->nom},\n\nNous vous rappelons qu'un solde de $reste FCFA reste dû au titre de : $type.\n"
            ."Merci de régulariser votre situation auprès du service scolarité.\n\nConnectez-vous à votre portail :\n".route('login')."\n\nCordialement,\n$application";
        $res = $notifications->envoyer($p->email, $p->telephone, $sujet, $texte, 'paiement_retard', (int) $p->id);

        $nbParents = 0;
        // Notification sur téléphone (étudiant et parents)
        app(Push::class)->etudiant((int) $p->etudiant_id, 'Paiement en attente', 'Un paiement de frais est en retard : merci de régulariser auprès de la scolarité.', ['type' => 'paiement']);
        app(Push::class)->parentsDe((int) $p->etudiant_id, 'Paiement en attente', 'Un paiement de frais de '.$p->prenoms.' est en retard.', ['type' => 'paiement']);
        $parents = DB::table('parents_etudiants as pe')->join('parents as pa', 'pa.id', '=', 'pe.parent_id')
            ->where('pe.etudiant_id', $p->etudiant_id)->where('pa.actif', 1)->get(['pa.email', 'pa.telephone']);
        foreach ($parents as $parent) {
            $texteParent = "Bonjour,\n\nNous vous rappelons qu'un solde de $reste FCFA reste dû pour {$p->prenoms} {$p->nom} au titre de : $type.\n"
                ."Merci de régulariser la situation auprès du service scolarité.\n\nConnectez-vous à l'Espace Parents :\n".$menu->lien('login_parent')."\n\nCordialement,\n$application";
            $r = $notifications->envoyer($parent->email, $parent->telephone, $sujet, $texteParent, 'paiement_retard', (int) $p->id);
            $nbParents += (int) ($r['email'] || $r['sms']);
        }

        if ($res['email'] || $res['sms']) {
            DB::table('paiements')->where('id', $p->id)->update(['derniere_relance' => now()]);
            $canaux = implode(' + ', array_filter([$res['email'] ? 'email' : null, $res['sms'] ? 'SMS' : null]));

            return [true, "Relance envoyée à {$p->nom} {$p->prenoms} ($canaux)".($nbParents ? " + $nbParents parent(s)" : '').'.'];
        }

        return [false, "Échec d'envoi pour {$p->nom} {$p->prenoms} — vérifiez la configuration email/SMS dans Paramètres."];
    }

    /** Impayés du périmètre de l'agent, en retard de plus de $seuil jours, avec le contact de l'étudiant. */
    private function impayes(Request $request, int $seuil): Builder
    {
        return $this->paiements->requete($request->user())
            ->whereIn('p.statut', ['en_attente', 'partiel'])->whereColumn('p.montant_paye', '<', 'p.montant_du')
            ->when($seuil, fn ($q) => $q->where('p.created_at', '<', now()->subDays($seuil)))
            ->select(['p.*', 'e.nom', 'e.prenoms', 'e.numero',
                DB::raw("COALESCE(NULLIF(e.email, ''), (SELECT u.email FROM utilisateurs u WHERE u.etudiant_id = e.id ORDER BY u.id LIMIT 1)) AS email"),
                DB::raw("NULLIF(e.telephone, '') AS telephone")]);
    }
}
