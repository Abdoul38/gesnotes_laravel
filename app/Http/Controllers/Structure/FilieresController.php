<?php

namespace App\Http\Controllers\Structure;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Journal;
use App\Services\Maquette;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Filières, départements, UE et matières — portage de pages/structure/filieres.php.
 * Mêmes onglets (?tab=filieres|departements|structure) et mêmes adresses
 * de retour (?tab=…&promo=…) que l'ancienne page.
 */
class FilieresController extends Controller
{
    /** Rôles autorisés à fixer le volume horaire prévu (programme officiel). */
    public const ROLES_VOLUME = ['admin', 'secretaire', 'chef_departement', 'doyen', 'sc_entite'];

    public function __construct(private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    // ── Affichage ─────────────────────────────────────────────

    public function index(Request $request): View
    {
        $u = $request->user();
        $faculte = $this->perimetre->faculte($u);
        $tab = in_array($request->query('tab'), ['departements', 'structure'], true) ? $request->query('tab') : 'filieres';

        $facultes = DB::table('facultes')->where('actif', 1)->orderBy('libelle')->get(['id', 'code', 'libelle']);
        if ($facultes->isEmpty()) {
            // Repli de l'ancienne page : facultés connues seulement par leur libellé
            $facultes = DB::table('filieres')->whereNotNull('faculte')->where('faculte', '!=', '')
                ->distinct()->orderBy('faculte')->pluck('faculte')
                ->map(fn ($l) => (object) ['id' => null, 'code' => '', 'libelle' => $l]);
        }
        $departements = DB::table('departements as d')->join('facultes as f', 'f.id', '=', 'd.faculte_id')
            ->where('d.actif', 1)->orderBy('f.libelle')->orderBy('d.libelle')
            ->get(['d.*', 'f.libelle as faculte_lib', 'f.code as faculte_code']);

        $donnees = [
            'tab' => $tab,
            'facultes' => $facultes,
            'departements' => $departements,
            'depParFaculte' => $departements->groupBy('faculte_id')->map(fn ($l) => $l->map(fn ($d) => ['id' => $d->id, 'libelle' => $d->libelle])->values()),
            'peutFixerVolume' => in_array($u->role, self::ROLES_VOLUME, true),
        ];

        return view('structure.filieres.index', $donnees + match ($tab) {
            'filieres' => $this->ongletFilieres($faculte),
            'departements' => $this->ongletDepartements($departements),
            'structure' => $this->ongletStructure($faculte, (int) $request->query('promo')),
        });
    }

    private function ongletFilieres(int $faculte): array
    {
        return [
            'filieres' => DB::table('filieres as f')->leftJoin('filieres as fp', 'fp.id', '=', 'f.filiere_parente_id')
                ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
                ->orderBy('f.code')->get(['f.*', 'fp.libelle as parente_libelle']),
            'filieresMeres' => DB::table('filieres')->where('actif', 1)
                ->where(fn ($q) => $q->whereNull('filiere_parente_id')->orWhere('filiere_parente_id', 0))
                ->orderBy('libelle')->get(['id', 'code', 'libelle']),
        ];
    }

    private function ongletDepartements($departements): array
    {
        return ['nbFilieres' => DB::table('filieres')->whereIn('departement_id', $departements->pluck('id'))
            ->groupBy('departement_id')->selectRaw('departement_id, COUNT(*) AS nb')->pluck('nb', 'departement_id')];
    }

    private function ongletStructure(int $faculte, int $promoDemandee): array
    {
        $promos = DB::table('promotions as p')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
            ->orderByDesc('a.libelle')->orderBy('f.code')->orderBy('n.code')
            ->get(['p.id', 'p.filiere_id', 'p.niveau_id', 'f.code as fcode', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']);

        // Promotion demandée, si elle est dans le périmètre ; sinon la première.
        $promo = $promos->firstWhere('id', $promoDemandee) ?? $promos->first();
        $ues = collect();
        $sources = collect();
        $ordres = [];
        if ($promo) {
            $ues = DB::table('unites_enseignement as ue')->join('semestres as s', 's.id', '=', 'ue.semestre_id')
                ->where('ue.promotion_id', $promo->id)->orderBy('s.code')->orderBy('ue.ordre')
                ->get(['ue.*', 's.code as sem_code']);
            $matieres = DB::table('matieres')->whereIn('ue_id', $ues->pluck('id'))->where('actif', 1)->orderBy('ordre')->get()->groupBy('ue_id');
            $enseignants = DB::table('affectations as af')->join('enseignants as e', 'e.id', '=', 'af.enseignant_id')
                ->whereIn('af.matiere_id', $matieres->flatten()->pluck('id'))
                ->groupBy('af.matiere_id')
                ->selectRaw("af.matiere_id, GROUP_CONCAT(DISTINCT CONCAT(e.nom,' ',e.prenoms) ORDER BY e.nom SEPARATOR ', ') AS enseignants")
                ->pluck('enseignants', 'matiere_id');
            foreach ($ues as $ue) {
                $ue->matieres = ($matieres[$ue->id] ?? collect())->each(fn ($m) => $m->enseignants = $enseignants[$m->id] ?? null);
                // Ordre proposé pour la prochaine matière de l'UE
                $ordres[$ue->id] = (int) ($ue->matieres->max('ordre') ?? 0) + 1;
            }
            // Sources de copie : même filière et niveau, avec au moins une UE
            $sources = DB::table('promotions as p')
                ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                ->join('unites_enseignement as ue', 'ue.promotion_id', '=', 'p.id')
                ->where('p.id', '!=', $promo->id)->where('p.filiere_id', $promo->filiere_id)->where('p.niveau_id', $promo->niveau_id)
                ->groupBy('p.id', 'f.libelle', 'n.code', 'a.libelle')
                ->orderByDesc('a.libelle')
                ->selectRaw('p.id, f.libelle AS filiere, n.code AS niveau, a.libelle AS annee, COUNT(ue.id) AS nb_ue')
                ->get();
        }

        return [
            'promos' => $promos, 'promo' => $promo, 'ues' => $ues, 'sources' => $sources, 'ordres' => $ordres,
            'semestres' => DB::table('semestres')->orderBy('code')->get(),
        ];
    }

    // ── Départements ──────────────────────────────────────────

    public function departementAjouter(Request $request): RedirectResponse
    {
        $faculteId = (int) $request->input('faculte_id');
        $code = mb_strtoupper(trim((string) $request->input('code_dep')));
        $libelle = trim((string) $request->input('libelle_dep'));
        $chef = trim((string) $request->input('chef_dep')) ?: null;

        if (! $faculteId || $code === '' || $libelle === '') {
            return $this->retour($request, 'erreur', 'Faculté, code et libellé requis.');
        }
        $this->exiger($this->perimetre->faculteAutorisee($request->user(), $faculteId));

        // Le code est unique dans toute l'université : l'ancien « ON DUPLICATE
        // KEY UPDATE » écrasait alors le département d'une AUTRE faculté.
        $existant = DB::table('departements')->where('code', $code)->first();
        if ($existant && (int) $existant->faculte_id !== $faculteId) {
            return $this->retour($request, 'erreur', "Le code «{$code}» est déjà utilisé par un département d'une autre faculté.");
        }
        DB::table('departements')->updateOrInsert(['code' => $code], ['faculte_id' => $faculteId, 'libelle' => $libelle, 'chef' => $chef]);

        return $this->retour($request, 'succes', "Département «{$code}» créé.");
    }

    public function departementModifier(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');
        $code = mb_strtoupper(trim((string) $request->input('code_dep')));
        $libelle = trim((string) $request->input('libelle_dep'));
        if (! $id || $code === '' || $libelle === '') {
            return $this->retour($request, 'erreur', 'Code et libellé requis.');
        }
        $this->exiger($this->perimetre->departement($request->user(), $id));
        if (DB::table('departements')->where('code', $code)->where('id', '!=', $id)->exists()) {
            return $this->retour($request, 'erreur', "Le code «{$code}» est déjà utilisé par un autre département.");
        }
        DB::table('departements')->where('id', $id)->update(['code' => $code, 'libelle' => $libelle, 'chef' => trim((string) $request->input('chef_dep')) ?: null]);

        return $this->retour($request, 'succes', 'Département mis à jour.');
    }

    public function departementSupprimer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');
        $this->exiger($this->perimetre->departement($request->user(), $id));
        $nb = DB::table('filieres')->where('departement_id', $id)->count();
        if ($nb > 0) {
            return $this->retour($request, 'erreur', "Impossible : $nb filière(s) utilisent ce département.");
        }
        DB::table('departements')->where('id', $id)->delete();

        return $this->retour($request, 'succes', 'Département supprimé.');
    }

    // ── Filières ──────────────────────────────────────────────

    public function filiereEnregistrer(Request $request): RedirectResponse
    {
        $u = $request->user();
        $id = (int) $request->input('id');
        $code = mb_strtoupper(trim((string) $request->input('code')));
        $libelle = trim((string) $request->input('libelle'));
        $faculteId = (int) $request->input('faculte_id');
        $departementId = (int) $request->input('departement_id') ?: null;
        $parente = (int) $request->input('filiere_parente_id') ?: null;
        $type = in_array($request->input('type_formation'), ['classique', 'professionnelle'], true) ? $request->input('type_formation') : 'classique';

        if ($code === '' || $libelle === '') {
            return $this->retour($request, 'erreur', 'Code et libellé requis.');
        }
        if (! $faculteId) {
            return $this->retour($request, 'erreur', 'Veuillez sélectionner une faculté.');
        }
        $this->exiger($this->perimetre->faculteAutorisee($u, $faculteId) && (! $id || $this->perimetre->filiere($u, $id)));
        if ($parente !== null && $parente === $id) {
            return $this->retour($request, 'erreur', 'Une filière ne peut pas être sa propre filière mère.');
        }
        if (DB::table('filieres')->where('code', $code)->where('id', '!=', $id)->exists()) {
            return $this->retour($request, 'erreur', "Le code «{$code}» est déjà utilisé par une autre filière.");
        }

        $valeurs = [
            'code' => $code, 'libelle' => $libelle,
            // Libellés texte gardés pour les anciens écrans (rétrocompatibilité)
            'faculte' => (string) DB::table('facultes')->where('id', $faculteId)->value('libelle'),
            'departement' => (string) ($departementId ? DB::table('departements')->where('id', $departementId)->value('libelle') : ''),
            'faculte_id' => $faculteId, 'departement_id' => $departementId, 'filiere_parente_id' => $parente,
            'domaine' => trim((string) $request->input('domaine')) ?: null,
            'mention_academique' => trim((string) $request->input('mention_academique')) ?: null,
            'type_formation' => $type,
        ];
        if ($id) {
            DB::table('filieres')->where('id', $id)->update($valeurs);

            return $this->retour($request, 'succes', 'Filière mise à jour.');
        }
        DB::table('filieres')->insert($valeurs);

        return $this->retour($request, 'succes', "Filière «{$code}» créée.");
    }

    public function filiereSupprimer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');
        $this->exiger($this->perimetre->filiere($request->user(), $id));

        // L'ancienne page supprimait sans aucun contrôle : la cascade effaçait
        // promotions, inscriptions, PV, notes et tarifs, et retirait la filière
        // des dossiers étudiants.
        $liens = array_filter([
            'promotion(s)' => DB::table('promotions')->where('filiere_id', $id)->count(),
            'étudiant(s)' => DB::table('etudiants')->where('filiere_id', $id)->count(),
        ]);
        if ($liens) {
            $detail = implode(', ', array_map(fn ($n, $quoi) => "$n $quoi", $liens, array_keys($liens)));

            return $this->retour($request, 'erreur', "Impossible — cette filière a encore $detail.");
        }
        DB::table('filieres')->where('id', $id)->delete();
        $this->journal->audit('FILIERE_SUPPRIMER', 'filieres', $id);

        return $this->retour($request, 'succes', 'Filière supprimée.');
    }

    // ── Unités d'enseignement ─────────────────────────────────

    public function ueCopier(Request $request, Maquette $maquette): RedirectResponse
    {
        $promo = (int) $request->input('promo_id');
        $source = (int) $request->input('source_promo_id') ?: null;
        if (! $promo) {
            return $this->retour($request, 'erreur', 'Promotion manquante.');
        }
        $u = $request->user();
        $this->exiger($this->perimetre->promotion($u, $promo) && (! $source || $this->perimetre->promotion($u, $source)));

        $nb = $maquette->copierUEs($promo, $source, $request->input('forcer_copie') === '1');

        return match (true) {
            $nb > 0 => $this->retour($request, 'succes', "✅ $nb UE(s) copiées avec succès."),
            $nb === Maquette::DEJA_DES_UE => $this->retour($request, 'avertissement', '⚠️ Cette promotion a déjà des UE. Cochez « Forcer la copie » pour écraser.'),
            default => $this->retour($request, 'erreur', 'Aucune UE à copier (aucune source trouvée ou source vide).'),
        };
    }

    public function ueEnregistrer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('ue_id_edit');
        $code = trim((string) $request->input('code_ue'));
        $libelle = trim((string) $request->input('libelle_ue'));
        $semestre = (int) $request->input('sem_id');
        $valeurs = [
            'code' => $code, 'libelle' => $libelle,
            'credits_total' => (int) $request->input('credits', 6),
            'ordre' => (int) $request->input('ordre'),
            'nb_options_a_choisir' => max(0, (int) $request->input('nb_options_a_choisir')),
        ];
        if ($code === '' || $libelle === '' || ! $semestre) {
            return $this->retour($request, 'erreur', 'Semestre, code et intitulé requis.');
        }

        if ($id) {
            $this->exiger($this->perimetre->ue($request->user(), $id));
            DB::table('unites_enseignement')->where('id', $id)->update($valeurs + ['semestre_id' => $semestre]);

            return $this->retour($request, 'succes', "UE «{$code}» mise à jour.");
        }

        $promo = (int) $request->input('promo_id');
        if (! $promo) {
            return $this->retour($request, 'erreur', 'Promotion manquante.');
        }
        $this->exiger($this->perimetre->promotion($request->user(), $promo));
        // Même code dans la même promotion et le même semestre : mise à jour (comme avant)
        DB::table('unites_enseignement')->updateOrInsert(
            ['promotion_id' => $promo, 'semestre_id' => $semestre, 'code' => $code],
            array_diff_key($valeurs, ['code' => 1]),
        );

        return $this->retour($request, 'succes', "UE «{$code}» ajoutée.");
    }

