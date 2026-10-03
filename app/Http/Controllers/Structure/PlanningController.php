<?php

namespace App\Http\Controllers\Structure;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Identite;
use App\Services\Journal;
use App\Services\Pv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Planning des examens d'un PV — portage de pages/structure/planning.php
 * (admin, secrétaire, sc_centrale, sc_entite, doyen, chef de département ;
 * PV de sa faculté).
 *
 * Différences voulues :
 *  - date, heures (fin après début), matière du PV, salle et surveillants
 *    actifs contrôlés ; une salle déjà occupée sur le créneau est refusée
 *    à l'enregistrement (l'avertissement n'était qu'affiché) ;
 *  - vider la date d'une matière retire sa ligne du planning (il n'y avait
 *    aucun moyen de le faire) ; enregistrement journalisé ;
 *  - impression : logos de l'université et de la faculté (logos UDH et FADEG
 *    écrits en dur).
 */
class PlanningController extends Controller
{
    public function __construct(private readonly Perimetre $perimetre, private readonly Pv $pvs) {}

    public function index(Request $request, Identite $identite): View|RedirectResponse
    {
        $fac = $this->perimetre->faculte($request->user());
        $pvId = (int) $request->query('pv_id');
        $pv = $pvId ? $this->pv($request, $pvId) : null;
        if ($pvId && ! $pv) {
            return redirect()->route('planning')->with('erreur', 'Accès non autorisé — ce PV appartient à une autre faculté.');
        }
        $donnees = ['pvId' => $pvId, 'pv' => $pv,
            'pvs' => DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->when($fac, fn ($q) => $q->where('f.faculte_id', $fac))
                ->orderByDesc('a.libelle')->orderBy('f.libelle')->get(['ps.id', 'ps.libelle', 'ps.type_session', 'a.libelle as annee'])];
        if ($pv) {
            $donnees += ['ues' => $this->pvs->ues($pv),
                'planning' => DB::table('examens_planning as ep')->leftJoin('enseignants as e1', 'e1.id', '=', 'ep.surveillant_1')->leftJoin('enseignants as e2', 'e2.id', '=', 'ep.surveillant_2')
                    ->where('ep.pv_id', $pv->id)->get(['ep.*', 'e1.nom as surv1_nom', 'e1.prenoms as surv1_pren', 'e2.nom as surv2_nom', 'e2.prenoms as surv2_pren'])->keyBy('matiere_id'),
                'enseignants' => DB::table('enseignants')->where('actif', 1)->orderBy('nom')->get(['id', 'nom', 'prenoms']),
                'salles' => DB::table('salles')->where('actif', 1)->orderBy('batiment')->orderBy('code')->get(['id', 'code', 'nom', 'capacite', 'batiment'])];
            if ($request->has('imprimer')) {
                return view('structure.planning_impression', $donnees + ['logos' => $identite->logos($pv->faculte_id ? (int) $pv->faculte_id : null),
                    'institution' => app(\App\Services\Parametres::class)->valeur('institution_nom', app(\App\Universites\UniversiteCourante::class)->get()->nom)]);
            }
        }

        return view('structure.planning', $donnees);
    }

    /** Salles occupées sur un créneau (vérification en direct du formulaire). */
    public function disponibilite(Request $request): JsonResponse
    {
        $date = (string) $request->query('date');
        $debut = (string) $request->query('heure_debut');
        $fin = (string) $request->query('heure_fin');
        if (! $this->dateValide($date) || ! $this->heureValide($debut) || ! $this->heureValide($fin)) {
            return response()->json(['occupees' => []]);
        }

        return response()->json(['occupees' => $this->occupees($date, $debut, $fin, (int) $request->query('exclude_id'))->map(fn ($v) => (int) $v)->values()]);
    }

