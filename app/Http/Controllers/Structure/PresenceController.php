<?php

namespace App\Http\Controllers\Structure;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Prise de présence par séance — portage de pages/structure/presence.php
 * et de api_matieres_promotion.php.
 *
 * Différences voulues : chacun dans son périmètre — scolarité centrale
 * tout, faculté pour sc_entite et doyen, département pour le chef de
 * département, ses matières affectées pour un enseignant (n'importe quel
 * compte avec la permission créait des séances et modifiait les présences
 * de toute l'université) ; matière de la promotion, date et type contrôlés ;
 * seuls les étudiants inscrits dans la promotion sont enregistrés ; noms
 * des matières insérés sans HTML.
 */
class PresenceController extends Controller
{
    public const TYPES = ['CM', 'TD', 'TP'];

    public const STATUTS = ['present' => '✅ Présent', 'absent' => '❌ Absent', 'retard' => '⏰ Retard', 'excuse' => '📝 Excusé'];

    public function index(Request $request): View
    {
        $u = $request->user();
        $seance = (int) $request->query('seance_id') ? $this->seance($u, (int) $request->query('seance_id')) : null;

        return view('structure.presence', [
            'promotions' => $this->promotions($u)->orderByDesc('a.libelle')->orderBy('f.libelle')->get(['p.id', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']),
            'recentes' => $this->seances($u)->orderByDesc('se.date_seance')->orderByDesc('se.id')->limit(15)
                ->get(['se.id', 'se.date_seance', 'se.type_seance', 'm.libelle as matiere', 'f.libelle as filiere', 'n.code as niveau',
                    DB::raw('(SELECT COUNT(*) FROM presences pr WHERE pr.seance_id = se.id) AS nb_saisies')]),
            'seance' => $seance,
            'etudiants' => $seance ? $this->inscrits((int) $seance->promotion_id)->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero']) : collect(),
            'presences' => $seance ? DB::table('presences')->where('seance_id', $seance->id)->get()->keyBy('etudiant_id') : collect(),
        ]);
    }

    public function matieres(Request $request): JsonResponse
    {
        return response()->json($this->matieresPermises($request->user(), (int) $request->query('promotion_id'))->get(['m.id', 'm.libelle']));
    }

    public function creer(Request $request): RedirectResponse
    {
        $u = $request->user();
        $v = Validator::make($request->all(), ['promotion_id' => 'required|integer', 'matiere_id' => 'required|integer',
            'date_seance' => 'required|date', 'type_seance' => ['required', Rule::in(self::TYPES)]], ['required' => 'Matière et promotion requises.']);
        $retour = redirect()->route('presence');
        if ($v->fails()) {
            return $retour->with('erreur', $v->errors()->first());
        }
        $promotion = (int) $request->input('promotion_id');
        if (! $this->matieresPermises($u, $promotion)->where('m.id', (int) $request->input('matiere_id'))->exists()) {
            return $retour->with('erreur', 'Matière introuvable pour cette promotion dans votre périmètre.');
        }
        $id = DB::table('seances')->insertGetId(['matiere_id' => (int) $request->input('matiere_id'), 'promotion_id' => $promotion,
            'date_seance' => $request->input('date_seance'), 'type_seance' => $request->input('type_seance'), 'enseignant_id' => $u->enseignant_id, 'cree_par' => $u->id]);

        return redirect()->route('presence', ['seance_id' => $id])->with('succes', 'Séance créée — vous pouvez maintenant saisir les présences.');
    }

    public function enregistrer(Request $request, Journal $journal): RedirectResponse
    {
        $u = $request->user();
        $seance = $this->seance($u, (int) $request->input('seance_id'));
        if (! $seance) {
            return redirect()->route('presence')->with('erreur', 'Séance introuvable.');
        }
        $inscrits = $this->inscrits((int) $seance->promotion_id)->pluck('e.id')->flip();
        $motifs = (array) $request->input('motif', []);
        $lignes = [];
        foreach ((array) $request->input('statut', []) as $et => $statut) {
            if (! $inscrits->has((int) $et) || ! isset(self::STATUTS[$statut])) {
                continue;
            }
            $lignes[] = ['seance_id' => $seance->id, 'etudiant_id' => (int) $et, 'statut' => $statut,
                'motif' => mb_substr(trim((string) ($motifs[$et] ?? '')), 0, 200) ?: null, 'saisi_par' => $u->id];
        }
        if ($lignes) {
            DB::table('presences')->upsert($lignes, ['seance_id', 'etudiant_id'], ['statut', 'motif', 'saisi_par']);
        }
        $journal->action('modifier', 'presence', "Présences de la séance #{$seance->id} ({$seance->matiere}, ".count($lignes).' étudiant(s))', (int) $seance->id);

        return redirect()->route('presence', ['seance_id' => $seance->id])->with('succes', 'Présences enregistrées.');
    }

    /** Promotions du périmètre (alias p, f, n, a). */
    private function promotions(Utilisateur $u)
    {
        $q = DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id');

        return match (true) {
            in_array($u->role, ['admin', 'secretaire', 'sc_centrale'], true) => $q,
            in_array($u->role, ['sc_entite', 'doyen'], true) => $q->where('f.faculte_id', $u->faculteId() ?: -1),
            $u->role === 'chef_departement' => $q->where('f.departement_id', $u->departementId() ?: -1),
            default => $q->whereExists(fn ($s) => $s->from('affectations as af')->join('matieres as m', 'm.id', '=', 'af.matiere_id')
                ->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')->whereColumn('ue.promotion_id', 'p.id')->whereColumn('af.annee_id', 'p.annee_id')
                ->where('af.enseignant_id', (int) $u->enseignant_id ?: -1)),
        };
    }

    /** Matières d'une promotion du périmètre ; pour un enseignant, ses matières affectées. */
    private function matieresPermises(Utilisateur $u, int $promotion)
    {
        $q = DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
            ->where('ue.promotion_id', $promotion)->where('m.actif', 1)
            ->whereExists($this->promotions($u)->select(DB::raw(1))->whereColumn('p.id', 'ue.promotion_id'))
            ->orderBy('ue.ordre')->orderBy('m.ordre');
        if (! in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite', 'doyen', 'chef_departement'], true)) {
            $q->whereExists(fn ($s) => $s->from('affectations as af')->whereColumn('af.matiere_id', 'm.id')->where('af.enseignant_id', (int) $u->enseignant_id ?: -1));
        }

        return $q;
    }

    private function seances(Utilisateur $u)
    {
        $q = DB::table('seances as se')->join('matieres as m', 'm.id', '=', 'se.matiere_id')->join('promotions as p', 'p.id', '=', 'se.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->whereExists($this->promotions($u)->select(DB::raw(1))->whereColumn('p.id', 'se.promotion_id'));
        if (! in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite', 'doyen', 'chef_departement'], true)) {
            $q->whereExists(fn ($s) => $s->from('affectations as af')->whereColumn('af.matiere_id', 'se.matiere_id')->where('af.enseignant_id', (int) $u->enseignant_id ?: -1));
        }

        return $q;
    }

    private function seance(Utilisateur $u, int $id): ?object
    {
        return $this->seances($u)->where('se.id', $id)->first(['se.*', 'm.libelle as matiere', 'f.libelle as filiere', 'n.code as niveau']);
    }

    private function inscrits(int $promotion)
    {
        return DB::table('inscriptions as i')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')
            ->where(['i.promotion_id' => $promotion, 'i.statut' => 'inscrit'])->distinct()->orderBy('e.nom')->orderBy('e.prenoms');
    }
}
