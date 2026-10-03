<?php

namespace App\Http\Controllers\Communication;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Vue d'ensemble des notifications — portage de
 * pages/communication/dashboard_notifications.php (admin, secrétaire,
 * sc_centrale, sc_entite, doyen, chef de département ; ancien hasRole) :
 * envois des 30 derniers jours, répartition par type, présence par filière,
 * parents jamais connectés, étudiants jamais notifiés.
 *
 * Différences voulues :
 *  - listes de parents et d'étudiants (noms, téléphones, e-mails) et présence
 *    limitées au périmètre de l'agent (toute l'université pour un doyen ou un
 *    chef de département) ;
 *  - « jamais notifié » réparé : la requête mêlait deux collations (erreur
 *    SQL masquée par un try/catch : la liste était toujours vide) et comparait
 *    le numéro saisi, avec espaces, au numéro journalisé ; un étudiant
 *    n'apparaît qu'une fois.
 */
class TableauNotificationsController extends Controller
{
    public function __invoke(Request $request, Perimetre $perimetre): View
    {
        $u = $request->user();
        $depuis = now()->subDays(30);
        $stats = fn (string $table) => DB::table($table)->where('date_envoi', '>=', $depuis)
            ->selectRaw("SUM(statut='envoye') AS ok, SUM(statut='echec') AS echec, SUM(statut='simule') AS simule, COUNT(*) AS total")->first();
        $etudiants = fn () => $perimetre->limiterEtudiants(DB::table('etudiants as e'), $u);
        $parentsDuPerimetre = fn ($q) => $q->whereExists(fn ($s) => $perimetre->limiterEtudiants(
            $s->from('parents_etudiants as pe2')->join('etudiants as e', 'e.id', '=', 'pe2.etudiant_id')->whereColumn('pe2.parent_id', 'p.id'), $u));
        $restreint = $perimetre->faculte($u) || ($u->role === 'chef_departement' && $u->departementId());
        $faculte = $perimetre->faculte($u);
        $departement = $u->role === 'chef_departement' ? $u->departementId() : 0;
        $sansEspaces = "REGEXP_REPLACE(e.telephone, '[^0-9+]', '')";

        $jamaisConnectes = DB::table('parents as p')->where('p.actif', 1)->whereNull('p.derniere_connexion')->when($restreint, $parentsDuPerimetre);

        return view('communication.tableau_notifications', [
            'email' => $stats('email_logs'),
            'sms' => $stats('sms_logs'),
            'parContexte' => DB::table('email_logs')->where('date_envoi', '>=', $depuis)->whereNotNull('contexte')->where('contexte', '!=', '')
                ->groupBy('contexte')->orderByDesc('nb')->get(['contexte', DB::raw('COUNT(*) AS nb'), DB::raw("SUM(statut='envoye') AS nb_ok")]),
            'presences' => DB::table('presences as pr')->join('seances as se', 'se.id', '=', 'pr.seance_id')->join('promotions as p', 'p.id', '=', 'se.promotion_id')
                ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->where('se.date_seance', '>=', $depuis->toDateString())
                ->when($departement, fn ($q) => $q->where('f.departement_id', $departement), fn ($q) => $q->when($faculte, fn ($r) => $r->where('f.faculte_id', $faculte)))
                ->groupBy('f.id', 'f.libelle')->orderByDesc('nb_total')
                ->get(['f.libelle as filiere', DB::raw('COUNT(*) AS nb_total'), DB::raw("SUM(pr.statut='present') AS nb_present"), DB::raw("SUM(pr.statut='absent') AS nb_absent")]),
            'parents' => (clone $jamaisConnectes)->orderByDesc('p.created_at')->limit(50)
                ->get(['p.id', 'p.nom', 'p.prenoms', 'p.telephone', 'p.email', 'p.created_at',
                    DB::raw('(SELECT COUNT(*) FROM parents_etudiants pe WHERE pe.parent_id = p.id) AS nb_enfants')]),
            'nbParentsJamais' => (clone $jamaisConnectes)->count(),
            'nbParentsActifs' => DB::table('parents as p')->where('p.actif', 1)->when($restreint, $parentsDuPerimetre)->count(),
            'jamaisNotifies' => $etudiants()
                ->whereExists(fn ($s) => $s->from('inscriptions as i')->whereColumn('i.etudiant_id', 'e.id')->where('i.statut', 'inscrit'))
                ->whereNotExists(fn ($s) => $s->from('email_logs as el')->whereRaw('el.destinataire COLLATE utf8mb4_unicode_ci = e.email')->where('e.email', '!=', ''))
                ->whereNotExists(fn ($s) => $s->from('sms_logs as sl')->whereRaw("sl.numero COLLATE utf8mb4_unicode_ci = $sansEspaces")->whereRaw("$sansEspaces != ''"))
                ->orderBy('e.nom')->limit(50)
                ->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero', 'e.email', 'e.telephone',
                    DB::raw("(SELECT CONCAT(f.libelle, ' ', n.code) FROM inscriptions i JOIN promotions p ON p.id = i.promotion_id JOIN filieres f ON f.id = p.filiere_id
                        JOIN niveaux n ON n.id = p.niveau_id WHERE i.etudiant_id = e.id AND i.statut = 'inscrit' ORDER BY i.id DESC LIMIT 1) AS promotion")]),
            'nbInscrits' => $etudiants()->whereExists(fn ($s) => $s->from('inscriptions as i')->whereColumn('i.etudiant_id', 'e.id')->where('i.statut', 'inscrit'))->count(),
        ]);
    }
}
