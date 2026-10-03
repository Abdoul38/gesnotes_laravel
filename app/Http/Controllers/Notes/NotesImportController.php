<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Journal;
use App\Services\Pv;
use App\Services\Resultats;
use App\Support\Classeur;
use App\Support\Tableur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Import des notes — portage de pages/notes_pv/notes_importer.php :
 *  - par matière, à partir du modèle téléchargé (une matière, Examen + TD) ;
 *  - PV complet, à partir du fichier produit par « Exporter Excel » du PV.
 * Les notes déjà acquises (AV/AC) ne sont jamais écrasées. En rattrapage,
 * la meilleure note entre la 1ère session et le rattrapage est gardée, et
 * l'étudiant non validé sans note dans le fichier est noté absent.
 *
 * Droits (repris) : admin, secrétaire, sc_centrale ; sc_entite de la faculté
 * du PV ; enseignant affecté à la matière (import par matière seulement).
 *
 * Différences voulues :
 *  - les résultats sont recalculés après l'import par matière (ils ne
 *    l'étaient qu'après l'import complet : le PV restait faux) ;
 *  - un enseignant ne peut plus importer le PV complet (toutes les matières)
 *    parce qu'il est affecté à une seule ;
 *  - notes au-dessus du maximum refusées (seules les notes négatives l'étaient) ;
 *  - absent au rattrapage : même règle pour les deux imports — note vide,
 *    matière défaillante (l'import complet mettait 0, l'import par matière
 *    une note vide) ;
 *  - import en une transaction ; un PV verrouillé est refusé.
 */
class NotesImportController extends Controller
{
    public function __construct(private readonly Pv $pvs, private readonly Resultats $resultats, private readonly Journal $journal) {}

    public function index(Request $request): View|RedirectResponse
    {
        $pv = $this->pvAutorise($request, (int) $request->query('pv_id'), (int) $request->query('mat_id'));
        if (! $pv) {
            return redirect()->route('notes')->with('erreur', "PV introuvable, verrouillé, ou vous n'êtes pas autorisé à importer des notes pour ce PV.");
        }

        return view('notes.import', [
            'pv' => $pv,
            'matieres' => $this->matieres($pv),
            'nbEtudiants' => $this->pvs->etudiants($pv)->count(),
            'matiereChoisie' => (int) $request->query('mat_id'),
            'importComplet' => $this->peutImporterTout($request->user()),
            'resultat' => session('resultat_import'),
        ]);
    }

    public function modele(Request $request): BinaryFileResponse|RedirectResponse
    {
        $matId = (int) $request->query('mat_id');
        $pv = $this->pvAutorise($request, (int) $request->query('pv_id'), $matId);
        $mat = $pv ? $this->matieres($pv)->firstWhere('id', $matId) : null;
        if (! $mat) {
            return redirect()->route('notes')->with('erreur', 'Matière introuvable.');
        }
        $avecTd = (float) $mat->note_max_cc > 0;
        $entetes = ['N° Etudiant', 'Nom', 'Prénoms', 'Examen/'.(float) $mat->note_max_examen, ...($avecTd ? ['TD/'.(float) $mat->note_max_cc] : [])];
        $lignes = [
            ['height' => 20, 'cells' => [['v' => $mat->libelle.($pv->type_session === 'rattrapage' ? ' — Rattrapage (non validés uniquement)' : ''), 'style' => 1]]],
            ['height' => 18, 'cells' => array_map(fn ($e) => ['v' => $e, 'style' => 5], $entetes)],
        ];
        foreach ($this->pvs->etudiants($pv)->get(['e.numero', 'e.nom', 'e.prenoms']) as $et) {
            $lignes[] = ['cells' => [['v' => (string) $et->numero], ['v' => (string) $et->nom], ['v' => (string) $et->prenoms]]];
        }
        $classeur = new Classeur;
        $classeur->feuille('Notes', $lignes, [14, 22, 24, 14, 14]);

        return response()->download($classeur->enregistrer(), 'template_notes_'.preg_replace('/[^a-z0-9]+/iu', '_', $mat->libelle).'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** Import d'une matière (modèle téléchargé, ou tout CSV : N° étudiant, …, Examen, TD). */
    public function importerMatiere(Request $request): RedirectResponse
    {
        $pvId = (int) $request->input('pv_id');
        $matId = (int) $request->input('mat_id');
        $pv = $this->pvAutorise($request, $pvId, $matId);
        $retour = route('notes_importer', ['pv_id' => $pvId]);
        $mat = $pv ? $this->matieres($pv)->firstWhere('id', $matId) : null;
        if (! $pv || ! $mat) {
            return redirect($retour)->with('erreur', 'Matière introuvable.');
        }
        $fichier = $request->file('fichier');
        $lignes = $fichier && $fichier->isValid() ? $this->lire($fichier->getRealPath(), strtolower($fichier->getClientOriginalExtension())) : [];
        if (! $lignes) {
            return redirect($retour)->with('erreur', 'Fichier vide ou illisible.');
        }

        // Ligne d'en-têtes : la première qui parle d'examen, d'étudiant ou de matricule
        $iEntete = 0;
        foreach ($lignes as $i => $l) {
            $t = mb_strtolower(implode(' ', $l));
            if (str_contains($t, 'exam') || str_contains($t, 'etudiant') || str_contains($t, 'étudiant') || str_contains($t, 'matricule')) {
                $iEntete = $i;
                break;
            }
        }
        $avecTd = (float) $mat->note_max_cc > 0;
        $iExam = $iTd = null;
        foreach ($lignes[$iEntete] as $i => $h) {
            $h = mb_strtolower(trim((string) $h));
            $iExam = str_contains($h, 'exam') ? $i : $iExam;
            $iTd = (str_contains($h, 'td') || str_contains($h, 'cc') || str_contains($h, 'travaux')) ? $i : $iTd;
        }
        $iExam ??= 3;
        $iTd = $avecTd ? ($iTd ?? 4) : null;

        $index = $this->parMatricule($pv);
        $acquises = DB::table('notes')->where(['pv_id' => $pv->id, 'matiere_id' => $matId])->whereIn('observation', ['AV', 'AC'])->pluck('etudiant_id')->flip();
        $saisies = [];
        $resultat = ['ok' => 0, 'ignorees' => 0, 'erreurs' => 0, 'absents' => 0, 'lignes' => [], 'matiere' => $mat->libelle];
        foreach (array_slice($lignes, array_search($iEntete, array_keys($lignes), true) + 1, null, true) as $num => $l) {
            $matricule = trim((string) ($l[0] ?? ''));
            if ($matricule === '') {
                continue;
            }
            $et = $this->trouver($index, $matricule);
            if (! $et) {
                $resultat['lignes'][] = "Ligne $num : étudiant « $matricule » introuvable";
                $resultat['erreurs']++;

                continue;
            }
            if ($acquises->has($et->id)) {
                $resultat['ignorees']++;

                continue;
            }
            [$exam, $td, $erreur] = $this->valeurs($l, $iExam, $iTd, $mat);
            if ($erreur) {
                $resultat['lignes'][] = "Ligne $num ({$et->nom}) : $erreur";
                $resultat['erreurs']++;

                continue;
            }
            if ($exam === null && $td === null) {
                $resultat['ignorees'] += $pv->type_session === 'rattrapage' ? 0 : 1;

                continue;
            }
            $saisies[$et->id][$matId] = [$exam, $td];
        }

        $resultat['ok'] = DB::transaction(function () use ($pv, $saisies, $matId, $request, &$resultat) {
            $nb = $this->enregistrer($pv, $saisies, $request->user()->id);
            $touches = array_keys($saisies);
            if ($pv->type_session === 'rattrapage' && $pv->pv_normale_id) {
                $absents = $this->marquerAbsents($pv, [$matId], $saisies, $request->user()->id);
                $resultat['absents'] = count($absents);
                $touches = array_unique([...$touches, ...$absents]);
            }
            foreach ($touches as $et) {
                $this->resultats->recalculer($pv->id, (int) $et);
            }

            return $nb;
        });
        $resultat['total'] = $this->pvs->etudiants($pv)->count();
        $this->journal->action('importer', 'notes', "Import des notes de « {$mat->libelle} » ({$resultat['ok']}) — {$pv->libelle}", $pv->id);

        return redirect($retour)->with('resultat_import', $resultat);
    }

    /** Import du PV complet (fichier de « Exporter Excel » : Exam./TD par matière, matricule en 2e colonne). */
    public function importerPv(Request $request): RedirectResponse
    {
        $pvId = (int) $request->input('pv_id');
        $pv = $this->pvAutorise($request, $pvId, 0);
        if (! $pv || ! $this->peutImporterTout($request->user())) {
            return redirect()->route('notes_importer', ['pv_id' => $pvId])->with('erreur', "Vous n'êtes pas autorisé à importer le PV complet.");
        }
        $fichier = $request->file('fichier_pv');
        $lignes = $fichier && $fichier->isValid() ? $this->lire($fichier->getRealPath(), strtolower($fichier->getClientOriginalExtension())) : [];
        if (! $lignes) {
            return redirect()->route('notes_importer', ['pv_id' => $pvId])->with('erreur', 'Impossible de lire le fichier.');
        }
        $matieres = $this->matieres($pv)->values();

        // Colonnes : la ligne des sous-titres (« Exam./40 », « TD/20 », …, « Obs. » termine une matière)
        $colonnes = [];
        $iEntete = null;
        foreach ($lignes as $i => $l) {
            if (collect($l)->contains(fn ($v) => preg_match('/^Exam\./i', trim((string) $v)))) {
                $iEntete = $i;
                $k = 0;
                foreach ($l as $ci => $v) {
                    $v = trim((string) $v);
                    if (preg_match('/^Exam\./i', $v) && isset($matieres[$k])) {
                        $colonnes[$ci] = [$matieres[$k]->id, 'exam'];
                    } elseif (preg_match('/^TD\//i', $v) && isset($matieres[$k])) {
                        $colonnes[$ci] = [$matieres[$k]->id, 'td'];
                    } elseif (preg_match('/^Obs\./i', $v)) {
                        $k++;
                    }
                }
                break;
            }
        }
        if ($iEntete === null) {
            return redirect()->route('notes_importer', ['pv_id' => $pvId])->with('erreur', "Format non reconnu : utilisez le fichier produit par « Exporter Excel » du PV.");
        }

        $index = $this->parMatricule($pv);
        $parId = $matieres->keyBy('id');
        $saisies = [];
        $erreurs = [];
        foreach (array_slice($lignes, array_search($iEntete, array_keys($lignes), true) + 1, null, true) as $num => $l) {
            $matricule = trim((string) ($l[1] ?? ''));
            if ($matricule === '') {
                continue;
            }
            $et = $this->trouver($index, $matricule);
            if (! $et) {
                $erreurs[] = "Matricule introuvable : $matricule";

                continue;
            }
            $parMatiere = [];
            foreach ($colonnes as $ci => [$mid, $type]) {
                $parMatiere[$mid][$type] = $l[$ci] ?? '';
            }
            foreach ($parMatiere as $mid => $v) {
                $mat = $parId[$mid];
                [$exam, $td, $erreur] = $this->valeurs([$v['exam'] ?? '', $v['td'] ?? ''], 0, (float) $mat->note_max_cc > 0 ? 1 : null, $mat);
                if ($erreur) {
                    $erreurs[] = "Ligne $num ({$et->nom}, {$mat->libelle}) : $erreur";
                } elseif ($exam !== null || $td !== null) {
                    $saisies[$et->id][$mid] = [$exam, $td];
                }
            }
        }
        if ($erreurs && collect($erreurs)->contains(fn ($e) => str_contains($e, 'hors de'))) {
            return redirect()->route('notes_importer', ['pv_id' => $pvId])->with('erreur', 'Rien n\'a été importé : '.implode(' ', array_slice($erreurs, 0, 5)));
        }

        $acquises = DB::table('notes')->where('pv_id', $pv->id)->whereIn('observation', ['AV', 'AC'])->get(['etudiant_id', 'matiere_id'])
            ->map(fn ($n) => $n->etudiant_id.'-'.$n->matiere_id)->flip();
        foreach ($saisies as $et => $mats) {
            foreach (array_keys($mats) as $mid) {
                if ($acquises->has($et.'-'.$mid)) {
                    unset($saisies[$et][$mid]);
                }
            }
        }
        $saisies = array_filter($saisies);

        DB::transaction(function () use ($pv, $saisies, $matieres, $request) {
            $this->enregistrer($pv, $saisies, $request->user()->id);
            $touches = array_keys($saisies);
            if ($pv->type_session === 'rattrapage' && $pv->pv_normale_id) {
                $touches = array_unique([...$touches, ...$this->marquerAbsents($pv, $matieres->pluck('id')->all(), $saisies, $request->user()->id)]);
            }
            foreach ($touches as $et) {
                $this->resultats->recalculer($pv->id, (int) $et);
            }
        });
        $nb = count($saisies);
        $this->journal->action('importer', 'notes', "Import du PV complet ($nb étudiant(s)) — {$pv->libelle}", $pv->id);
        if (! $nb) {
            return redirect()->route('pv_detail', ['id' => $pv->id])->with('erreur', 'Aucune note importée. Vérifiez le format du fichier.');
        }

        return redirect()->route('pv_detail', ['id' => $pv->id])->with('succes', "✅ Import PV complet réussi — $nb étudiant(s) mis à jour.".($erreurs ? ' ('.count($erreurs).' ligne(s) non reconnue(s))' : ''));
    }

    /**
     * Enregistre les notes [étudiant][matière] = [examen, TD]. En rattrapage,
     * la meilleure note entre 1ère session et rattrapage est gardée.
     */
    private function enregistrer(object $pv, array $saisies, int $utilisateur): int
    {
        $matieres = $this->matieres($pv)->keyBy('id');
        $normale = ($pv->type_session === 'rattrapage' && $pv->pv_normale_id)
            ? DB::table('notes')->where('pv_id', $pv->pv_normale_id)->get()->groupBy('etudiant_id')->map->keyBy('matiere_id') : collect();
        $lignes = [];
        foreach ($saisies as $et => $mats) {
            foreach ($mats as $mid => [$exam, $td]) {
                $avecTd = (float) $matieres[$mid]->note_max_cc > 0;
                // Note finale seulement si l'examen ET le TD prévu sont saisis
                $finale = ($exam !== null && (! $avecTd || $td !== null)) ? $exam + ($td ?? 0) : null;
                $n1 = $normale[$et][$mid] ?? null;
                if ($n1 && $n1->note_finale !== null) {
                    $garderSession1 = $finale === null ? (float) $n1->note_finale != 0.0 : (float) $n1->note_finale > $finale;
                    if ($garderSession1) {
                        [$exam, $td, $finale] = [$n1->note_examen, $n1->note_cc, (float) $n1->note_finale];
                    }
                }
                $lignes[] = ['pv_id' => $pv->id, 'etudiant_id' => $et, 'matiere_id' => $mid, 'note_examen' => $exam, 'note_cc' => $td,
                    'note_finale' => $finale, 'defaillant' => 0, 'saisie_par' => $utilisateur, 'updated_at' => now()];
            }
        }
        foreach (array_chunk($lignes, 500) as $paquet) {
            DB::table('notes')->upsert($paquet, ['pv_id', 'etudiant_id', 'matiere_id'], ['note_examen', 'note_cc', 'note_finale', 'updated_at']);
        }

        return count($lignes);
    }

    /**
     * Rattrapage : un étudiant du PV sans note dans le fichier pour une
     * matière qu'il n'avait pas acquise en 1ère session est absent — note
     * vide, matière défaillante. Retourne les étudiants concernés.
     */
    private function marquerAbsents(object $pv, array $matieres, array $saisies, int $utilisateur): array
    {
        $acquisesS1 = DB::table('notes')->where('pv_id', $pv->pv_normale_id)->whereIn('observation', ['AV', 'AC'])
            ->get(['etudiant_id', 'matiere_id'])->map(fn ($n) => $n->etudiant_id.'-'.$n->matiere_id)->flip();
        $absents = [];
        foreach ($this->pvs->etudiants($pv)->pluck('e.id') as $et) {
            foreach ($matieres as $mid) {
                if (isset($saisies[$et][$mid]) || $acquisesS1->has($et.'-'.$mid)) {
                    continue;
                }
                DB::table('notes')->upsert([['pv_id' => $pv->id, 'etudiant_id' => $et, 'matiere_id' => $mid, 'note_examen' => null, 'note_cc' => null,
                    'note_finale' => null, 'defaillant' => 1, 'saisie_par' => $utilisateur]], ['pv_id', 'etudiant_id', 'matiere_id'],
                    ['note_examen', 'note_cc', 'note_finale', 'defaillant']);
                $absents[$et] = true;
            }
        }

        return array_keys($absents);
    }

    /** @return array{0: ?float, 1: ?float, 2: ?string} examen, TD, erreur */
    private function valeurs(array $ligne, int $iExam, ?int $iTd, object $mat): array
    {
        $lire = function (?int $i, float $max, string $nom) use ($ligne) {
            if ($i === null) {
                return [null, null];
            }
            $v = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], trim((string) ($ligne[$i] ?? '')));
            if ($v === '' || $v === '—' || $v === '-' || ! is_numeric($v)) {
                return [null, null];
            }
            if ((float) $v < 0 || (float) $v > $max) {
                return [null, "note $nom $v hors de 0 à $max"];
            }

            return [round((float) $v, 2), null];
        };
        [$exam, $e1] = $lire($iExam, (float) $mat->note_max_examen, 'examen');
        [$td, $e2] = $lire($iTd, (float) $mat->note_max_cc, 'TD');

        return [$exam, $td, $e1 ?? $e2];
    }

    /** @return array<int, list<string>> */
    private function lire(string $chemin, string $extension): array
    {
        if ($extension === 'xlsx') {
            return Tableur::xlsx($chemin);
        }
        $texte = Tableur::texteCsv($chemin);
        $lignes = array_values(array_filter(explode("\n", $texte), fn ($l) => trim($l) !== ''));
        $echantillon = implode("\n", array_slice($lignes, 0, 5));
        $sep = substr_count($echantillon, "\t") > max(substr_count($echantillon, ';'), substr_count($echantillon, ',')) ? "\t"
            : (substr_count($echantillon, ',') > substr_count($echantillon, ';') ? ',' : ';');
        $resultat = [];
        foreach ($lignes as $i => $l) {
            // Séparateur autre que la virgule : « 12,5 » est une décimale
            $resultat[$i + 1] = str_getcsv($sep !== ',' ? preg_replace('/(\d),(\d)/', '$1.$2', $l) : $l, $sep, '"', '');
        }

        return $resultat;
    }

    /** Étudiants du PV indexés par matricule. */
    private function parMatricule(object $pv): array
    {
        return $this->pvs->etudiants($pv)->get(['e.id', 'e.numero', 'e.nom'])->keyBy(fn ($e) => (string) $e->numero)->all();
    }

    private function trouver(array $index, string $matricule): ?object
    {
        return $index[$matricule] ?? $index[ltrim($matricule, '0')] ?? $index[str_pad(ltrim($matricule, '0'), 5, '0', STR_PAD_LEFT)] ?? null;
    }

    private function matieres(object $pv)
    {
        return DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
            ->where(['ue.promotion_id' => $pv->promotion_id, 'ue.semestre_id' => $pv->semestre_id, 'm.actif' => 1])
            ->orderBy('ue.ordre')->orderBy('m.ordre')
            ->get(['m.id', 'm.code', 'm.libelle', 'm.note_max_examen', 'm.note_max_cc', 'm.note_max_totale', 'ue.code as ue_code', 'ue.libelle as ue_libelle']);
    }

    private function peutImporterTout(Utilisateur $u): bool
    {
        return in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite'], true);
    }

    /** PV non verrouillé que l'utilisateur peut alimenter (pour la matière donnée, s'il est enseignant). */
    private function pvAutorise(Request $request, int $pvId, int $matId): ?object
    {
        $u = $request->user();
        $pv = $pvId ? $this->pvs->trouver($pvId) : null;
        if (! $pv || $pv->verrouille) {
            return null;
        }
        $ok = match ($u->role) {
            'admin', 'secretaire', 'sc_centrale' => true,
            'sc_entite' => $u->faculteId() > 0 && (int) $pv->faculte_id === $u->faculteId(),
            'enseignant' => $u->enseignant_id && DB::table('affectations as af')->join('matieres as m', 'm.id', '=', 'af.matiere_id')
                ->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
                ->where(['ue.promotion_id' => $pv->promotion_id, 'ue.semestre_id' => $pv->semestre_id, 'af.enseignant_id' => $u->enseignant_id])
                ->when($matId, fn ($q) => $q->where('af.matiere_id', $matId))->exists(),
            default => false,
        };

        return $ok ? $pv : null;
    }
}
