<?php

namespace App\Http\Controllers\Structure;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Identite;
use App\Services\Journal;
use App\Services\Parametres;
use App\Services\Pv;
use App\Universites\UniversiteCourante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Planning des examens — portage de pages/structure/planning.php
 * (admin, secrétaire, sc_centrale, sc_entite, doyen, chef de département ;
 * promotions de sa faculté).
 *
 * Différences voulues :
 *  - planning par promotion (filière, niveau, année), semestre et session
 *    (normale, rattrapage), et non plus par PV : les examens se planifient
 *    avant les inscriptions pédagogiques et la création des PV. Les PV et
 *    les convocations lisent le planning de leur promotion, semestre et
 *    session (planning_examens ; l'ancien, par PV, est repris à la création
 *    de la table) ;
 *  - date, heures (fin après début), matière de la promotion et du semestre,
 *    salle et surveillants actifs contrôlés ; une salle déjà occupée sur le
 *    créneau est refusée à l'enregistrement (l'avertissement n'était
 *    qu'affiché) ;
 *  - vider la date d'une matière retire sa ligne du planning (il n'y avait
 *    aucun moyen de le faire) ; enregistrement journalisé ;
 *  - impression : logos de l'université et de la faculté (logos UDH et FADEG
 *    écrits en dur).
 */
class PlanningController extends Controller
{
    public const SESSIONS = ['normale' => '1ère session (normale)', 'rattrapage' => '2ème session (rattrapage)'];

    public function __construct(private readonly Perimetre $perimetre, private readonly Pv $pvs) {}

