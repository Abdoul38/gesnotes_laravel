<?php

namespace App\Http\Controllers\Communication;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Messagerie interne administration ↔ étudiants — portage de
 * pages/communication/messagerie.php (admin, secrétaire, sc_centrale,
 * doyen, chef de département) : messages reçus, envoyés, lecture, réponse,
 * nouveau message, suppression.
 *
 * Différences voulues :
 *  - chacun ne voit, ne lit, ne supprime et n'écrit qu'aux étudiants de son
 *    périmètre (faculté ; département pour un chef de département) : un doyen
 *    lisait et supprimait les messages de toute l'université ;
 *  - destinataire, message d'origine d'une réponse, longueurs contrôlés ;
 *  - résultats de recherche de destinataire construits sans HTML (les noms
 *    étaient insérés tels quels : une candidature au nom piégé injectait du
 *    HTML dans la page) ; onglets sans JavaScript.
 */
class MessagerieController extends Controller
{
    public function __construct(private readonly Perimetre $perimetre) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $onglet = $request->query('onglet') === 'envoyes' ? 'envoyes' : 'recus';
        $msgId = (int) $request->query('msg');
        $base = fn () => DB::table('messages as m')->join('etudiants as e', 'e.id', '=', DB::raw("IF(m.expediteur_type = 'etudiant', m.expediteur_id, m.destinataire_id)"))
            ->tap(fn ($q) => $this->limiterEtudiants($q, $u))->select(['m.*', 'e.nom', 'e.prenoms', 'e.numero']);

        $detail = $msgId ? $base()->where('m.id', $msgId)->first() : null;
        if ($detail && ! $detail->lu && $detail->destinataire_type === 'admin') {
            DB::table('messages')->where('id', $detail->id)->update(['lu' => 1, 'lu_le' => now()]);
            $detail->lu = 1;
        }

        return view('communication.messagerie', [
            'onglet' => $onglet,
            'recus' => $base()->where('m.expediteur_type', 'etudiant')->where('m.destinataire_type', 'admin')->whereNull('m.parent_id')
                ->orderBy('m.lu')->orderByDesc('m.created_at')->limit(300)->get(),
            'envoyes' => $base()->where('m.expediteur_type', 'admin')->whereNull('m.parent_id')->orderByDesc('m.created_at')->limit(20)->get(),
            'nbNonLus' => $base()->where('m.destinataire_type', 'admin')->where('m.lu', 0)->count(),
            'detail' => $detail,
            'msgId' => $msgId,
        ]);
    }

    public function envoyer(Request $request): RedirectResponse
    {
        $u = $request->user();
        $v = Validator::make($request->all(), [
            'sujet' => 'required|string|max:200', 'corps' => 'required|string|max:10000',
            'dest_id' => 'required|integer', 'parent_id' => 'nullable|integer',
        ], ['required' => 'Sujet, message et destinataire obligatoires.']);
        if ($v->fails()) {
            return redirect()->route('messagerie')->with('erreur', $v->errors()->first());
        }
        $d = $v->validated();
        if (! $this->limiterEtudiants(DB::table('etudiants as e')->where('e.id', $d['dest_id']), $u)->exists()) {
            return redirect()->route('messagerie')->with('erreur', 'Destinataire introuvable.');
        }
        // Réponse : le message d'origine doit venir de ce même étudiant
        $parent = ! empty($d['parent_id']) ? DB::table('messages')->where('id', $d['parent_id'])->where('expediteur_type', 'etudiant')
            ->where('expediteur_id', $d['dest_id'])->value('id') : null;
        DB::table('messages')->insert(['expediteur_type' => 'admin', 'expediteur_id' => $u->id, 'destinataire_type' => 'etudiant',
            'destinataire_id' => $d['dest_id'], 'sujet' => mb_substr(trim($d['sujet']), 0, 200), 'corps' => trim($d['corps']), 'parent_id' => $parent]);

        return redirect()->route('messagerie', $parent ? ['msg' => $parent] : [])->with('succes', 'Message envoyé.');
    }

    public function supprimer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');
        $existe = DB::table('messages as m')->join('etudiants as e', 'e.id', '=', DB::raw("IF(m.expediteur_type = 'etudiant', m.expediteur_id, m.destinataire_id)"))
            ->tap(fn ($q) => $this->limiterEtudiants($q, $request->user()))->where('m.id', $id)->exists();
        if (! $existe) {
            return redirect()->route('messagerie')->with('erreur', 'Message introuvable.');
        }
        DB::table('messages')->where('id', $id)->delete();

        return redirect()->route('messagerie')->with('succes', 'Message supprimé.');
    }

    /** Étudiants du périmètre (voir Perimetre::limiterEtudiants). */
    private function limiterEtudiants(Builder $q, Utilisateur $u): Builder
    {
        return $this->perimetre->limiterEtudiants($q, $u);
    }
}
