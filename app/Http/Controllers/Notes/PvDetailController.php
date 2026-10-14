<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Parametres;
use App\Services\Pv;
use App\Services\Resultats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Détail d'un PV et grand tableau de saisie — portage de
 * pages/notes_pv/pv_detail.php, pages/api/ajax_recalcul_queue.php et
 * assets/js/pv_detail.js.
 *
 * Différences voulues :
 *  - le tableau affiche les résultats ENREGISTRÉS par le moteur. L'ancienne
 *    page les recalculait à l'affichage (en PHP puis en JavaScript) avec des
 *    règles différentes du moteur : l'écran pouvait montrer d'autres
 *    décisions que le PV imprimé. Pendant la saisie, les résultats
 *    provisoires viennent du moteur lui-même (simulation sans enregistrement) ;
 *  - un PV verrouillé ne peut plus être modifié, même par un envoi direct
 *    (le verrou n'était appliqué qu'à l'affichage) ;
 *  - seuls les étudiants du PV et ses matières sont acceptés, notes bornées
 *    entre 0 et le maximum, matières acquises (1ère session, reportées) et
 *    options non choisies protégées ;
 *  - une seule liste d'étudiants pour l'affichage, l'enregistrement et les
 *    deux recalculs (voir App\Services\Pv) ; le recalcul progressif exige
 *    le jeton CSRF et les droits de modification (tout enseignant pouvait
 *    le lancer sur n'importe quel PV) ;
 *  - suppression refusée pour un PV verrouillé ou qui n'est plus en
 *    brouillon (elle effaçait notes et résultats en cascade) ; message
 *    technique d'erreur non affiché.
 */
class PvDetailController extends Controller
{
    private const PAR_PAGE = 50;

    public function __construct(
        private readonly Pv $pvs,
        private readonly Resultats $resultats,
        private readonly Journal $journal,
        private readonly Parametres $parametres,
    ) {}

    public function afficher(Request $request, int $id): View|RedirectResponse
    {
        $u = $request->user();
        if ($u->role === 'etudiant') {
            return redirect()->route('accueil')->with('erreur', "Cette page n'est pas accessible depuis un compte étudiant.");
        }
        $pv = $this->pvs->trouver($id);
        if (! $pv) {
            return redirect()->route('pv')->with('erreur', 'PV introuvable');
        }
        if (! $this->pvs->accessible($u, $pv)) {
            return redirect()->route('pv')->with('erreur', 'Ce PV ne relève pas de votre périmètre.');
        }

        $recherche = trim((string) $request->query('q'));
        $tous = $this->pvs->etudiants($pv, $recherche)->get();
        $pages = max(1, (int) ceil($tous->count() / self::PAR_PAGE));
        $page = min(max(1, (int) $request->query('ppage', 1)), $pages);
        $etudiants = $tous->slice(($page - 1) * self::PAR_PAGE, self::PAR_PAGE)->values();
        $ids = $etudiants->pluck('id');

        $resultats = DB::table('resultats_semestre')->where('pv_id', $pv->id)->get()->keyBy('etudiant_id');
        $resultatsTous = $resultats->only($tous->pluck('id')->all());

        return view('notes.pv.detail', [
            'pv' => $pv,
            'ues' => $this->pvs->ues($pv),
            'etudiants' => $etudiants,
            'total' => $tous->count(),
            'page' => $page,
            'pages' => $pages,
            'decalage' => ($page - 1) * self::PAR_PAGE,
            'recherche' => $recherche,
            'cellules' => $this->cellules($pv, $ids->all()),
            'resultats' => $resultats,
            'resultatsUe' => DB::table('resultats_ue')->where('pv_id', $pv->id)->whereIn('etudiant_id', $ids)->get()->groupBy('etudiant_id')->map->keyBy('ue_id'),
            'stats' => [
                'total' => $tous->count(),
                'admis' => $resultatsTous->where('valide', 1)->count(),
                // Défaillant : une note manque ; Elim : note ou moyenne d'UE éliminatoire
                'defaillants' => $resultatsTous->where('defaillant', 1)->where('decision', '!=', 'Elim')->count(),
                'elimines' => $resultatsTous->where('decision', 'Elim')->count(),
                'ajournes' => $resultatsTous->where('valide', 0)->where('defaillant', 0)->count(),
                'non_calcules' => $tous->count() - $resultatsTous->count(),
            ],
            'seuil' => (float) $this->resultats->regle('seuil_validation', (int) $pv->annee_id, '10'),
            'peutModifier' => $this->pvs->peutModifier($u, $pv),
            'gestionnaire' => $this->pvs->gestionnaire($u),
            'admin' => $u->aRole('admin'),
        ]);
    }

    public function sauvegarder(Request $request, int $id): RedirectResponse
    {
        $pv = $this->pvModifiable($request, $id);
        $retour = route('pv_detail', ['id' => $id, 'ppage' => (int) $request->input('ppage', 1)]);
        if (! $pv) {
            return redirect($retour)->with('erreur', 'Ce PV est verrouillé ou vous ne pouvez pas le modifier.');
        }
        [$lignes, $erreurs] = $this->lignesDeNotes($pv, (array) $request->input('notes', []), $request->user()->id);
        if ($erreurs) {
            return redirect($retour)->with('erreur', 'Rien n\'a été enregistré : '.implode(' ', array_slice($erreurs, 0, 5)).(count($erreurs) > 5 ? ' …' : ''));
        }

        try {
            DB::transaction(function () use ($pv, $lignes) {
                foreach (array_chunk($lignes, 500) as $paquet) {
                    DB::table('notes')->upsert($paquet, ['pv_id', 'etudiant_id', 'matiere_id'], ['note_examen', 'note_cc', 'note_finale', 'defaillant', 'saisie_par', 'updated_at']);
                }
                $this->recalculerTout($pv);
            });
        } catch (Throwable $e) {
            Log::error("Enregistrement des notes du PV {$pv->id} : ".$e->getMessage());

            return redirect($retour)->with('erreur', "Erreur lors de l'enregistrement : aucune note n'a été modifiée.");
        }
        $matieres = DB::table('matieres')->whereIn('id', array_unique(array_column($lignes, 'matiere_id')))->pluck('libelle')->implode(', ');
        $nb = count(array_unique(array_column($lignes, 'etudiant_id')));
        $this->journal->action('modifier', 'notes', "Saisie/modification de notes pour $nb étudiant(s) — {$pv->libelle} — Matière(s) : ".($matieres ?: '—'), $pv->id);

        return redirect($retour)->with('succes', 'Notes enregistrées et résultats recalculés.');
    }

    /**
     * Résultats provisoires d'un étudiant pendant la saisie : le moteur
     * calcule avec les notes en cours de saisie, puis tout est annulé.
     */
    public function simuler(Request $request, int $id): JsonResponse
    {
        $pv = $this->pvModifiable($request, $id);
        $et = (int) $request->input('etudiant_id');
        if (! $pv) {
            return response()->json(['ok' => false], 403);
        }
        [$lignes, $erreurs] = $this->lignesDeNotes($pv, [$et => (array) $request->input('notes', [])], $request->user()->id);
        if ($erreurs) {
            return response()->json(['ok' => false, 'erreurs' => $erreurs]);
        }

        DB::beginTransaction();
        try {
            if ($lignes) {
                DB::table('notes')->upsert($lignes, ['pv_id', 'etudiant_id', 'matiere_id'], ['note_examen', 'note_cc', 'note_finale']);
            }
            $this->resultats->recalculer($pv->id, $et);
            $semestre = DB::table('resultats_semestre')->where(['pv_id' => $pv->id, 'etudiant_id' => $et])->first();
            $ues = DB::table('resultats_ue')->where(['pv_id' => $pv->id, 'etudiant_id' => $et])->get()->keyBy('ue_id');
            $notes = DB::table('notes')->where(['pv_id' => $pv->id, 'etudiant_id' => $et])->get()->keyBy('matiere_id');
        } finally {
            DB::rollBack();
        }
        $ueMax = $this->pvs->ues($pv)->mapWithKeys(fn ($ue) => [$ue->id => (int) $ue->credits_total]);
        $matieres = DB::table('matieres')->whereIn('id', $notes->keys())->get(['id', 'credits', 'ue_id', 'note_max_cc'])->keyBy('id');
        $credits = $matieres->pluck('credits', 'id');
        // UE où une case est vide : « Déf. » plutôt que « Elim »
        $vides = $notes->filter(fn ($n, $mat) => isset($matieres[$mat]) && Resultats::caseVide($n, $matieres[$mat]))->map(fn ($n, $mat) => $matieres[$mat]->ue_id)->flip();
        $seuil = (float) $this->resultats->regle('seuil_validation', (int) $pv->annee_id, '10');

        return response()->json(['ok' => true,
            'matieres' => $notes->map(fn ($n, $mat) => ['finale' => $n->note_finale, 'obs' => $n->observation,
                'credits' => in_array($n->observation, ['AV', 'AC'], true) ? (int) ($credits[$mat] ?? 0) : 0]),
            'ues' => $ues->map(fn ($r) => ['total' => $r->total_points, 'moyenne' => $r->moyenne_20, 'credits' => (int) $r->credits_obtenus,
                'max' => $ueMax[$r->ue_id] ?? 0, 'valide' => (bool) $r->valide, 'defaillant' => (bool) $r->defaillant, 'vide' => isset($vides[$r->ue_id]),
                // Observation complète (Déf. / Elim / NV / AC / AV), même règle que l'affichage du PV
                'obs' => Resultats::observationUe($r, isset($vides[$r->ue_id]), $seuil)]),
            'semestre' => $semestre ? ['moyenne' => $semestre->moyenne_generale, 'credits' => (int) $semestre->credits_obtenus,
                'decision' => $semestre->decision, 'mention' => $semestre->mention, 'valide' => (bool) $semestre->valide] : null,
        ]);
    }

    public function action(Request $request, int $id): RedirectResponse
    {
        $u = $request->user();
        $pv = $this->pvs->trouver($id);
        $action = (string) $request->input('action_pv');
        $retour = route('pv_detail', ['id' => $id]);
        if (! $pv || ! $this->pvs->accessible($u, $pv) || ! $this->pvs->gestionnaire($u)) {
            return redirect()->route('pv')->with('erreur', 'Accès refusé.');
        }
        if ($pv->verrouille && $action !== 'deverrouiller') {
            return redirect($retour)->with('erreur', 'Ce PV est verrouillé : déverrouillez-le d\'abord.');
        }

        switch ($action) {
            case 'verrouiller':
            case 'deverrouiller':
            case 'finaliser':
                DB::table('pv_sessions')->where('id', $id)->update($action === 'finaliser' ? ['statut' => 'finalise'] : ['verrouille' => $action === 'verrouiller' ? 1 : 0]);
                $this->journal->action($action, 'pv', "PV « {$pv->libelle} » : $action", $id);

                return redirect($retour)->with('succes', ['verrouiller' => 'PV verrouillé', 'deverrouiller' => 'PV déverrouillé', 'finaliser' => 'PV finalisé'][$action]);

            case 'reporter_notes':
                $nb = DB::transaction(function () use ($pv) {
                    $nb = 0;
                    foreach ($this->pvs->etudiants($pv)->pluck('e.id') as $et) {
                        $nb += $this->resultats->reporterNotes((int) $et, $pv->id);
                        $this->resultats->recalculer($pv->id, (int) $et);
                    }

                    return $nb;
                });

                return redirect($retour)->with('succes', "↩ $nb note(s) reportée(s) et résultats recalculés.");

            case 'recalculer':
                DB::transaction(fn () => $this->recalculerTout($pv));

                return redirect($retour)->with('succes', 'Résultats recalculés');

            case 'vider_matiere':
                $mat = (int) $request->input('matiere_id');
                if (! $u->aRole('admin')) {
                    return redirect($retour)->with('erreur', 'Accès refusé.');
                }
                if (! $this->pvs->ues($pv)->pluck('matieres')->flatten(1)->contains('id', $mat)) {
                    return redirect($retour)->with('erreur', 'Matière non spécifiée.');
                }
                $nb = DB::transaction(function () use ($pv, $mat) {
                    $nb = DB::table('notes')->where(['pv_id' => $pv->id, 'matiere_id' => $mat])->delete();
                    foreach (DB::table('resultats_semestre')->where('pv_id', $pv->id)->pluck('etudiant_id') as $et) {
                        $this->resultats->recalculer($pv->id, (int) $et);
                    }

                    return $nb;
                });
                $this->journal->action('supprimer', 'notes', "Notes de la matière #$mat vidées ($nb) — {$pv->libelle}", $pv->id);

                return redirect($retour)->with('succes', "🗑️ $nb note(s) supprimée(s) pour cette matière.");

            case 'supprimer':
                if ($pv->statut !== 'brouillon') {
                    return redirect($retour)->with('erreur', 'Seul un PV en brouillon peut être supprimé (ses notes et résultats seraient effacés).');
                }
                DB::transaction(function () use ($id) {
                    DB::table('pv_sessions')->where('pv_normale_id', $id)->update(['pv_normale_id' => null]);
                    DB::table('pv_sessions')->where('id', $id)->delete();
                });
                $this->journal->action('supprimer', 'pv', "Suppression du PV « {$pv->libelle} »", $id);

                return redirect()->route('pv')->with('succes', 'PV supprimé avec succès.');
        }

        return redirect($retour);
    }

    /** Recalcul progressif (ancien ajax_recalcul_queue.php). */
    public function file(Request $request, int $id): JsonResponse
    {
        $pv = $this->pvModifiable($request, $id);
        if (! $pv) {
            return response()->json(['ok' => false, 'error' => 'PV verrouillé ou accès refusé'], 403);
        }
        if ($request->input('mode') === 'start') {
            $ids = $this->pvs->etudiants($pv)->pluck('e.id')->map(fn ($i) => (int) $i)->values();

            return response()->json(['ok' => true, 'total' => $ids->count(), 'ids' => $ids]);
        }
        if ($request->input('mode') === 'rangs') {
            $this->resultats->recalculerRangs($pv->id);

            return response()->json(['ok' => true]);
        }
        $et = (int) $request->input('etudiant_id');
        if (! $this->pvs->etudiants($pv)->where('e.id', $et)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Étudiant hors du PV']);
        }
        try {
            $this->resultats->recalculer($pv->id, $et);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'etudiant_id' => $et, 'error' => 'Recalcul impossible']);
        }

        return response()->json(['ok' => true, 'etudiant_id' => $et]);
    }

    /**
     * Cellules du tableau : note et état de chaque matière pour chaque
     * étudiant affiché. État : saisissable, acquise (1ère session ou
     * reportée, non modifiable), ou NC (option non choisie).
     *
     * @return array<int, array<int, array{note: ?object, etat: string}>>
     */
    private function cellules(object $pv, array $ids): array
    {
        $notes = DB::table('notes')->where('pv_id', $pv->id)->whereIn('etudiant_id', $ids)->get()->groupBy('etudiant_id')->map->keyBy('matiere_id');
        $normale = ($pv->type_session === 'rattrapage' && $pv->pv_normale_id)
            ? DB::table('notes')->where('pv_id', $pv->pv_normale_id)->whereIn('etudiant_id', $ids)->get()->groupBy('etudiant_id')->map->keyBy('matiere_id')
            : collect();
        $choix = $this->pvs->choixOptions($pv);

        $cellules = [];
        foreach ($ids as $et) {
            foreach ($this->pvs->ues($pv) as $ue) {
                foreach ($ue->matieres as $m) {
                    $note = $notes[$et][$m->id] ?? null;
                    $n1 = $normale[$et][$m->id] ?? null;
                    $etat = 'saisie';
                    if ($ue->nb_options_a_choisir > 0 && $m->type_matiere === 'optionnelle' && ! in_array((int) $m->id, $choix[$et][$ue->id] ?? [], true)) {
                        $etat = 'nc';
                    } elseif ($n1 && in_array($n1->observation, ['AV', 'AC'], true)) {
                        $etat = 'session1';
                        $note = ($note && $note->note_examen !== null) ? $note : $n1;
                    } elseif ($note && $note->reportee) {
                        $etat = 'reportee';
                    }
                    $cellules[$et][$m->id] = ['note' => $note, 'etat' => $etat];
                }
            }
        }

        return $cellules;
    }

    /**
     * Notes envoyées → lignes à enregistrer, ou erreurs. Refuse étudiants et
     * matières hors du PV, matières acquises ou non choisies, valeurs hors bornes.
     *
     * @return array{0: list<array>, 1: list<string>}
     */
    private function lignesDeNotes(object $pv, array $saisie, int $utilisateur): array
    {
        $ids = array_map('intval', array_keys($saisie));
        $etudiants = $this->pvs->etudiants($pv)->whereIn('e.id', $ids)->pluck('e.nom', 'e.id');
        $cellules = $this->cellules($pv, $etudiants->keys()->map(fn ($i) => (int) $i)->all());
        $matieres = $this->pvs->ues($pv)->pluck('matieres')->flatten(1)->keyBy('id');
        $lignes = [];
        $erreurs = [];

        foreach ($saisie as $et => $parMatiere) {
            $et = (int) $et;
            if (! isset($etudiants[$et])) {
                $erreurs[] = "Étudiant #$et hors de ce PV.";

                continue;
            }
            foreach ((array) $parMatiere as $mat => $valeurs) {
                $mat = (int) $mat;
                $m = $matieres[$mat] ?? null;
                if (! $m || ($cellules[$et][$mat]['etat'] ?? '') !== 'saisie') {
                    continue; // matière étrangère, acquise ou non choisie : ignorée
                }
                $lire = function (string $cle, float $max) use ($valeurs, $m, $etudiants, $et, &$erreurs) {
                    $brut = str_replace(',', '.', trim((string) ($valeurs[$cle] ?? '')));
                    if ($brut === '') {
                        return null;
                    }
                    if (! is_numeric($brut) || (float) $brut < 0 || (float) $brut > $max) {
                        $erreurs[] = "{$etudiants[$et]} — {$m->libelle} : ".($cle === 'exam' ? 'examen' : 'TD')." « $brut » hors de 0 à $max.";

                        return null;
                    }

                    return round((float) $brut, 2);
                };
                $exam = $lire('exam', (float) $m->note_max_examen);
                $cc = (float) $m->note_max_cc > 0 ? $lire('cc', (float) $m->note_max_cc) : null;
                $lignes[] = [
                    'pv_id' => $pv->id, 'etudiant_id' => $et, 'matiere_id' => $mat, 'note_examen' => $exam, 'note_cc' => $cc,
                    // Même calcul que l'ancienne saisie (le moteur juge ensuite le TD manquant)
                    'note_finale' => ($exam !== null || $cc !== null) ? ($exam ?? 0) + ($cc ?? 0) : null,
                    'defaillant' => 0, 'saisie_par' => $utilisateur, 'updated_at' => now(),
                ];
            }
        }

        return [$lignes, $erreurs];
    }

    /**
     * Recalcule tous les étudiants du PV. En rattrapage, les résultats
     * d'étudiants validés en 1ère session sont retirés (ancien comportement).
     */
    private function recalculerTout(object $pv): void
    {
        if ($pv->type_session === 'rattrapage' && $pv->pv_normale_id) {
            $valides = DB::table('resultats_semestre')->where(['pv_id' => $pv->pv_normale_id, 'valide' => 1])->pluck('etudiant_id');
            DB::table('resultats_semestre')->where('pv_id', $pv->id)->whereIn('etudiant_id', $valides)->delete();
            DB::table('resultats_ue')->where('pv_id', $pv->id)->whereIn('etudiant_id', $valides)->delete();
        }
        foreach ($this->pvs->etudiants($pv)->pluck('e.id') as $et) {
            $this->resultats->recalculer($pv->id, (int) $et);
        }
    }

    private function pvModifiable(Request $request, int $id): ?object
    {
        $pv = $this->pvs->trouver($id);

        return $pv && $this->pvs->accessible($request->user(), $pv) && $this->pvs->peutModifier($request->user(), $pv) ? $pv : null;
    }
}
