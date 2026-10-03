<?php

namespace App\Http\Controllers\Rapports;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Suivi d'exécution du programme (heures faites / prévues par matière) —
 * portage de pages/structure/suivi_execution.php. Portée : admin et
 * secrétaire tout ; chef de département son département ; doyen et
 * sc_entite leur faculté (lecture seule) ; autre compte lié à un
 * enseignant : ses matières.
 *
 * Différences voulues : un chef de département ne corrige plus que les
 * matières de son département (toute matière avant) ; enseignants affichés
 * : ceux de l'année choisie (toutes années confondues avant) ; correction
 * des heures en un seul endroit de contrôle (bornes, volume prévu).
 */
class SuiviExecutionController extends Controller
{
    public function index(Request $request): View
    {
        $u = $request->user();
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeId = $request->has('annee_id') ? (int) $request->query('annee_id') : (int) ($annees->firstWhere('active', 1)->id ?? 0);
        $filiereId = (int) $request->query('filiere_id');
        $semestreId = (int) $request->query('semestre_id');
        $matieres = $this->portee($u, DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')->join('promotions as p', 'p.id', '=', 'ue.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'ue.semestre_id')
            ->leftJoin('affectations as af', fn ($j) => $j->on('af.matiere_id', '=', 'm.id')->on('af.annee_id', '=', 'p.annee_id'))->leftJoin('enseignants as e', 'e.id', '=', 'af.enseignant_id')
            ->where('p.annee_id', $anneeId)->where('m.actif', 1)->whereNotNull('m.volume_horaire_prevu')
            ->when($filiereId, fn ($q) => $q->where('f.id', $filiereId))->when($semestreId, fn ($q) => $q->where('s.id', $semestreId)))
            ->groupBy('m.id', 'm.libelle', 'm.volume_horaire_prevu', 'm.heures_faites', 'f.libelle', 'n.code', 's.code')
            ->orderBy('f.libelle')->orderBy('n.code')->orderBy('m.libelle')
            ->get(['m.id', 'm.libelle as matiere', 'm.volume_horaire_prevu', 'm.heures_faites', 'f.libelle as filiere', 'n.code as niveau', 's.code as semestre',
                DB::raw("GROUP_CONCAT(DISTINCT CONCAT(e.nom, ' ', e.prenoms) ORDER BY e.nom SEPARATOR ', ') AS enseignant")])
            ->map(function ($m) {
                $prevu = (float) $m->volume_horaire_prevu;
                $fait = (float) $m->heures_faites;
                $m->pct = $prevu > 0 ? min(100, round($fait / $prevu * 100, 1)) : 0;
                $m->categorie = $fait >= $prevu ? 'complete' : ($fait > 0 ? 'en_cours' : 'sans_toucher');

                return $m;
            });
        $prevues = $matieres->sum(fn ($m) => (float) $m->volume_horaire_prevu);
        $faites = $matieres->sum(fn ($m) => min((float) $m->heures_faites, (float) $m->volume_horaire_prevu));

        return view('rapports.suivi_execution', ['annees' => $annees, 'anneeId' => $anneeId, 'filiereId' => $filiereId, 'semestreId' => $semestreId,
            'semestres' => DB::table('semestres')->orderBy('code')->get(),
            'filieres' => $this->porteeFilieres($u, DB::table('filieres as f')->where('f.actif', 1))->orderBy('f.libelle')->get(['f.id', 'f.libelle']),
            'matieres' => $matieres, 'prevues' => $prevues, 'faites' => $faites, 'taux' => $prevues > 0 ? round($faites / $prevues * 100, 1) : 0,
            'peutEditer' => $this->peutEditer($u)]);
    }

    public function heures(Request $request, Journal $journal): RedirectResponse
    {
        $u = $request->user();
        $retour = redirect()->route('suivi_execution', array_filter(['annee_id' => (int) $request->input('annee_id'), 'filiere_id' => (int) $request->input('filiere_id'),
            'semestre_id' => (int) $request->input('semestre_id')]));
        if (! $this->peutEditer($u)) {
            return $retour->with('erreur', "Vous n'êtes pas autorisé à modifier les heures faites.");
        }
        $mat = $this->portee($u, DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')->join('promotions as p', 'p.id', '=', 'ue.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id'))->where('m.id', (int) $request->input('mat_id'))->first(['m.id', 'm.libelle', 'm.volume_horaire_prevu']);
        $brut = str_replace(',', '.', trim((string) $request->input('heures_faites')));
        if (! $mat) {
            return $retour->with('erreur', 'Matière introuvable dans votre périmètre.');
        }
        if (! is_numeric($brut) || (float) $brut < 0 || (float) $brut > 9999) {
            return $retour->with('erreur', "Le nombre d'heures ne peut pas être négatif.");
        }
        $h = round((float) $brut, 1);
        if ($mat->volume_horaire_prevu !== null && $h > (float) $mat->volume_horaire_prevu) {
            return $retour->with('erreur', "Le nombre d'heures dépasse le volume prévu (".(float) $mat->volume_horaire_prevu.'h).');
        }
        DB::table('matieres')->where('id', $mat->id)->update(['heures_faites' => $h, 'heures_maj_par' => $u->id, 'heures_maj_le' => now()]);
        $journal->action('modifier', 'matiere', "Heures faites corrigées à {$h} h depuis le suivi d'exécution — « {$mat->libelle} »", (int) $mat->id);

        return $retour->with('succes', "Heures mises à jour : {$h}h.");
    }

    /** Matières visibles (alias m, f). */
    private function portee(Utilisateur $u, $q)
    {
        return match (true) {
            in_array($u->role, ['admin', 'secretaire'], true) => $q,
            $u->role === 'chef_departement' => $q->where('f.departement_id', $u->departementId() ?: -1),
            in_array($u->role, ['doyen', 'sc_entite'], true) => $q->where('f.faculte_id', $u->faculteId() ?: -1),
            default => $q->whereExists(fn ($s) => $s->from('affectations as af2')->whereColumn('af2.matiere_id', 'm.id')->where('af2.enseignant_id', (int) $u->enseignant_id ?: -1)),
        };
    }

    private function porteeFilieres(Utilisateur $u, $q)
    {
        return match (true) {
            $u->role === 'chef_departement' => $q->where('f.departement_id', $u->departementId() ?: -1),
            in_array($u->role, ['doyen', 'sc_entite'], true) => $q->where('f.faculte_id', $u->faculteId() ?: -1),
            default => $q,
        };
    }

    /** Doyen et sc_entite voient plus large mais ne corrigent pas. */
    private function peutEditer(Utilisateur $u): bool
    {
        return in_array($u->role, ['admin', 'secretaire', 'chef_departement'], true) || (! in_array($u->role, ['doyen', 'sc_entite'], true) && $u->enseignant_id);
    }
}
