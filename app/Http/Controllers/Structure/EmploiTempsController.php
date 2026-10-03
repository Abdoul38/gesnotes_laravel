<?php

namespace App\Http\Controllers\Structure;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Identite;
use App\Services\Journal;
use App\Services\Parametres;
use App\Universites\UniversiteCourante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Emploi du temps d'une promotion — portage de pages/structure/emploi_temps.php.
 *
 * Différences voulues :
 *  - modification réservée au personnel de scolarité (admin, secrétaire,
 *    sc_centrale, sc_entite, doyen, chef de département) et à sa faculté :
 *    tout compte avec la permission de lecture ajoutait, modifiait ou
 *    supprimait les séances de n'importe quelle promotion (par simple id) ;
 *  - jour, horaires (fin après début), type, couleur (#rrggbb), matière de la
 *    promotion et du semestre, enseignant et salle actifs contrôlés ; salle
 *    déjà occupée refusée à l'enregistrement, et occupation calculée sur la
 *    même année et le même semestre (tous confondus avant) ;
 *  - suppression dans un formulaire séparé (elle était imbriquée dans le
 *    formulaire de modification) ; actions journalisées.
 */
class EmploiTempsController extends Controller
{
    public const JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

    public const HEURES = ['07:00', '08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'];

    public const TYPES = ['cours' => ['Cours', '#1A3A5C'], 'td' => ['TD', '#1b5e20'], 'tp' => ['TP', '#6a1b9a'], 'exam' => ['Examen', '#b71c1c']];

    private const GESTION = ['admin', 'secretaire', 'sc_centrale', 'sc_entite', 'doyen', 'chef_departement'];

    public function __construct(private readonly Perimetre $perimetre) {}

    public function index(Request $request, Identite $identite, Parametres $parametres, UniversiteCourante $universite): View
    {
        $u = $request->user();
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $semestres = DB::table('semestres')->orderBy('code')->get();
        $anneeId = (int) $request->query('annee_id') ?: (int) ($annees->firstWhere('active', 1)->id ?? $annees->first()->id ?? 0);
        $semId = (int) $request->query('sem_id') ?: (int) ($semestres->first()->id ?? 0);
        $promos = $this->promotions($u)->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('n.code')
            ->get(['p.id', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee', 'a.id as annee_id']);
        $promo = $promos->firstWhere('id', (int) $request->query('promo_id'));
        $seances = $promo ? DB::table('emploi_temps as et')->leftJoin('matieres as m', 'm.id', '=', 'et.matiere_id')->leftJoin('enseignants as e', 'e.id', '=', 'et.enseignant_id')
            ->where(['et.promotion_id' => $promo->id, 'et.annee_id' => $anneeId, 'et.semestre_id' => $semId])
            ->orderByRaw("FIELD(et.jour, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi')")->orderBy('et.heure_debut')
            ->get(['et.*', 'm.libelle as matiere', 'm.code as mat_code', 'e.nom as ens_nom', 'e.prenoms as ens_pren']) : collect();
        $donnees = ['annees' => $annees, 'semestres' => $semestres, 'promos' => $promos, 'promo' => $promo, 'anneeId' => $anneeId, 'semId' => $semId,
            'grille' => $this->grille($seances), 'seances' => $seances, 'peutModifier' => in_array($u->role, self::GESTION, true),
            'matieres' => $promo ? $this->matieres($promo->id, $semId)->get(['m.id', 'm.libelle', 'm.code']) : collect(),
            'enseignants' => DB::table('enseignants')->where('actif', 1)->orderBy('nom')->get(['id', 'nom', 'prenoms']),
            'salles' => DB::table('salles')->where('actif', 1)->orderBy('batiment')->orderBy('code')->get(['id', 'code', 'nom', 'capacite', 'batiment'])];
        if ($promo && $request->has('imprimer')) {
            return view('structure.emploi_temps_impression', $donnees + ['logo' => $identite->logos()['gauche'],
                'institution' => $parametres->valeur('institution_nom', $universite->get()->nom),
                'semestre' => $semestres->firstWhere('id', $semId), 'annee' => $annees->firstWhere('id', $anneeId)]);
        }

        return view('structure.emploi_temps', $donnees);
    }

    public function disponibilite(Request $request): JsonResponse
    {
        $jour = (string) $request->query('jour');
        $debut = (string) $request->query('heure_debut');
        $fin = (string) $request->query('heure_fin');
        if (! in_array($jour, self::JOURS, true) || ! $this->heure($debut) || ! $this->heure($fin)) {
            return response()->json(['occupees' => []]);
        }

        return response()->json(['occupees' => $this->occupees($jour, $debut, $fin, (int) $request->query('annee_id'), (int) $request->query('sem_id'),
            (int) $request->query('exclude_id'))->map(fn ($v) => (int) $v)->values()]);
    }

    public function enregistrer(Request $request, Journal $journal): RedirectResponse
    {
        $u = $request->user();
        $id = (int) $request->input('id');
        $existante = $id ? DB::table('emploi_temps')->find($id) : null;
        $promoId = $existante->promotion_id ?? (int) $request->input('promotion_id');
        $anneeId = $existante->annee_id ?? (int) $request->input('annee_id');
        $semId = $existante->semestre_id ?? (int) $request->input('semestre_id');
        $retour = redirect()->route('emploi_temps', ['promo_id' => $promoId, 'annee_id' => $anneeId, 'sem_id' => $semId]);
        if (! in_array($u->role, self::GESTION, true) || ! $this->promotions($u)->where('p.id', $promoId)->exists() || ($id && ! $existante)) {
            return $retour->with('erreur', "Vous n'êtes pas autorisé à modifier cet emploi du temps.");
        }
        if (! DB::table('annees_academiques')->where('id', $anneeId)->exists() || ! DB::table('semestres')->where('id', $semId)->exists()) {
            return $retour->with('erreur', 'Année ou semestre introuvable.');
        }
        $v = Validator::make($request->all(), [
            'jour' => ['required', Rule::in(self::JOURS)], 'heure_debut' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'heure_fin' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'], 'type_seance' => ['required', Rule::in(array_keys(self::TYPES))],
            'couleur' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'matiere_id' => ['nullable', Rule::in($this->matieres($promoId, $semId)->pluck('m.id')->all())],
            'enseignant_id' => ['nullable', Rule::exists('enseignants', 'id')->where('actif', 1)], 'salle_id' => ['nullable', Rule::exists('salles', 'id')->where('actif', 1)],
        ], ['in' => 'Valeur invalide : :attribute.', 'regex' => 'Format invalide : :attribute.'],
            ['heure_debut' => 'heure de début', 'heure_fin' => 'heure de fin', 'matiere_id' => 'matière de la promotion', 'type_seance' => 'type']);
        if ($v->fails()) {
            return $retour->with('erreur', $v->errors()->first());
        }
        $d = $v->validated();
        if ($d['heure_fin'] <= $d['heure_debut']) {
            return $retour->with('erreur', "L'heure de fin doit suivre l'heure de début.");
        }
        $salle = (int) ($d['salle_id'] ?? 0) ?: null;
        if ($salle && $this->occupees($d['jour'], $d['heure_debut'], $d['heure_fin'], $anneeId, $semId, $id)->contains($salle)) {
            return $retour->with('erreur', 'Cette salle est déjà occupée sur ce créneau.');
        }
        $valeurs = ['jour' => $d['jour'], 'heure_debut' => $d['heure_debut'], 'heure_fin' => $d['heure_fin'], 'matiere_id' => ($d['matiere_id'] ?? null) ?: null,
            'enseignant_id' => ($d['enseignant_id'] ?? null) ?: null, 'salle_id' => $salle, 'salle' => $salle ? DB::table('salles')->where('id', $salle)->value('nom') : null,
            'type_seance' => $d['type_seance'], 'couleur' => $d['couleur'] ?? self::TYPES[$d['type_seance']][1]];
        if ($id) {
            DB::table('emploi_temps')->where('id', $id)->update($valeurs);
        } else {
            $id = DB::table('emploi_temps')->insertGetId($valeurs + ['promotion_id' => $promoId, 'annee_id' => $anneeId, 'semestre_id' => $semId]);
        }
        $journal->action($existante ? 'modifier' : 'creer', 'emploi_temps', "Séance du {$d['jour']} {$d['heure_debut']}-{$d['heure_fin']} (promotion #$promoId)", $id);

        return $retour->with('succes', $existante ? 'Séance modifiée.' : 'Séance ajoutée.');
    }

    public function supprimer(Request $request, Journal $journal): RedirectResponse
    {
        $u = $request->user();
        $s = DB::table('emploi_temps')->find((int) $request->input('id'));
        $retour = $s ? redirect()->route('emploi_temps', ['promo_id' => $s->promotion_id, 'annee_id' => $s->annee_id, 'sem_id' => $s->semestre_id]) : redirect()->route('emploi_temps');
        if (! $s || ! in_array($u->role, self::GESTION, true) || ! $this->promotions($u)->where('p.id', $s->promotion_id)->exists()) {
            return $retour->with('erreur', 'Séance introuvable.');
        }
        DB::table('emploi_temps')->where('id', $s->id)->delete();
        $journal->action('supprimer', 'emploi_temps', "Séance du {$s->jour} {$s->heure_debut} supprimée (promotion #{$s->promotion_id})", (int) $s->id);

        return $retour->with('succes', 'Séance supprimée.');
    }

    /**
     * Grille jour × créneau : ['seance' => …, 'rowspan' => n], 'skip' (cellule
     * couverte) ou null — même calcul que l'ancienne page.
     */
    private function grille($seances): array
    {
        $n = count(self::HEURES) - 1;
        $grille = array_fill_keys(self::JOURS, array_fill(0, $n, null));
        foreach ($seances as $s) {
            $debut = substr((string) $s->heure_debut, 0, 5);
            $fin = substr((string) $s->heure_fin, 0, 5);
            $i = collect(self::HEURES)->search(fn ($h) => $h >= $debut);
            $j = collect(self::HEURES)->search(fn ($h) => $h >= $fin);
            if ($i === false || $j === false || $i >= $n) {
                continue;
            }
            $rowspan = max(1, $j - $i);
            $grille[$s->jour][$i] = ['seance' => $s, 'rowspan' => $rowspan];
            for ($k = $i + 1; $k < $i + $rowspan && $k < $n; $k++) {
                $grille[$s->jour][$k] = 'skip';
            }
        }

        return $grille;
    }

    private function promotions(Utilisateur $u)
    {
        $fac = $this->perimetre->faculte($u);

        return DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->when($fac, fn ($q) => $q->where('f.faculte_id', $fac));
    }

    private function matieres(int $promoId, int $semId)
    {
        return DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
            ->where(['ue.promotion_id' => $promoId, 'ue.semestre_id' => $semId, 'm.actif' => 1])->orderBy('ue.ordre')->orderBy('m.ordre');
    }

    private function occupees(string $jour, string $debut, string $fin, int $annee, int $sem, int $exclure)
    {
        return DB::table('emploi_temps')->where(['jour' => $jour, 'annee_id' => $annee, 'semestre_id' => $sem])->whereNotNull('salle_id')
            ->where('heure_debut', '<', $fin)->where('heure_fin', '>', $debut)->where('id', '!=', $exclure)->pluck('salle_id');
    }

    private function heure(string $h): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $h);
    }
}
