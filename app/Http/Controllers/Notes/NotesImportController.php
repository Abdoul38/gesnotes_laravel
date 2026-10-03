<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Journal;
use App\Services\Pv;
use App\Services\Resultats;
use App\Services\SaisieNotes;
use App\Support\Classeur;
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
    public function __construct(private readonly Pv $pvs, private readonly Resultats $resultats, private readonly Journal $journal, private readonly SaisieNotes $saisie) {}

    public function index(Request $request): View|RedirectResponse
    {
        $pv = $this->pvAutorise($request, (int) $request->query('pv_id'), (int) $request->query('mat_id'));
        if (! $pv) {
            return redirect()->route('notes')->with('erreur', "PV introuvable, verrouillé, ou vous n'êtes pas autorisé à importer des notes pour ce PV.");
        }

        return view('notes.import', [
            'pv' => $pv,
            'matieres' => $this->saisie->matieres($pv),
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
        $mat = $pv ? $this->saisie->matieres($pv)->firstWhere('id', $matId) : null;
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
        $mat = $pv ? $this->saisie->matieres($pv)->firstWhere('id', $matId) : null;
        if (! $pv || ! $mat) {
            return redirect($retour)->with('erreur', 'Matière introuvable.');
        }
        $fichier = $request->file('fichier');
        $lignes = $fichier && $fichier->isValid() ? $this->saisie->lire($fichier->getRealPath(), strtolower($fichier->getClientOriginalExtension())) : [];
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

        $index = $this->saisie->parMatricule($pv);
        $acquises = DB::table('notes')->where(['pv_id' => $pv->id, 'matiere_id' => $matId])->whereIn('observation', ['AV', 'AC'])->pluck('etudiant_id')->flip();
        $saisies = [];
        $resultat = ['ok' => 0, 'ignorees' => 0, 'erreurs' => 0, 'absents' => 0, 'lignes' => [], 'matiere' => $mat->libelle];
        foreach (array_slice($lignes, array_search($iEntete, array_keys($lignes), true) + 1, null, true) as $num => $l) {
            $matricule = trim((string) ($l[0] ?? ''));
            if ($matricule === '') {
                continue;
            }
            $et = $this->saisie->trouver($index, $matricule);
            if (! $et) {
                $resultat['lignes'][] = "Ligne $num : étudiant « $matricule » introuvable";
                $resultat['erreurs']++;

                continue;
            }
            if ($acquises->has($et->id)) {
                $resultat['ignorees']++;

                continue;
            }
            [$exam, $td, $erreur] = $this->saisie->valeurs($l, $iExam, $iTd, $mat);
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
            $nb = $this->saisie->enregistrer($pv, $saisies, $request->user()->id);
            $touches = array_keys($saisies);
            if ($pv->type_session === 'rattrapage' && $pv->pv_normale_id) {
                $absents = $this->saisie->marquerAbsents($pv, [$matId], $saisies, $request->user()->id);
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
        $lignes = $fichier && $fichier->isValid() ? $this->saisie->lire($fichier->getRealPath(), strtolower($fichier->getClientOriginalExtension())) : [];
        if (! $lignes) {
            return redirect()->route('notes_importer', ['pv_id' => $pvId])->with('erreur', 'Impossible de lire le fichier.');
        }
        $matieres = $this->saisie->matieres($pv)->values();

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

        $index = $this->saisie->parMatricule($pv);
        $parId = $matieres->keyBy('id');
        $saisies = [];
        $erreurs = [];
        foreach (array_slice($lignes, array_search($iEntete, array_keys($lignes), true) + 1, null, true) as $num => $l) {
            $matricule = trim((string) ($l[1] ?? ''));
            if ($matricule === '') {
                continue;
            }
            $et = $this->saisie->trouver($index, $matricule);
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
                [$exam, $td, $erreur] = $this->saisie->valeurs([$v['exam'] ?? '', $v['td'] ?? ''], 0, (float) $mat->note_max_cc > 0 ? 1 : null, $mat);
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
            $this->saisie->enregistrer($pv, $saisies, $request->user()->id);
            $touches = array_keys($saisies);
            if ($pv->type_session === 'rattrapage' && $pv->pv_normale_id) {
                $touches = array_unique([...$touches, ...$this->saisie->marquerAbsents($pv, $matieres->pluck('id')->all(), $saisies, $request->user()->id)]);
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
