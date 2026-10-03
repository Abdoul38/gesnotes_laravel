<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Services\Communication\Notifications;
use App\Services\Journal;
use App\Services\Pv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Notifier un PV — portage de pages/notes_pv/notifier_pv.php : envoi manuel
 * (e-mail et SMS) aux étudiants d'un PV et/ou à leurs parents, avec
 * l'historique des envois du PV.
 *
 * Différences voulues :
 *  - seulement pour un PV publié : on pouvait annoncer « résultats
 *    disponibles » pour un PV encore en brouillon ;
 *  - réservé aux gestionnaires de PV (admin, secrétaire, sc_centrale,
 *    sc_entite) et aux PV de leur périmètre ; envoi journalisé ;
 *  - au moins un public coché (sinon rien n'était envoyé, sans message).
 */
class NotifierPvController extends Controller
{
    public function __construct(private readonly Pv $pvs) {}

    public function index(Request $request): View|RedirectResponse
    {
        $u = $request->user();
        $pvId = (int) $request->query('pv_id');
        $pv = $pvId ? $this->pvs->trouver($pvId) : null;
        if ($pvId && (! $pv || ! $this->pvs->accessible($u, $pv))) {
            return redirect()->route('notifier_pv')->with('erreur', 'Accès non autorisé — ce PV appartient à une autre faculté.');
        }

        return view('communication.notifier_pv', [
            'pvs' => DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
                ->when($u->role === 'sc_entite', fn ($q) => $q->where('f.faculte_id', $u->faculteId()))
                ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('s.code')
                ->get(['ps.id', 'ps.type_session', 'ps.statut', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee', 's.code as semestre',
                    DB::raw('(SELECT COUNT(*) FROM resultats_semestre rs WHERE rs.pv_id = ps.id) AS nb_etudiants')]),
            'pv' => $pv,
            'nbEtudiants' => $pv ? DB::table('resultats_semestre')->where('pv_id', $pv->id)->count() : 0,
            'historique' => $pv ? DB::table('email_logs')->where(['contexte' => 'pv_publie', 'reference_id' => $pv->id])->orderByDesc('id')->limit(30)->get() : collect(),
        ]);
    }

    public function envoyer(Request $request, Notifications $notifications, Journal $journal): RedirectResponse
    {
        $pv = $this->pvs->trouver((int) $request->input('pv_id'));
        if (! $pv || ! $this->pvs->accessible($request->user(), $pv)) {
            return redirect()->route('notifier_pv')->with('erreur', 'PV introuvable.');
        }
        $retour = redirect()->route('notifier_pv', ['pv_id' => $pv->id]);
        if ($pv->statut !== 'publie') {
            return $retour->with('erreur', "Ce PV n'est pas publié : publiez-le avant d'annoncer les résultats.");
        }
        $cibles = (array) $request->input('cibles', []);
        $etudiants = in_array('etudiants', $cibles, true);
        $parents = in_array('parents', $cibles, true);
        if (! $etudiants && ! $parents) {
            return $retour->with('erreur', 'Choisissez au moins un public (étudiants et/ou parents).');
        }
        $bilan = $notifications->notifierPv($pv->id, $etudiants, $parents);
        $journal->action('envoyer', 'notification', "Notification du PV « {$pv->libelle} » : {$bilan['destinataires']} destinataire(s)", (int) $pv->id);

        return $retour->with('succes', "Notifications envoyées à {$bilan['destinataires']} destinataire(s) — {$bilan['emails']} email(s), {$bilan['sms']} SMS.");
    }
}
