<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\HistoriqueNotes;
use App\Services\Journal;
use App\Services\Parametres;
use App\Services\PresidentsJury;
use App\Services\Pv;
use App\Services\Resultats;
use App\Services\SaisieNotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
 *
 * Ajout : repêchage par le jury, tracé (voir repecher()).
 */
class PvDetailController extends Controller
{
    private const PAR_PAGE = 50;

    public function __construct(
        private readonly Pv $pvs,
        private readonly Resultats $resultats,
        private readonly Journal $journal,
        private readonly Parametres $parametres,
        private readonly PresidentsJury $presidents,
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
            'repechages' => $this->repechages($pv),
            'repechagesDisponibles' => $this->repechagesDisponibles(),
            'etudiantsPv' => $tous,
            'president' => PresidentsJury::nom($this->presidents->pour($pv)),
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
            // Ancienne → nouvelle valeur de chaque note modifiée, dans les journaux d'activité
            app(HistoriqueNotes::class)->suivre($pv->id, 'saisie du PV', fn () => DB::transaction(function () use ($pv, $lignes) {
                foreach (array_chunk($lignes, 500) as $paquet) {
                    DB::table('notes')->upsert($paquet, ['pv_id', 'etudiant_id', 'matiere_id'], ['note_examen', 'note_cc', 'note_finale', 'defaillant', 'saisie_par', 'updated_at']);
                }
                $this->recalculerTout($pv);
            }), array_values(array_unique(array_column($lignes, 'etudiant_id'))));
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
                $nb = app(HistoriqueNotes::class)->suivre($pv->id, 'matière vidée', fn () => DB::transaction(function () use ($pv, $mat) {
                    $nb = DB::table('notes')->where(['pv_id' => $pv->id, 'matiere_id' => $mat])->delete();
                    foreach (DB::table('resultats_semestre')->where('pv_id', $pv->id)->pluck('etudiant_id') as $et) {
                        $this->resultats->recalculer($pv->id, (int) $et);
                    }

                    return $nb;
                }));
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

    /**
     * Repêchage par le jury : des points sont ajoutés à la note d'examen d'une
     * matière pour qu'un étudiant valide. Chaque repêchage est enregistré
     * (notes et décision avant / après, points, motif, auteur, date) et
     * journalisé ; il peut être annulé (la note d'origine est rétablie, la
     * trace reste, marquée annulée).
     */
    public function repecher(Request $request, int $id): RedirectResponse
    {
        $pv = $this->pvModifiable($request, $id);
        $retour = redirect()->route('pv_detail', ['id' => $id, 'ppage' => (int) $request->input('ppage', 1)]);
        if (! $pv) {
            return $retour->with('erreur', 'Ce PV est verrouillé ou vous ne pouvez pas le modifier.');
        }
        if (! $this->repechagesDisponibles()) {
            return $retour->with('erreur', 'Repêchage indisponible : la table « repechages » manque (php artisan gesnotes:completer-schema --appliquer).');
        }
        $et = (int) $request->input('etudiant_id');
        $mat = (int) $request->input('matiere_id');
        $points = round((float) str_replace(',', '.', (string) $request->input('points')), 2);
        $motif = trim((string) $request->input('motif'));
        $etudiant = $this->pvs->etudiants($pv)->where('e.id', $et)->first(['e.id', 'e.nom', 'e.prenoms']);
        $m = $this->pvs->ues($pv)->pluck('matieres')->flatten(1)->firstWhere('id', $mat);
        if (! $etudiant || ! $m) {
            return $retour->with('erreur', 'Choisissez un étudiant et une matière de ce PV.');
        }
        if ($points <= 0) {
            return $retour->with('erreur', 'Indiquez le nombre de points accordés (supérieur à 0).');
        }
        if (mb_strlen($motif) < 5) {
            return $retour->with('erreur', 'Le motif du repêchage est obligatoire (décision du jury, par exemple).');
        }
        if (($this->cellules($pv, [$et])[$et][$mat]['etat'] ?? '') !== 'saisie') {
            return $retour->with('erreur', "Cette matière est déjà acquise ou n'a pas été choisie par l'étudiant : pas de repêchage possible.");
        }
        $note = DB::table('notes')->where(['pv_id' => $pv->id, 'etudiant_id' => $et, 'matiere_id' => $mat])->first();
        if (! $note || $note->note_examen === null) {
            return $retour->with('erreur', "L'étudiant n'a pas de note d'examen dans cette matière : saisissez d'abord sa note.");
        }
        $nouvelle = round((float) $note->note_examen + $points, 2);
        if ($nouvelle > (float) $m->note_max_examen) {
            return $retour->with('erreur', "Avec +$points point(s), la note d'examen ($nouvelle) dépasserait le maximum (".(float) $m->note_max_examen.').');
        }

        $r = DB::transaction(function () use ($pv, $et, $mat, $m, $note, $points, $nouvelle, $motif, $request) {
            $avant = DB::table('resultats_semestre')->where(['pv_id' => $pv->id, 'etudiant_id' => $et])->value('decision');
            $finale = $nouvelle + ((float) $m->note_max_cc > 0 ? (float) ($note->note_cc ?? 0) : 0);
            DB::table('notes')->where('id', $note->id)->update(['note_examen' => $nouvelle, 'note_finale' => $finale,
                'saisie_par' => $request->user()->id, 'updated_at' => now()]);
            $this->resultats->recalculer($pv->id, $et);
            $apres = DB::table('resultats_semestre')->where(['pv_id' => $pv->id, 'etudiant_id' => $et])->value('decision');
            DB::table('repechages')->insert(['pv_id' => $pv->id, 'etudiant_id' => $et, 'matiere_id' => $mat, 'points' => $points,
                'note_examen_avant' => $note->note_examen, 'note_examen_apres' => $nouvelle, 'note_finale_avant' => $note->note_finale, 'note_finale_apres' => $finale,
                'decision_avant' => $avant, 'decision_apres' => $apres, 'motif' => mb_substr($motif, 0, 500), 'fait_par' => $request->user()->id, 'created_at' => now()]);
            $this->resultats->recalculerRangs($pv->id);

            return ['avant' => $avant, 'apres' => $apres];
        });
        $qui = trim($etudiant->nom.' '.$etudiant->prenoms);
        $this->journal->action('repecher', 'notes', "Repêchage — {$pv->libelle} — $qui — {$m->libelle} : examen "
            .(float) $note->note_examen." → $nouvelle (+$points) ; décision « {$r['avant']} » → « {$r['apres']} » ; motif : $motif", $pv->id);

        return $retour->with('succes', "🛟 $qui repêché(e) en {$m->libelle} : examen ".(float) $note->note_examen." → $nouvelle (+$points). Décision : ".($r['apres'] ?? '—').'.');
    }

    /** Annulation d'un repêchage : note d'origine rétablie, trace conservée (marquée annulée). */
    public function annulerRepechage(Request $request, int $id, int $repechage): RedirectResponse
    {
        $pv = $this->pvModifiable($request, $id);
        $retour = redirect()->route('pv_detail', ['id' => $id]);
        if (! $pv || ! $this->repechagesDisponibles()) {
            return $retour->with('erreur', 'Ce PV est verrouillé ou vous ne pouvez pas le modifier.');
        }
        $r = DB::table('repechages')->where(['id' => $repechage, 'pv_id' => $pv->id, 'annule' => 0])->first();
        if (! $r) {
            return $retour->with('erreur', 'Repêchage introuvable ou déjà annulé.');
        }
        $note = DB::table('notes')->where(['pv_id' => $pv->id, 'etudiant_id' => $r->etudiant_id, 'matiere_id' => $r->matiere_id])->first();
        if (! $note || abs((float) $note->note_examen - (float) $r->note_examen_apres) > 0.001) {
            return $retour->with('erreur', "La note a été modifiée depuis le repêchage : corrigez-la directement dans le tableau (le repêchage n'a pas été annulé).");
        }
        DB::transaction(function () use ($pv, $r, $note, $request) {
            $mat = DB::table('matieres')->find($r->matiere_id);
            DB::table('notes')->where('id', $note->id)->update(['note_examen' => $r->note_examen_avant,
                'note_finale' => (float) $r->note_examen_avant + ((float) $mat->note_max_cc > 0 ? (float) ($note->note_cc ?? 0) : 0),
                'saisie_par' => $request->user()->id, 'updated_at' => now()]);
            $this->resultats->recalculer($pv->id, (int) $r->etudiant_id);
            $this->resultats->recalculerRangs($pv->id);
            DB::table('repechages')->where('id', $r->id)->update(['annule' => 1, 'annule_par' => $request->user()->id, 'annule_le' => now()]);
        });
        $this->journal->action('repecher', 'notes', "Annulation du repêchage #{$r->id} — {$pv->libelle} — étudiant #{$r->etudiant_id}, matière #{$r->matiere_id} : examen rétabli à ".(float) $r->note_examen_avant, $pv->id);

        return $retour->with('succes', 'Repêchage annulé : la note d\'origine est rétablie (la trace est conservée).');
    }

    private function repechagesDisponibles(): bool
    {
        return Schema::hasTable('repechages');
    }

    /** Repêchages du PV (actifs et annulés), avec étudiant, matière et auteurs. */
    private function repechages(object $pv): Collection
    {
        if (! $this->repechagesDisponibles()) {
            return collect();
        }

        return DB::table('repechages as r')->join('etudiants as e', 'e.id', '=', 'r.etudiant_id')->join('matieres as m', 'm.id', '=', 'r.matiere_id')
            ->leftJoin('utilisateurs as u', 'u.id', '=', 'r.fait_par')->leftJoin('utilisateurs as ua', 'ua.id', '=', 'r.annule_par')
            ->where('r.pv_id', $pv->id)->orderByDesc('r.created_at')->orderByDesc('r.id')
            ->get(['r.*', 'e.nom', 'e.prenoms', 'e.numero', 'm.libelle as matiere', DB::raw("TRIM(CONCAT(COALESCE(u.prenoms, ''), ' ', COALESCE(u.nom, ''))) AS auteur"),
                DB::raw("TRIM(CONCAT(COALESCE(ua.prenoms, ''), ' ', COALESCE(ua.nom, ''))) AS annule_par_nom")]);
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
                // Rattrapage : seul l'examen est composé, le TD en découle (règle de l'université)
                $cc = $pv->type_session === 'rattrapage' ? SaisieNotes::tdRattrapage($exam, $m)
                    : ((float) $m->note_max_cc > 0 ? $lire('cc', (float) $m->note_max_cc) : null);
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