    public function sauvegarder(Request $request, Journal $journal): RedirectResponse
    {
        $pv = $this->pv($request, (int) $request->input('pv_id'));
        if (! $pv) {
            return redirect()->route('planning')->with('erreur', 'Accès non autorisé — ce PV appartient à une autre faculté.');
        }
        $retour = redirect()->route('planning', ['pv_id' => $pv->id]);
        $matieres = $this->pvs->ues($pv)->flatMap(fn ($ue) => $ue->matieres)->keyBy('id');
        $salles = DB::table('salles')->where('actif', 1)->pluck('nom', 'id');
        $enseignants = DB::table('enseignants')->where('actif', 1)->pluck('id')->flip();
        $existantes = DB::table('examens_planning')->where('pv_id', $pv->id)->get()->keyBy('matiere_id');
        $lignes = [];
        $retirees = [];
        $erreurs = [];
        foreach ((array) $request->input('planning', []) as $matId => $v) {
            $matId = (int) $matId;
            if (! isset($matieres[$matId]) || ! is_array($v)) {
                continue;
            }
            $libelle = $matieres[$matId]->libelle;
            if (trim((string) ($v['date'] ?? '')) === '') {
                if (isset($existantes[$matId])) {
                    $retirees[] = $matId;
                }

                continue;
            }
            [$date, $debut, $fin] = [(string) $v['date'], (string) ($v['heure_debut'] ?? ''), (string) ($v['heure_fin'] ?? '')];
            $salle = (int) ($v['salle_id'] ?? 0) ?: null;
            if (! $this->dateValide($date) || ! $this->heureValide($debut) || ! $this->heureValide($fin) || $fin <= $debut) {
                $erreurs[] = "$libelle : date ou horaires invalides";

                continue;
            }
            if ($salle && ! isset($salles[$salle])) {
                $erreurs[] = "$libelle : salle inconnue";

                continue;
            }
            if ($salle && $this->occupees($date, $debut, $fin, (int) ($existantes[$matId]->id ?? 0))->contains($salle)) {
                $erreurs[] = "$libelle : salle {$salles[$salle]} déjà occupée sur ce créneau";

                continue;
            }
            $surv = fn ($k) => isset($v[$k]) && $enseignants->has((int) $v[$k]) ? (int) $v[$k] : null;
            $lignes[] = ['pv_id' => $pv->id, 'matiere_id' => $matId, 'date_examen' => $date, 'heure_debut' => $debut, 'heure_fin' => $fin,
                'salle' => $salle ? $salles[$salle] : null, 'salle_id' => $salle, 'surveillant_1' => $surv('surv1'), 'surveillant_2' => $surv('surv2'),
                'notes' => isset($v['notes']) ? mb_substr((string) $v['notes'], 0, 1000) : null];
        }
        if ($erreurs) {
            return $retour->with('erreur', 'Rien n\'a été enregistré — '.implode(' ; ', $erreurs).'.');
        }
        DB::transaction(function () use ($lignes, $retirees, $pv) {
            if ($lignes) {
                DB::table('examens_planning')->upsert($lignes, ['pv_id', 'matiere_id'],
                    ['date_examen', 'heure_debut', 'heure_fin', 'salle', 'salle_id', 'surveillant_1', 'surveillant_2', 'notes']);
            }
            if ($retirees) {
                DB::table('examens_planning')->where('pv_id', $pv->id)->whereIn('matiere_id', $retirees)->delete();
            }
        });
        $journal->action('modifier', 'planning', "Planning des examens enregistré — {$pv->libelle}", (int) $pv->id);

        return $retour->with('succes', 'Planning sauvegardé.');
    }

    private function pv(Request $request, int $id): ?object
    {
        $pv = $id ? $this->pvs->trouver($id) : null;

        return $pv && $this->perimetre->faculteAutorisee($request->user(), $pv->faculte_id !== null ? (int) $pv->faculte_id : null) ? $pv : null;
    }

    private function occupees(string $date, string $debut, string $fin, int $exclure)
    {
        return DB::table('examens_planning')->where('date_examen', $date)->whereNotNull('salle_id')
            ->where('heure_debut', '<', $fin)->where('heure_fin', '>', $debut)->where('id', '!=', $exclure)->pluck('salle_id');
    }

    private function dateValide(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    }

    private function heureValide(string $h): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $h);
    }
}