    public function index(Request $request, Identite $identite): View|RedirectResponse
    {
        if (! Schema::hasTable('planning_examens')) {
            return redirect()->route('accueil')->with('erreur', 'Base de données à mettre à jour (php artisan gesnotes:completer-schema --appliquer).');
        }
        $fac = $this->perimetre->faculte($request->user());
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeId = $annees->firstWhere('id', (int) $request->query('annee_id'))?->id ?? $annees->firstWhere('active', 1)?->id ?? $annees->first()?->id ?? 0;
        $promoId = (int) $request->query('promo_id');
        $cible = $promoId ? $this->cible($request, $promoId, (int) $request->query('sem_id'), (string) $request->query('session', 'normale')) : null;
        if ($promoId && ! $cible) {
            return redirect()->route('planning')->with('erreur', 'Accès non autorisé — cette promotion appartient à une autre faculté.');
        }
        $donnees = ['annees' => $annees, 'anneeId' => $cible->annee_id ?? $anneeId, 'cible' => $cible,
            'promos' => DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->where('p.annee_id', $cible->annee_id ?? $anneeId)->when($fac, fn ($q) => $q->where('f.faculte_id', $fac))
                ->orderBy('f.libelle')->orderBy('n.code')->get(['p.id', 'f.libelle as filiere', 'n.code as niveau']),
            'semestres' => DB::table('semestres')->orderBy('code')->get(['id', 'code', 'libelle'])];
        if ($cible) {
            $donnees += ['ues' => $this->pvs->ues($cible),
                'planning' => DB::table('planning_examens as pe')->leftJoin('enseignants as e1', 'e1.id', '=', 'pe.surveillant_1')->leftJoin('enseignants as e2', 'e2.id', '=', 'pe.surveillant_2')
                    ->where(['pe.promotion_id' => $cible->promotion_id, 'pe.semestre_id' => $cible->semestre_id, 'pe.session' => $cible->session])
                    ->get(['pe.*', 'e1.nom as surv1_nom', 'e1.prenoms as surv1_pren', 'e2.nom as surv2_nom', 'e2.prenoms as surv2_pren'])->keyBy('matiere_id'),
                'enseignants' => DB::table('enseignants')->where('actif', 1)->orderBy('nom')->get(['id', 'nom', 'prenoms']),
                'salles' => DB::table('salles')->where('actif', 1)->orderBy('batiment')->orderBy('code')->get(['id', 'code', 'nom', 'capacite', 'batiment'])];
            if ($request->has('imprimer')) {
                return view('structure.planning_impression', $donnees + ['logos' => $identite->logos($cible->faculte_id ? (int) $cible->faculte_id : null),
                    'institution' => app(Parametres::class)->valeur('institution_nom', app(UniversiteCourante::class)->get()->nom)]);
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
        $cible = $this->cible($request, (int) $request->input('promo_id'), (int) $request->input('sem_id'), (string) $request->input('session'));
        if (! $cible) {
            return redirect()->route('planning')->with('erreur', 'Accès non autorisé — cette promotion appartient à une autre faculté.');
        }
        $cle = ['promotion_id' => $cible->promotion_id, 'semestre_id' => $cible->semestre_id, 'session' => $cible->session];
        $retour = redirect()->route('planning', ['promo_id' => $cible->promotion_id, 'sem_id' => $cible->semestre_id, 'session' => $cible->session]);
        $matieres = $this->pvs->ues($cible)->flatMap(fn ($ue) => $ue->matieres)->keyBy('id');
        $salles = DB::table('salles')->where('actif', 1)->pluck('nom', 'id');
        $enseignants = DB::table('enseignants')->where('actif', 1)->pluck('id')->flip();
        $existantes = DB::table('planning_examens')->where($cle)->get()->keyBy('matiere_id');
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
            $lignes[] = $cle + ['matiere_id' => $matId, 'date_examen' => $date, 'heure_debut' => $debut, 'heure_fin' => $fin,
                'salle' => $salle ? $salles[$salle] : null, 'salle_id' => $salle, 'surveillant_1' => $surv('surv1'), 'surveillant_2' => $surv('surv2'),
                'notes' => isset($v['notes']) ? mb_substr((string) $v['notes'], 0, 1000) : null];
        }
        if ($erreurs) {
            return $retour->with('erreur', 'Rien n\'a été enregistré — '.implode(' ; ', $erreurs).'.');
        }
        DB::transaction(function () use ($lignes, $retirees, $cle) {
            if ($lignes) {
                DB::table('planning_examens')->upsert($lignes, ['promotion_id', 'semestre_id', 'session', 'matiere_id'],
                    ['date_examen', 'heure_debut', 'heure_fin', 'salle', 'salle_id', 'surveillant_1', 'surveillant_2', 'notes']);
            }
            if ($retirees) {
                DB::table('planning_examens')->where($cle)->whereIn('matiere_id', $retirees)->delete();
            }
        });
        $journal->action('modifier', 'planning', "Planning des examens enregistré — {$cible->libelle}", (int) $cible->promotion_id);

        return $retour->with('succes', 'Planning sauvegardé.');
    }

    /**
     * Promotion, semestre et session choisis, si la promotion est dans le
     * périmètre de l'agent — avec les champs qu'utilisent Pv::ues() et
     * l'impression (filière, niveau, année, faculté, libellé).
     */
    private function cible(Request $request, int $promoId, int $semId, string $session): ?object
    {
        $c = DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')
            ->where('p.id', $promoId)
            ->first(['p.id as promotion_id', 'p.annee_id', 'f.libelle as filiere', 'f.faculte_id', DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'),
                'n.code as niveau', 'n.libelle as niveau_libelle', 'a.libelle as annee']);
        $sem = DB::table('semestres')->find($semId) ?? DB::table('semestres')->orderBy('code')->first();
        if (! $c || ! $sem || ! $this->perimetre->faculteAutorisee($request->user(), $c->faculte_id !== null ? (int) $c->faculte_id : null)) {
            return null;
        }
        $c->semestre_id = (int) $sem->id;
        $c->sem_libelle = $sem->libelle;
        $c->session = isset(self::SESSIONS[$session]) ? $session : 'normale';
        $c->libelle = "{$c->filiere} {$c->niveau} — {$sem->libelle}".($c->session === 'rattrapage' ? ' — Rattrapage' : '');

        return $c;
    }

    private function occupees(string $date, string $debut, string $fin, int $exclure)
    {
        return DB::table('planning_examens')->where('date_examen', $date)->whereNotNull('salle_id')
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