    public function ueSupprimer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('ue_id_edit');
        $this->exiger($this->perimetre->ue($request->user(), $id));

        $nbMat = DB::table('matieres')->where('ue_id', $id)->where('actif', 1)->count();
        if ($nbMat > 0) {
            return $this->retour($request, 'erreur', "Impossible — cette UE contient encore $nbMat matière(s). Supprimez-les d'abord.");
        }
        // Les matières « supprimées » ne sont que désactivées : la cascade
        // effacerait leurs notes et les résultats de l'UE.
        $matieres = DB::table('matieres')->where('ue_id', $id)->pluck('id');
        if (DB::table('notes')->whereIn('matiere_id', $matieres)->exists() || DB::table('resultats_ue')->where('ue_id', $id)->exists()) {
            return $this->retour($request, 'erreur', 'Impossible — des notes ou des résultats sont enregistrés pour cette UE.');
        }
        DB::table('unites_enseignement')->where('id', $id)->delete();

        return $this->retour($request, 'succes', 'UE supprimée.');
    }

    // ── Matières ──────────────────────────────────────────────

    public function matiereEnregistrer(Request $request): RedirectResponse
    {
        $u = $request->user();
        $id = (int) $request->input('mat_id');
        $libelle = trim((string) $request->input('libelle_mat'));
        $maxExam = (float) $request->input('max_exam', 40);
        $maxCC = (float) $request->input('max_cc', 0);
        $valeurs = [
            'libelle' => $libelle,
            'note_max_examen' => $maxExam, 'note_max_cc' => $maxCC, 'note_max_totale' => $maxExam + $maxCC,
            'credits' => (int) $request->input('credits_mat', 4),
            'coefficient' => max(0.5, (float) $request->input('coef_mat', 1.0)),
            'ordre' => (int) $request->input('ordre_mat'),
            'type_matiere' => in_array($request->input('type_matiere'), ['obligatoire', 'optionnelle'], true) ? $request->input('type_matiere') : 'obligatoire',
        ];
        $peutVolume = in_array($u->role, self::ROLES_VOLUME, true);
        $volume = $this->volumeSaisi($request);

        if ($id) {
            if ($libelle === '') {
                return $this->retour($request, 'erreur', 'Intitulé requis.');
            }
            $this->exiger($this->perimetre->matiere($u, $id));
            // Sans le droit de fixer le volume, la valeur en base est conservée.
            if ($peutVolume) {
                $valeurs['volume_horaire_prevu'] = $volume;
            }
            DB::table('matieres')->where('id', $id)->update($valeurs);

            return $this->retour($request, 'succes', "Matière «{$libelle}» mise à jour.");
        }

        $ue = (int) $request->input('ue_id');
        $code = trim((string) $request->input('code_mat'));
        if (! $ue || $code === '' || $libelle === '') {
            return $this->retour($request, 'erreur', 'UE, code et intitulé requis.');
        }
        $this->exiger($this->perimetre->ue($u, $ue));
        $valeurs['volume_horaire_prevu'] = $peutVolume ? $volume : null;
        // Même code dans la même UE : mise à jour, et RÉACTIVATION si la
        // matière avait été supprimée (avant, elle restait invisible malgré
        // le message « ajoutée »).
        DB::table('matieres')->updateOrInsert(['ue_id' => $ue, 'code' => $code], $valeurs + ['actif' => 1]);

        return $this->retour($request, 'succes', "Matière «{$code}» ajoutée.");
    }

    public function matiereSupprimer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('mat_id');
        $this->exiger($this->perimetre->matiere($request->user(), $id));
        // Simple désactivation : les notes déjà saisies sont conservées.
        DB::table('matieres')->where('id', $id)->update(['actif' => 0]);

        return $this->retour($request, 'succes', 'Matière supprimée.');
    }

    /**
     * Volume horaire appliqué à toutes les matières actives SANS volume.
     * Limité à la faculté de l'utilisateur s'il en a une (l'ancienne page
     * touchait toujours toute l'université).
     */
    public function volumeMasse(Request $request): RedirectResponse
    {
        $u = $request->user();
        if (! in_array($u->role, self::ROLES_VOLUME, true)) {
            return $this->retour($request, 'erreur', "Seuls l'administration, le chef de département ou le doyen peuvent fixer le volume horaire.");
        }
        $volume = (float) str_replace(',', '.', (string) $request->input('volume_masse', '0'));
        if ($volume <= 0) {
            return $this->retour($request, 'erreur', 'Le volume horaire doit être supérieur à 0.');
        }

        $faculte = $this->perimetre->faculte($u);
        $nb = DB::table('matieres')->whereNull('volume_horaire_prevu')->where('actif', 1)
            ->when($faculte, fn ($q) => $q->whereIn('ue_id', DB::table('unites_enseignement as ue')
                ->join('promotions as p', 'p.id', '=', 'ue.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                ->where('f.faculte_id', $faculte)->select('ue.id')))
            ->update(['volume_horaire_prevu' => $volume]);
        $this->journal->action('modifier', 'matiere', "Volume horaire fixé à $volume h en masse sur $nb matière(s) sans valeur existante");

        return $this->retour($request, 'succes', "$nb matière(s) mise(s) à jour avec $volume h — les matières ayant déjà un volume propre n'ont pas été touchées.");
    }

    // ── Outils ────────────────────────────────────────────────

    private function volumeSaisi(Request $request): ?float
    {
        $v = trim((string) $request->input('volume_prevu_mat'));

        return $v === '' ? null : (float) str_replace(',', '.', $v);
    }

    private function exiger(bool $autorise): void
    {
        abort_unless($autorise, 403, "Cet élément n'appartient pas à votre faculté.");
    }

    /** Même retour que l'ancienne page : onglet + promotion affichée. */
    private function retour(Request $request, string $type, string $message): RedirectResponse
    {
        $promo = (int) ($request->input('promo_id_redirect') ?: $request->input('promo_id') ?: $request->input('promo_retour') ?: $request->query('promo'));
        $tab = in_array($request->input('tab'), ['filieres', 'departements', 'structure'], true) ? $request->input('tab') : 'structure';

        return redirect()->route('filieres', array_filter(['tab' => $tab, 'promo' => $promo ?: null]))->with($type, $message);
    }
}
