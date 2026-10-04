<?php

namespace App\Http\Controllers\Structure;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Support\Tableur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Import des UE et matières depuis un fichier Excel d'ingénierie de
 * formation (dépôt → aperçu → confirmation) — portage de
 * pages/outils/import_ue_matieres.php.
 *
 * Différences voulues : fichier temporaire hors du dossier web et désigné
 * par la session (le formulaire renvoyait un nom de fichier) ; promotions
 * limitées à la faculté de l'utilisateur ; import d'un semestre en une
 * transaction ; jeton CSRF aussi à l'étape d'analyse.
 */
class ImportUeMatieresController extends Controller
{
    private const SESSION = 'import_ue_matieres';

    public function __construct(private readonly Perimetre $perimetre) {}

    public function index(Request $request): View
    {
        return view('structure.import_ue_matieres', ['semestres' => null]);
    }

    public function analyser(Request $request): View|RedirectResponse
    {
        $fichier = $request->file('fichier');
        if (! $fichier || ! $fichier->isValid() || strtolower($fichier->getClientOriginalExtension()) !== 'xlsx' || $fichier->getSize() > 10 * 1024 * 1024) {
            return redirect()->route('import_ue_matieres')->with('erreur', 'Seuls les fichiers .xlsx (10 Mo maximum) sont acceptés.');
        }
        $this->oublierFichier($request);
        $nom = Str::uuid().'.xlsx';
        $fichier->move($this->dossier(), $nom);
        $semestres = self::interpreter(Tableur::xlsx($this->dossier().DIRECTORY_SEPARATOR.$nom));
        if (! $semestres) {
            @unlink($this->dossier().DIRECTORY_SEPARATOR.$nom);

            return redirect()->route('import_ue_matieres')->with('erreur', "Aucun semestre reconnu dans ce fichier. Vérifiez qu'il suit le format attendu (colonnes CODE UE / UE / CODE ECUE / ECUE / CREDITS).");
        }
        $request->session()->put(self::SESSION, $nom);

        return view('structure.import_ue_matieres', ['semestres' => $semestres, 'promotions' => $this->promotions($request)]);
    }

