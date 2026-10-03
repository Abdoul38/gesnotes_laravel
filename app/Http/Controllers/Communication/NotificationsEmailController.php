<?php

namespace App\Http\Controllers\Communication;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Communication\Courriel;
use App\Services\Journal;
use App\Services\Parametres;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * E-mails aux étudiants — portage de pages/communication/notifications_email.php
 * (admin, secrétaire, sc_centrale, doyen, chef de département) : tous, une
 * filière, un niveau ou un étudiant, variables [NOM] [PRENOMS] [NOM_COMPLET],
 * mode test, historique.
 *
 * Différences voulues :
 *  - envoi par le serveur SMTP configuré (service Courriel) : l'ancienne page
 *    utilisait mail() de PHP et ignorait cette configuration ;
 *  - destinataires limités au périmètre de l'agent (« tous » visait toute
 *    l'université, même pour un chef de département) ; filière de sa faculté ;
 *  - envoi « par niveau » réparé (il interrogeait une colonne inexistante,
 *    inscriptions.niveau_id : erreur SQL) ;
 *  - en-tête et pied du message tirés des paramètres de l'université (nom,
 *    service, téléphone et e-mail de l'UDH écrits en dur) ;
 *  - historique : ses propres envois (tous pour l'administrateur) ;
 *  - champs contrôlés ; envoi journalisé.
 */
class NotificationsEmailController extends Controller
{
    public function __construct(private readonly Perimetre $perimetre) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $etudiants = fn () => $this->perimetre->limiterEtudiants(DB::table('etudiants as e'), $u);
        $faculte = $this->perimetre->faculte($u);

        return view('communication.notifications_email', [
            'historique' => DB::table('notifications_email as n')->leftJoin('utilisateurs as u', 'u.id', '=', 'n.envoye_par')
                ->leftJoin('filieres as f', 'f.id', '=', 'n.filiere_id')->leftJoin('niveaux as nv', 'nv.id', '=', 'n.niveau_id')
                ->when($u->role !== 'admin', fn ($q) => $q->where('n.envoye_par', $u->id))
                ->orderByDesc('n.created_at')->limit(30)->get(['n.*', 'u.nom as envoyeur', 'f.libelle as filiere', 'nv.code as niveau']),
            'filieres' => DB::table('filieres')->where('actif', 1)->when($faculte, fn ($q) => $q->where('faculte_id', $faculte))->orderBy('libelle')->get(['id', 'libelle']),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(['id', 'code']),
            'nbAvecEmail' => $etudiants()->whereNotNull('e.email')->where('e.email', '!=', '')->count(),
            'nbTotal' => $etudiants()->count(),
        ]);
    }

    public function envoyer(Request $request, Courriel $courriel, Parametres $parametres, Journal $journal): RedirectResponse
    {
        $u = $request->user();
        $v = Validator::make($request->all(), [
            'sujet' => 'required|string|max:300', 'corps' => 'required|string|max:20000',
            'destinataires' => 'required|in:tous,filiere,niveau,individuel',
            'filiere_id' => 'nullable|integer|required_if:destinataires,filiere',
            'niveau_id' => 'nullable|integer|required_if:destinataires,niveau',
            'etudiant_id' => 'nullable|integer|required_if:destinataires,individuel',
            'test_addr' => 'nullable|email|max:190',
        ], ['required' => 'Sujet et corps obligatoires.', 'required_if' => 'Choisissez la filière, le niveau ou l\'étudiant.']);
        if ($v->fails()) {
            return redirect()->route('notifications_email')->with('erreur', $v->errors()->first());
        }
        $d = $v->validated();
        $test = $request->boolean('test_email');
        if ($test && empty($d['test_addr'])) {
            return redirect()->route('notifications_email')->with('erreur', "Saisissez l'adresse du test.");
        }
        $mode = $d['destinataires'];
        $filiere = $mode === 'filiere' ? (int) $d['filiere_id'] : null;
        if ($filiere && ! $this->perimetre->filiere($u, $filiere)) {
            return redirect()->route('notifications_email')->with('erreur', 'Filière introuvable.');
        }

        $destinataires = $test
            ? collect([(object) ['email' => $d['test_addr'], 'nom' => 'Test', 'prenoms' => 'Destinataire']])
            : $this->perimetre->limiterEtudiants(DB::table('etudiants as e'), $u)
                ->whereNotNull('e.email')->where('e.email', '!=', '')
                ->when($filiere, fn ($q) => $q->where('e.filiere_id', $filiere))
                ->when($mode === 'niveau', fn ($q) => $q->whereExists(fn ($s) => $s->from('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                    ->whereColumn('i.etudiant_id', 'e.id')->where('i.statut', 'inscrit')->where('p.niveau_id', (int) $d['niveau_id'])))
                ->when($mode === 'individuel', fn ($q) => $q->where('e.id', (int) $d['etudiant_id']))
                ->get(['e.id', 'e.nom', 'e.prenoms', 'e.email']);

        $entete = [$parametres->valeur('institution_nom', 'Université'), $parametres->valeur('institution_service', 'Service de la Scolarité')];
        $contact = implode(' | ', array_filter([$parametres->valeur('institution_tel'), $parametres->valeur('institution_email')]));
        $envoyes = $echecs = 0;
        foreach ($destinataires as $e) {
            $corps = str_replace(['[NOM]', '[PRENOMS]', '[NOM_COMPLET]'], [$e->nom, $e->prenoms, $e->prenoms.' '.$e->nom], $d['corps']);
            $html = view('communication.email_etudiant', ['entete' => $entete, 'contact' => $contact, 'nom' => $e->prenoms.' '.$e->nom, 'corps' => $corps])->render();
            $courriel->envoyer($e->email, $d['sujet'], $html, 'email_etudiants', $test ? null : (isset($e->id) ? (int) $e->id : null)) ? $envoyes++ : $echecs++;
        }

        $id = DB::table('notifications_email')->insertGetId([
            'sujet' => $d['sujet'], 'corps' => $d['corps'], 'destinataires' => $mode, 'filiere_id' => $filiere,
            'niveau_id' => $mode === 'niveau' ? (int) $d['niveau_id'] : null, 'etudiant_id' => $mode === 'individuel' ? (int) $d['etudiant_id'] : null,
            'nb_envoyes' => $envoyes, 'nb_echecs' => $echecs, 'statut' => $envoyes > 0 ? ($echecs > 0 ? 'partiel' : 'envoye') : 'brouillon',
            'envoye_le' => now(), 'envoye_par' => $u->id,
        ]);
        $journal->action('envoyer', 'email_etudiants', ($test ? 'Test — ' : '')."« {$d['sujet']} » : $envoyes envoyé(s), $echecs échec(s)", $id);

        if ($test) {
            return redirect()->route('notifications_email')->with($envoyes ? 'succes' : 'erreur', $envoyes
                ? "Email de test envoyé à {$d['test_addr']}." : "Échec de l'email de test — vérifiez la configuration du serveur (Paramètres › Notif EMAIL).");
        }

        return redirect()->route('notifications_email')->with($envoyes ? 'succes' : 'erreur', $destinataires->isEmpty()
            ? 'Aucun destinataire avec une adresse email.'
            : "✅ $envoyes email(s) envoyé(s)".($echecs ? " — ⚠️ $echecs échec(s) (voir la configuration email)" : '').'.');
    }
}
