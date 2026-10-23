<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\PresidentsJury;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Désignation des présidents de jury : un enseignant par semestre de chaque
 * promotion (filière, niveau, année). Admin, secrétaire, sc_centrale ;
 * sc_entite et doyen pour leur faculté ; chef de département pour son
 * département.
 */
class PresidentsJuryController extends Controller
{
    public function __construct(private readonly PresidentsJury $presidents, private readonly Journal $journal) {}

    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->presidents->disponible()) {
            return redirect()->route('accueil')->with('erreur', 'Base de données à mettre à jour (php artisan gesnotes:completer-schema --appliquer).');
        }
        $u = $request->user();
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeId = $annees->firstWhere('id', (int) $request->query('annee_id'))?->id ?? $annees->firstWhere('active', 1)?->id ?? $annees->first()?->id ?? 0;
        $filiereId = (int) $request->query('filiere_id');

        // Semestres de chaque promotion : ceux de sa maquette (UE) ou de ses PV
        $semestres = DB::table('unites_enseignement')->select(['promotion_id', 'semestre_id'])
            ->union(DB::table('pv_sessions')->select(['promotion_id', 'semestre_id']));
        $lignes = DB::query()->fromSub($semestres, 'ps')
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->leftJoin('presidents_jury as pj', fn ($j) => $j->on('pj.promotion_id', '=', 'ps.promotion_id')->on('pj.semestre_id', '=', 'ps.semestre_id'))
            ->leftJoin('enseignants as en', 'en.id', '=', 'pj.enseignant_id')
            ->where('p.annee_id', $anneeId)
            ->when($filiereId, fn ($q) => $q->where('f.id', $filiereId))
            ->when($u->faculteId(), fn ($q, $f) => $q->where('f.faculte_id', $f))
            ->when($u->departementId(), fn ($q, $d) => $q->where('f.departement_id', $d))
            ->orderBy('f.libelle')->orderBy('n.code')->orderBy('s.code')
            ->get(['ps.promotion_id', 'ps.semestre_id', 'f.libelle as filiere', 'n.code as niveau', 's.libelle as semestre',
                'pj.id as president_id', 'en.nom', 'en.prenoms', 'en.grade']);

        return view('notes.presidents_jury', [
            'annees' => $annees,
            'anneeId' => $anneeId,
            'filiereId' => $filiereId,
            'filieres' => DB::table('filieres')->where('actif', 1)->when($u->faculteId(), fn ($q, $f) => $q->where('faculte_id', $f))
                ->when($u->departementId(), fn ($q, $d) => $q->where('departement_id', $d))->orderBy('libelle')->get(['id', 'libelle']),
            'lignes' => $lignes,
            'enseignants' => DB::table('enseignants')->where('actif', 1)->orderBy('nom')->orderBy('prenoms')->get(['id', 'nom', 'prenoms', 'grade']),
        ]);
    }

    /** Désigne l'enseignant président des semestres cochés (« promotion-semestre »), à la place du président actuel. */
    public function designer(Request $request): RedirectResponse
    {
        $retour = redirect()->route('presidents_jury', array_filter(['annee_id' => (int) $request->input('annee_id'), 'filiere_id' => (int) $request->input('filiere_id')]));
        $enseignant = DB::table('enseignants')->where(['id' => (int) $request->input('enseignant_id'), 'actif' => 1])->first();
        $cibles = $this->cibles($request, (array) $request->input('semestres', []));
        if (! $enseignant || ! $cibles) {
            return $retour->with('erreur', 'Choisissez un enseignant et au moins un semestre de votre périmètre.');
        }
        DB::table('presidents_jury')->upsert(array_map(fn ($c) => $c + ['enseignant_id' => $enseignant->id, 'designe_par' => $request->user()->id, 'designe_le' => now()], $cibles),
            ['promotion_id', 'semestre_id'], ['enseignant_id', 'designe_par', 'designe_le']);
        $this->journal->action('modifier', 'pv', "Président du jury : {$enseignant->nom} {$enseignant->prenoms} — ".count($cibles).' semestre(s)');

        return $retour->with('succes', "{$enseignant->prenoms} {$enseignant->nom} est président du jury de ".count($cibles).' semestre(s).');
    }

    public function retirer(Request $request): RedirectResponse
    {
        $retour = redirect()->route('presidents_jury', array_filter(['annee_id' => (int) $request->input('annee_id'), 'filiere_id' => (int) $request->input('filiere_id')]));
        $cibles = $this->cibles($request, [(string) $request->input('semestre')]);
        if (! $cibles) {
            return $retour->with('erreur', 'Semestre introuvable dans votre périmètre.');
        }
        DB::table('presidents_jury')->where($cibles[0])->delete();
        $this->journal->action('supprimer', 'pv', 'Président du jury retiré');

        return $retour->with('succes', 'Président du jury retiré.');
    }

    /** « promotion-semestre » reçus → couples existants du périmètre de l'agent. */
    private function cibles(Request $request, array $valeurs): array
    {
        $u = $request->user();
        $cibles = [];
        foreach ($valeurs as $v) {
            if (! is_string($v) || ! preg_match('/^(\d+)-(\d+)$/', $v, $m)) {
                continue;
            }
            $ok = DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->where('p.id', (int) $m[1])
                ->when($u->faculteId(), fn ($q, $f) => $q->where('f.faculte_id', $f))
                ->when($u->departementId(), fn ($q, $d) => $q->where('f.departement_id', $d))->exists()
                && DB::table('semestres')->where('id', (int) $m[2])->exists();
            if ($ok) {
                $cibles[$v] = ['promotion_id' => (int) $m[1], 'semestre_id' => (int) $m[2]];
            }
        }

        return array_values($cibles);
    }
}