    public function confirmer(Request $request, Journal $journal): RedirectResponse
    {
        $nom = (string) $request->session()->get(self::SESSION);
        $chemin = $this->dossier().DIRECTORY_SEPARATOR.basename($nom);
        if ($nom === '' || ! is_file($chemin)) {
            return redirect()->route('import_ue_matieres')->with('erreur', "Le fichier temporaire a expiré, merci de recommencer l'import.");
        }
        $semestres = self::interpreter(Tableur::xlsx($chemin));
        $autorisees = $this->promotions($request)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $choix = (array) $request->input('promotion_semestre', []);
        $remplacer = (array) $request->input('remplacer', []);
        $n = ['ue_creees' => 0, 'ue_maj' => 0, 'matieres' => 0, 'ue_supprimees' => 0, 'ue_protegees' => 0];

        foreach ($semestres as $codeS => $data) {
            $promo = (int) ($choix[$codeS] ?? 0);
            $semestreId = DB::table('semestres')->where('code', $codeS)->value('id');
            if (! $promo || ! in_array($promo, $autorisees, true) || ! $semestreId) {
                continue;
            }
            DB::transaction(function () use ($data, $promo, $semestreId, $remplacer, $codeS, &$n) {
                if (! empty($remplacer[$codeS])) {
                    $codes = array_column($data['ue'], 'code');
                    foreach (DB::table('unites_enseignement')->where('promotion_id', $promo)->where('semestre_id', $semestreId)->whereNotIn('code', $codes)->pluck('id') as $ueId) {
                        // Une UE dont une matière a déjà des notes n'est jamais supprimée
                        if (DB::table('notes as no')->join('matieres as m', 'm.id', '=', 'no.matiere_id')->where('m.ue_id', $ueId)->exists()) {
                            $n['ue_protegees']++;

                            continue;
                        }
                        DB::table('matieres')->where('ue_id', $ueId)->delete();
                        DB::table('unites_enseignement')->where('id', $ueId)->delete();
                        $n['ue_supprimees']++;
                    }
                }
                foreach ($data['ue'] as $ue) {
                    $ueId = DB::table('unites_enseignement')->where('promotion_id', $promo)->where('semestre_id', $semestreId)->where('code', $ue['code'])->value('id');
                    if ($ueId) {
                        DB::table('unites_enseignement')->where('id', $ueId)->update(['libelle' => $ue['libelle'], 'credits_total' => $ue['credits_total'] ?: 0]);
                        $n['ue_maj']++;
                    } else {
                        $ueId = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $semestreId, 'code' => $ue['code'],
                            'libelle' => $ue['libelle'], 'credits_total' => $ue['credits_total'] ?: 0, 'ordre' => $n['ue_creees'] + $n['ue_maj'] + 1]);
                        $n['ue_creees']++;
                    }
                    foreach ($ue['matieres'] as $i => $m) {
                        if (DB::table('matieres')->where('ue_id', $ueId)->where('code', $m['code'])->exists()) {
                            continue; // déjà présente, jamais dupliquée
                        }
                        $max = self::baremeExamen($m['coefficient']);
                        DB::table('matieres')->insert(['ue_id' => $ueId, 'code' => $m['code'], 'libelle' => $m['libelle'], 'note_max_examen' => $max,
                            'note_max_cc' => 0, 'note_max_totale' => $max, 'credits' => $m['credits'], 'coefficient' => $m['coefficient'],
                            'ordre' => $i + 1, 'volume_horaire_prevu' => $m['volume_horaire'], 'actif' => 1]);
                        $n['matieres']++;
                    }
                }
            });
        }
        $this->oublierFichier($request);
        $message = "Import terminé : {$n['ue_creees']} UE créées, {$n['ue_maj']} UE mises à jour, {$n['matieres']} matières créées.";
        $message .= $n['ue_supprimees'] ? " {$n['ue_supprimees']} ancienne(s) UE supprimée(s)." : '';
        $message .= $n['ue_protegees'] ? " ⚠️ {$n['ue_protegees']} UE conservée(s) malgré la demande de remplacement — des notes y étaient déjà saisies." : '';
        $message .= " Barème d'examen calculé selon le coefficient (coef × 20) — à ajuster si besoin dans chaque matière.";
        $journal->action('importer', 'structure', $message);

        return redirect()->route('filieres')->with('succes', $message);
    }

    /** Coef 1 → 20, 2 → 40… (20 si coefficient absent ou nul). */
    public static function baremeExamen(float $coefficient): int
    {
        return (int) round($coefficient) * 20 ?: 20;
    }

    /**
     * Format « ingénierie de formation » : blocs « SEMESTRE n », colonnes
     * B code UE, C UE, D code ECUE, E ECUE, G-I CM/TD/TP, L coefficient,
     * M crédits matière, N crédits UE ; « TOTAL » ferme le semestre.
     */
    public static function interpreter(array $lignes): array
    {
        $semestres = [];
        $semestre = null;
        $ueCourante = null;
        foreach ($lignes as $l) {
            $a = trim((string) ($l[0] ?? ''));
            if (preg_match('/SEME(S)?TRE\s*(\d+)/i', $a, $m)) {
                $semestre = 'S'.$m[2];
                $semestres[$semestre] ??= ['ue' => [], 'libelle_source' => $a];
                $ueCourante = null;

                continue;
            }
            if (strtoupper($a) === 'TOTAL') {
                $semestre = $ueCourante = null;

                continue;
            }
            if ($semestre === null || ($a === '' && trim((string) ($l[1] ?? '')) === '' && trim((string) ($l[6] ?? '')) === 'CM')) {
                continue;
            }
            $codeUe = mb_substr(trim((string) ($l[1] ?? '')), 0, 20);
            $codeEcue = mb_substr(trim((string) ($l[3] ?? '')), 0, 20);
            if ($codeUe !== '') {
                $ueCourante = $codeUe;
                $semestres[$semestre]['ue'][$codeUe] = ['code' => $codeUe, 'libelle' => mb_substr(trim((string) ($l[2] ?? '')), 0, 200),
                    'credits_total' => isset($l[13]) && $l[13] !== '' ? (float) $l[13] : null, 'matieres' => []];
            }
            if ($codeEcue !== '' && $ueCourante !== null) {
                // Volume prévu pour l'enseignant = CM + TD + TP (le TPE, travail personnel, est exclu)
                $volume = (float) ($l[6] ?? 0) + (float) ($l[7] ?? 0) + (float) ($l[8] ?? 0);
                $semestres[$semestre]['ue'][$ueCourante]['matieres'][] = ['code' => $codeEcue, 'libelle' => mb_substr(trim((string) ($l[4] ?? '')), 0, 200),
                    'coefficient' => (float) ($l[11] ?? 1) ?: 1.0, 'credits' => (float) ($l[12] ?? 0), 'volume_horaire' => $volume ?: null];
            }
        }

        return array_filter($semestres, fn ($s) => ! empty($s['ue']));
    }

    private function promotions(Request $request)
    {
        $f = $this->perimetre->faculte($request->user());

        return DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->when($f, fn ($q) => $q->where('f.faculte_id', $f))
            ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('n.libelle')
            ->get(['p.id', 'f.libelle as filiere', 'n.libelle as niveau', 'a.libelle as annee']);
    }

    private function dossier(): string
    {
        $d = storage_path('app/private/imports');
        if (! is_dir($d)) {
            mkdir($d, 0750, true);
        }

        return $d;
    }

    private function oublierFichier(Request $request): void
    {
        $ancien = (string) $request->session()->pull(self::SESSION);
        if ($ancien !== '') {
            @unlink($this->dossier().DIRECTORY_SEPARATOR.basename($ancien));
        }
    }
}
