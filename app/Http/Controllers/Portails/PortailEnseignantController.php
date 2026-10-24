<?php

namespace App\Http\Controllers\Portails;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\HistoriqueNotes;
use App\Services\Journal;
use App\Services\NotesEnseignants;
use App\Services\Pv;
use App\Services\Resultats;
use App\Services\SaisieNotes;
use App\Support\Classeur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Portail enseignant — portage de pages/enseignants/portail_enseignant.php :
 * matières affectées, saisie et import des notes (examen ou TD selon
 * l'affectation), finalisation, heures effectuées, volume horaire prévu.
 *
 * Modes (comme avant) : l'enseignant pour ses affectations ; admin et
 * secrétaire sans fiche enseignant pour toutes ; chef de département (son
 * département), doyen et sc_entite (leur faculté) en lecture seule, avec
 * finalisation et volume horaire.
 *
 * Différences voulues :
 *  - la matière et le PV affichés, le modèle Excel et les enregistrements
 *    doivent figurer dans les affectations visibles : n'importe quel
 *    pv_id/mat_id affichait avant les notes et la liste des étudiants
 *    d'une autre faculté ;
 *  - le PV doit correspondre à la matière (même promotion, même semestre)
 *    et ne pas être verrouillé — l'import ne vérifiait pas le verrou ;
 *  - seuls les étudiants du PV sont enregistrés (n'importe quel id était
 *    accepté) ; notes contrôlées de 0 au maximum de la matière (l'import
 *    n'écartait que les négatives, la saisie rien) : en cas d'erreur rien
 *    n'est enregistré ;
 *  - les résultats des étudiants touchés sont recalculés, comme à l'import
 *    de la scolarité ; en rattrapage, la meilleure NOTE FINALE des deux
 *    sessions est gardée (même règle que l'import, l'ancien portail
 *    comparait les seules notes d'examen) ;
 *  - un absent au rattrapage n'efface plus la composante saisie par
 *    l'autre enseignant (cours/TD) ;
 *  - pas de finalisation automatique : l'enseignant enregistre ses notes,
 *    qui n'apparaissent pas dans le PV global, puis les finalise lui-même
 *    (voir App\Services\NotesEnseignants) — par PV et par composante, les
 *    enseignants de cours et de TD finalisant chacun la leur ; la saisie du
 *    mode administration va directement dans le PV, comme avant ;
 *  - le volume horaire prévu n'est modifiable que dans le périmètre du
 *    chef de département ou du doyen (toute matière avant).
 */
class PortailEnseignantController extends Controller
{
    private const SESSIONS = ['normale', 'rattrapage'];

    private const MESSAGE_ATTENTE = "Notes enregistrées. Elles n'apparaîtront dans le PV qu'après leur finalisation.";

    public function __construct(
        private readonly Pv $pvs,
        private readonly SaisieNotes $saisie,
        private readonly Resultats $resultats,
        private readonly Journal $journal,
        private readonly NotesEnseignants $notesEns,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $u = $request->user();
        $mode = $this->mode($u);
        if ($mode instanceof RedirectResponse) {
            return $mode;
        }
        if (! $this->notesEns->disponible()) {
            return redirect()->route('accueil')->with('erreur', 'Base de données à mettre à jour (php artisan gesnotes:completer-schema --appliquer).');
        }
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeId = $this->annee($request, $annees);
        $affectations = $this->etats($this->affectations($u, $mode, $anneeId)->get(), $mode);
        $choix = $this->selection($u, $mode, $anneeId, (int) $request->query('pv_id'), (int) $request->query('mat_id'));
        $etudiants = collect();
        $onglets = [];
        $tc = null;
        $finalisees = [];
        $enAttente = 0;
        if ($choix) {
            $onglets = $this->onglets($choix['type_charge'], $choix['mat']);
            $tc = isset($onglets[$request->query('tc')]) ? $request->query('tc') : array_key_first($onglets);
            $finalisees = $this->notesEns->finalisees($choix['pv'], $choix['mat']);
            $etudiants = $this->etudiants($choix['pv'], $choix['mat']->id)
                ->leftJoin('notes as n2', fn ($j) => $j->on('n2.etudiant_id', '=', 'e.id')->where('n2.pv_id', $choix['pv']->id)->where('n2.matiere_id', $choix['mat']->id))
                ->select(['e.id', 'e.nom', 'e.prenoms', 'e.numero', 'n2.note_examen', 'n2.note_cc', 'n2.note_finale', 'n2.defaillant'])->get();
            if ($mode !== 'admin') {
                // Notes enregistrées pas encore finalisées : affichées à la place de celles du PV
                foreach (array_keys($onglets) as $c) {
                    $brouillons = $this->notesEns->brouillons((int) $choix['pv']->id, (int) $choix['mat']->id, $c);
                    $enAttente += $c === $tc ? $brouillons->count() : 0;
                    foreach ($etudiants as $et) {
                        if ($brouillons->has($et->id)) {
                            $et->{$c === 'cc' ? 'note_cc' : 'note_examen'} = $brouillons[$et->id];
                            $et->defaillant = $brouillons[$et->id] === null ? 1 : 0;
                        }
                    }
                }
            }
        }
        if ($mode === 'enseignant' && $request->isMethod('get') && ! $request->query('pv_id')) {
            $this->journal->action('consulter', 'enseignant', 'Consultation portail enseignant', (int) $u->enseignant_id);
        }

        return view('portails.enseignant', [
            'mode' => $mode,
            'enseignant' => $u->enseignant_id ? DB::table('enseignants')->find($u->enseignant_id) : null,
            'perimetre' => match ($mode) {
                'chef' => DB::table('departements')->where('id', $u->departementId())->value('libelle') ?? 'Département non défini',
                'faculte' => (DB::table('facultes')->where('id', $u->faculteId())->value('libelle') ?? 'Faculté non définie').' (toute la faculté)',
                default => 'Accès toutes matières',
            },
            'annees' => $annees,
            'anneeId' => $anneeId,
            'affectations' => $affectations,
            'peutFinaliser' => $affectations->pluck('mat_id')->unique()->mapWithKeys(fn ($m) => [$m => $this->peutFinaliser($u, $mode, (int) $m)]),
            'choix' => $choix,
            'onglets' => $onglets,
            'tc' => $tc,
            'etudiants' => $etudiants,
            'peutSaisir' => in_array($mode, ['enseignant', 'admin'], true),
            'lectureSeule' => $choix && ($choix['pv']->verrouille || ($mode !== 'admin' && in_array($tc, $finalisees, true))),
            'finalisees' => $finalisees,
            'enAttente' => $enAttente,
        ]);
    }

    public function saisir(Request $request): RedirectResponse
    {
        $cible = $this->cibleSaisie($request);
        if ($cible instanceof RedirectResponse) {
            return $cible;
        }
        ['pv' => $pv, 'mat' => $mat, 'tc' => $tc, 'retour' => $retour, 'mode' => $mode] = $cible;
        $max = (float) ($tc === 'cc' ? $mat->note_max_cc : $mat->note_max_examen);
        $liste = $this->etudiants($pv, $mat->id)->pluck('e.nom', 'e.id');
        $notes = (array) $request->input('notes', []);
        $valeurs = [];
        $erreurs = [];
        foreach ($notes as $et => $brut) {
            if (! $liste->has((int) $et) || is_array($brut)) {
                continue;
            }
            $v = $this->saisie->note($brut, $max);
            if ($v === false) {
                $erreurs[] = "{$liste[(int) $et]} : « ".mb_substr((string) $brut, 0, 10)." » n'est pas une note de 0 à $max";
            } elseif ($v !== null || $pv->type_session === 'rattrapage') {
                // Case vide : inchangée en session normale, absent au rattrapage
                $valeurs[(int) $et] = $v;
            }
        }
        if ($erreurs) {
            return redirect($retour)->withInput()->with('erreur', 'Rien n\'a été enregistré — '.implode(' ; ', array_slice($erreurs, 0, 5)).(count($erreurs) > 5 ? ' …' : ''));
        }
        $this->enregistrer($request->user(), $mode, $pv, $mat, $tc, $valeurs);
        $this->journal->action('modifier', 'notes', 'Saisie de notes ('.count($valeurs)." étudiant(s), {$this->libelleTc($tc)}) — {$mat->libelle}, {$pv->libelle}", (int) $mat->id);

        return redirect($retour)->with('succes', $mode === 'admin' ? 'Notes enregistrées avec succès.' : self::MESSAGE_ATTENTE);
    }

    public function importer(Request $request): RedirectResponse
    {
        $cible = $this->cibleSaisie($request);
        if ($cible instanceof RedirectResponse) {
            return $cible;
        }
        ['pv' => $pv, 'mat' => $mat, 'tc' => $tc, 'retour' => $retour, 'mode' => $mode] = $cible;
        $fichier = $request->file('fichier_import');
        $ext = $fichier ? strtolower($fichier->getClientOriginalExtension()) : '';
        $lignes = $fichier && $fichier->isValid() && in_array($ext, ['xlsx', 'csv', 'txt'], true) ? $this->saisie->lire($fichier->getRealPath(), $ext) : [];
        if (! $lignes) {
            return redirect($retour)->with('erreur', 'Fichier vide ou illisible (formats acceptés : .xlsx, .csv).');
        }

        // En-tête : « N° Etudiant »/« Matricule » en 1ère colonne, ou une ligne « Nom » + « Examen »/« Note » (5 premières lignes)
        $cles = array_keys($lignes);
        $iEntete = $cles[0];
        foreach (array_slice($cles, 0, 5) as $i) {
            $premiere = mb_strtolower(trim((string) ($lignes[$i][0] ?? '')));
            $texte = mb_strtolower(implode(' ', $lignes[$i]));
            if (str_contains($premiere, 'etudiant') || str_contains($premiere, 'étudiant') || str_contains($premiere, 'matricule') || in_array($premiere, ['n°', 'no'], true)
                || (str_contains($texte, 'nom') && (str_contains($texte, 'exam') || str_contains($texte, 'note')))) {
                $iEntete = $i;
                break;
            }
        }
        $iNote = 3;
        foreach ($lignes[$iEntete] as $i => $h) {
            $h = mb_strtolower(trim((string) $h));
            $iNote = (str_contains($h, 'exam') || str_contains($h, 'note')) ? $i : $iNote;
        }

        $max = (float) ($tc === 'cc' ? $mat->note_max_cc : $mat->note_max_examen);
        $index = $this->etudiants($pv, $mat->id)->get(['e.id', 'e.numero', 'e.nom'])->keyBy(fn ($e) => (string) $e->numero)->all();
        $valeurs = [];
        $presents = [];
        $erreurs = [];
        $inconnus = 0;
        foreach (array_slice($lignes, array_search($iEntete, $cles, true) + 1, null, true) as $num => $l) {
            $matricule = trim((string) ($l[0] ?? ''));
            if ($matricule === '') {
                continue;
            }
            $et = $this->saisie->trouver($index, $matricule);
            if (! $et) {
                $inconnus++;

                continue;
            }
            $presents[$et->id] = true;
            $v = $this->saisie->note($l[$iNote] ?? '', $max);
            if ($v === false) {
                $erreurs[] = "Ligne $num ({$et->nom}) : « ".mb_substr((string) ($l[$iNote] ?? ''), 0, 10)." » n'est pas une note de 0 à $max";
            } elseif ($v !== null) {
                $valeurs[(int) $et->id] = $v;
            }
        }
        if ($erreurs) {
            return redirect($retour)->with('erreur', 'Rien n\'a été importé — '.implode(' ; ', array_slice($erreurs, 0, 5)).(count($erreurs) > 5 ? ' …' : ''));
        }
        $nb = count($valeurs);
        if ($pv->type_session === 'rattrapage') {
            // Étudiants du rattrapage absents du fichier : n'ont pas composé
            foreach ($index as $et) {
                if (! isset($presents[$et->id])) {
                    $valeurs[(int) $et->id] = null;
                }
            }
        }
        $this->enregistrer($request->user(), $mode, $pv, $mat, $tc, $valeurs);
        $this->journal->action('importer', 'notes', "Import de notes ($nb, {$this->libelleTc($tc)}) — {$mat->libelle}, {$pv->libelle}", (int) $mat->id);

        return redirect($retour)->with('succes', "✅ Import réussi — $nb note(s) importée(s)".($inconnus ? " · $inconnus matricule(s) introuvable(s)" : '').'.'
            .($mode === 'admin' ? '' : ' '.self::MESSAGE_ATTENTE));
    }

    public function modele(Request $request): BinaryFileResponse|RedirectResponse
    {
        $u = $request->user();
        $mode = $this->mode($u);
        if ($mode instanceof RedirectResponse) {
            return $mode;
        }
        $choix = $this->selection($u, $mode, (int) $request->query('annee_id'), (int) $request->query('pv_id'), (int) $request->query('mat_id'));
        $onglets = $choix ? $this->onglets($choix['type_charge'], $choix['mat']) : [];
        $tc = (string) $request->query('tc', 'exam');
        if (! $choix || ! isset($onglets[$tc])) {
            return redirect()->route('portail_enseignant')->with('erreur', 'Matière introuvable dans vos affectations.');
        }
        ['pv' => $pv, 'mat' => $mat] = $choix;
        $max = (float) ($tc === 'cc' ? $mat->note_max_cc : $mat->note_max_examen);
        $lignes = [
            ['height' => 20, 'cells' => [['v' => ($pv->type_session === 'rattrapage' ? 'Rattrapage — ' : '').$mat->libelle.' — '.$this->libelleTc($tc), 'style' => 1]]],
            ['height' => 18, 'cells' => array_map(fn ($e) => ['v' => $e, 'style' => 5], ['N° Etudiant', 'Nom', 'Prénoms', 'Note/'.$max])],
        ];
        foreach ($this->etudiants($pv, $mat->id)->get(['e.numero', 'e.nom', 'e.prenoms']) as $et) {
            $lignes[] = ['cells' => [['v' => (string) $et->numero], ['v' => (string) $et->nom], ['v' => (string) $et->prenoms]]];
        }
        $classeur = new Classeur;
        $classeur->feuille('Notes', $lignes, [14, 22, 24, 14]);

        return response()->download($classeur->enregistrer(), 'notes_'.preg_replace('/[^a-z0-9]+/i', '_', $mat->libelle).'_'.($tc === 'cc' ? 'CC' : 'Exam').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Finaliser (tous) ou rouvrir (admin/secrétaire, chef de département,
     * doyen) les notes d'une matière dans un PV : l'enseignant finalise les
     * composantes de son affectation, les autres toute la matière.
     */
    public function finalisation(Request $request): RedirectResponse
    {
        $u = $request->user();
        $mode = $this->mode($u);
        if ($mode instanceof RedirectResponse) {
            return $mode;
        }
        $anneeId = (int) $request->input('annee_id');
        $retour = redirect()->route('portail_enseignant', ['annee_id' => $anneeId, 'pv_id' => (int) $request->input('pv_id'), 'mat_id' => (int) $request->input('mat_id')]);
        $choix = $this->selection($u, $mode, $anneeId, (int) $request->input('pv_id'), (int) $request->input('mat_id'));
        if (! $choix || ! $this->peutFinaliser($u, $mode, (int) $choix['mat']->id)) {
            return $retour->with('erreur', "Vous n'êtes pas autorisé à modifier le statut de cette matière.");
        }
        ['pv' => $pv, 'mat' => $mat] = $choix;
        if ($pv->verrouille) {
            return $retour->with('erreur', 'Ce PV est verrouillé.');
        }
        $composantes = $mode === 'enseignant' ? array_keys($this->onglets($choix['type_charge'], $mat)) : NotesEnseignants::composantes($mat);
        if (! $composantes) {
            return $retour->with('erreur', 'Aucune note à finaliser pour votre affectation.');
        }
        $faites = array_intersect($composantes, $this->notesEns->finalisees($pv, $mat));
        // « rouvrir » demandé, ou rien à finaliser : réouverture des composantes finalisées
        $rouvrir = $request->input('action') === 'rouvrir' || count($faites) === count($composantes);
        // L'enseignant verrouille ses notes, mais ne peut pas se déverrouiller lui-même
        if ($rouvrir && $mode === 'enseignant') {
            return $retour->with('erreur', "Seuls l'administration, le chef de département ou le doyen peuvent rouvrir des notes déjà finalisées.");
        }
        if ($rouvrir && $faites) {
            $this->notesEns->rouvrir($pv, $mat, array_values($faites));
            $this->journal->action('modifier', 'matiere', "Réouverture des notes « {$mat->libelle} » — {$pv->libelle}", (int) $mat->id);

            return $retour->with('succes', "Notes de « {$mat->libelle} » rouvertes : l'enseignant peut de nouveau les saisir.");
        }
        if ($rouvrir) {
            return $retour->with('erreur', 'Ces notes ne sont pas finalisées.');
        }
        $nb = $this->notesEns->finaliser($pv, $mat, $composantes, (int) $u->id);
        $this->journal->action('modifier', 'matiere', "Finalisation des notes « {$mat->libelle} » ($nb note(s) publiée(s) dans le PV) — {$pv->libelle}", (int) $mat->id);

        return $retour->with('succes', "Notes de « {$mat->libelle} » finalisées : elles figurent maintenant dans le PV ($nb note(s)).");
    }

    /** Heures de cours réellement effectuées (même périmètre que la finalisation). */
    public function heures(Request $request): RedirectResponse
    {
        $u = $request->user();
        $mode = $this->mode($u);
        if ($mode instanceof RedirectResponse) {
            return $mode;
        }
        $retour = redirect()->route('portail_enseignant', ['annee_id' => (int) $request->input('annee_id')]);
        $mat = DB::table('matieres')->find((int) $request->input('mat_id'));
        $brut = str_replace(',', '.', trim((string) $request->input('heures_faites')));
        if (! $mat || ! $this->peutFinaliser($u, $mode, (int) $mat->id)) {
            return $retour->with('erreur', "Vous n'êtes pas autorisé à déclarer les heures de cette matière.");
        }
        if (! is_numeric($brut) || (float) $brut < 0 || (float) $brut > 9999) {
            return $retour->with('erreur', "Le nombre d'heures doit être un nombre positif.");
        }
        $h = round((float) $brut, 1);
        if ($mat->volume_horaire_prevu !== null && $h > (float) $mat->volume_horaire_prevu) {
            return $retour->with('erreur', "Le nombre d'heures déclaré ({$h}h) dépasse le volume horaire prévu (".(float) $mat->volume_horaire_prevu.'h).');
        }
        DB::table('matieres')->where('id', $mat->id)->update(['heures_faites' => $h, 'heures_maj_par' => $u->id, 'heures_maj_le' => now()]);
        $this->journal->action('modifier', 'matiere', "Heures effectuées mises à jour ({$h}h) — « {$mat->libelle} »", (int) $mat->id);

        return $retour->with('succes', "Heures effectuées enregistrées : {$h}h.");
    }

    /** Volume horaire prévu (programme officiel) : administration, chef de département, doyen — dans leur périmètre. */
    public function volume(Request $request): RedirectResponse
    {
        $u = $request->user();
        $mode = $this->mode($u);
        if ($mode instanceof RedirectResponse) {
            return $mode;
        }
        $retour = redirect()->route('portail_enseignant', ['annee_id' => (int) $request->input('annee_id')]);
        $mat = DB::table('matieres')->find((int) $request->input('mat_id'));
        $brut = str_replace(',', '.', trim((string) $request->input('volume_horaire_prevu')));
        if ($mode === 'enseignant' || ! $mat || ! $this->peutFinaliser($u, $mode, (int) $mat->id)) {
            return $retour->with('erreur', "Seuls l'administration, le chef de département ou le doyen peuvent fixer le volume horaire prévu de leurs matières.");
        }
        if (! is_numeric($brut) || (float) $brut <= 0 || (float) $brut > 9999) {
            return $retour->with('erreur', 'Le volume horaire prévu doit être supérieur à 0.');
        }
        $v = round((float) $brut, 1);
        DB::table('matieres')->where('id', $mat->id)->update(['volume_horaire_prevu' => $v]);
        $this->journal->action('modifier', 'matiere', "Volume horaire prévu fixé à {$v}h — « {$mat->libelle} »", (int) $mat->id);

        return $retour->with('succes', "Volume horaire prévu enregistré : {$v}h.");
    }

    /**
     * Mode d'accès : chef (département), faculte (doyen, sc_entite), admin
     * (admin/secrétaire sans fiche enseignant) ou enseignant.
     */
    private function mode(Utilisateur $u): string|RedirectResponse
    {
        if ($u->role === 'chef_departement') {
            return 'chef';
        }
        if (in_array($u->role, ['doyen', 'sc_entite'], true)) {
            return 'faculte';
        }
        if (in_array($u->role, ['admin', 'secretaire'], true) && ! $u->enseignant_id) {
            return 'admin';
        }
        if (! $u->enseignant_id) {
            return redirect()->route('accueil')->with('erreur', 'Aucun dossier enseignant lié à votre compte.');
        }
        if (! DB::table('enseignants')->where('id', $u->enseignant_id)->exists()) {
            return redirect()->route('accueil')->with('erreur', "Votre compte est lié à une fiche enseignant introuvable. Contactez l'administrateur.");
        }

        return 'enseignant';
    }

    /** Année choisie, sinon l'année active, sinon la plus récente. */
    private function annee(Request $request, Collection $annees): int
    {
        $id = (int) $request->query('annee_id');

        return $annees->firstWhere('id', $id)?->id ?? $annees->firstWhere('active', 1)?->id ?? $annees->first()?->id ?? 0;
    }

    /** Affectations visibles de l'année, une ligne par PV (normale, rattrapage) de la matière. */
    private function affectations(Utilisateur $u, string $mode, int $anneeId)
    {
        $q = DB::table('affectations as a')
            ->join('matieres as m', 'm.id', '=', 'a.matiere_id')
            ->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
            ->join('promotions as p', 'p.id', '=', 'ue.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('semestres as s', 's.id', '=', 'ue.semestre_id')
            ->leftJoin('pv_sessions as ps', fn ($j) => $j->on('ps.promotion_id', '=', 'p.id')->on('ps.semestre_id', '=', 'ue.semestre_id')->whereIn('ps.type_session', self::SESSIONS))
            ->where('a.annee_id', $anneeId)
            ->select(['a.id', 'a.enseignant_id', 'a.type_charge', 'm.id as mat_id', 'm.libelle as matiere', 'm.note_max_examen', 'm.note_max_cc',
                'm.finalisee', 'm.volume_horaire_prevu', 'm.heures_faites', 'f.libelle as filiere', 'n.code as niveau', 's.libelle as sem_label',
                'ps.id as pv_id', 'ps.libelle as pv_libelle', 'ps.type_session'])
            ->orderBy('f.libelle')->orderBy('n.code')->orderBy('m.libelle')->orderBy('ps.type_session');

        return match ($mode) {
            'enseignant' => $q->where('a.enseignant_id', $u->enseignant_id),
            'chef' => $q->where('f.departement_id', $u->departementId() ?: -1),
            'faculte' => $q->where('f.faculte_id', $u->faculteId() ?: -1),
            default => $q,
        };
    }

    /** PV + matière choisis s'ils figurent dans les affectations visibles, avec le type de charge applicable. */
    private function selection(Utilisateur $u, string $mode, int $anneeId, int $pvId, int $matId): ?array
    {
        if (! $pvId || ! $matId) {
            return null;
        }
        $lignes = $this->affectations($u, $mode, $anneeId)->where('ps.id', $pvId)->where('m.id', $matId)->get();
        $pv = $lignes->isNotEmpty() ? $this->pvs->trouver($pvId) : null;
        $mat = $pv ? $this->saisie->matieres($pv)->firstWhere('id', $matId) : null;
        if (! $mat) {
            return null;
        }

        return ['pv' => $pv, 'mat' => $mat, 'type_charge' => $mode === 'enseignant' ? (string) $lignes->first()->type_charge : 'cours_td'];
    }

    /** Onglets ouverts par l'affectation : examen (cours), TD (si la matière en a). */
    private function onglets(string $typeCharge, object $mat): array
    {
        $onglets = [];
        if ($typeCharge !== 'td') {
            $onglets['exam'] = 'Examen';
        }
        if ($typeCharge !== 'cours' && (float) $mat->note_max_cc > 0) {
            $onglets['cc'] = 'TD';
        }

        return $onglets;
    }

    private function libelleTc(string $tc): string
    {
        return $tc === 'cc' ? 'TD' : 'Examen';
    }

    /** Étudiants du PV pour la matière : au rattrapage, sans ceux qui l'ont acquise en 1ère session. */
    private function etudiants(object $pv, int $matId)
    {
        $q = $this->pvs->etudiants($pv);
        if ($pv->type_session === 'rattrapage' && $pv->pv_normale_id) {
            $q->whereNotExists(fn ($s) => $s->from('notes as ns1')->whereColumn('ns1.etudiant_id', 'e.id')
                ->where(['ns1.pv_id' => $pv->pv_normale_id, 'ns1.matiere_id' => $matId])->whereIn('ns1.observation', ['AV', 'AC']));
        }

        return $q;
    }

    /** Contrôles communs à la saisie et à l'import. */
    private function cibleSaisie(Request $request): array|RedirectResponse
    {
        $u = $request->user();
        $mode = $this->mode($u);
        if ($mode instanceof RedirectResponse) {
            return $mode;
        }
        $anneeId = (int) $request->input('annee_id');
        $pvId = (int) $request->input('pv_id');
        $matId = (int) $request->input('mat_id');
        $tc = (string) $request->input('type_charge', 'exam');
        $retour = route('portail_enseignant', ['annee_id' => $anneeId, 'pv_id' => $pvId, 'mat_id' => $matId, 'tc' => $tc]);
        if (! in_array($mode, ['enseignant', 'admin'], true)) {
            return redirect($retour)->with('erreur', "Vous consultez ce portail en lecture seule — la saisie de notes est réservée à l'enseignant affecté.");
        }
        $choix = $this->selection($u, $mode, $anneeId, $pvId, $matId);
        if (! $choix) {
            return redirect()->route('portail_enseignant')->with('erreur', 'Accès refusé.');
        }
        if ($choix['pv']->verrouille) {
            return redirect($retour)->with('erreur', 'Ce PV est verrouillé.');
        }
        if ($mode !== 'admin' && $this->notesEns->finalisee($choix['pv'], $choix['mat'], $tc)) {
            return redirect($retour)->with('erreur', 'Ces notes sont finalisées : elles ne sont plus modifiables.');
        }
        if (! isset($this->onglets($choix['type_charge'], $choix['mat'])[$tc])) {
            return redirect($retour)->with('erreur', "Vous n'êtes pas affecté pour saisir ce type de note sur cette matière.");
        }

        return $choix + ['tc' => $tc, 'retour' => $retour, 'mode' => $mode];
    }

    /**
     * Mode administration : directement dans le PV, résultats recalculés.
     * Enseignant : notes gardées en attente de finalisation, hors du PV.
     */
    private function enregistrer(Utilisateur $u, string $mode, object $pv, object $mat, string $tc, array $valeurs): void
    {
        if ($mode !== 'admin') {
            $this->notesEns->enregistrer($pv, $mat, $tc, $valeurs, (int) $u->id);

            return;
        }
        app(HistoriqueNotes::class)->suivre((int) $pv->id, 'portail enseignant (administration)', fn () => DB::transaction(function () use ($u, $pv, $mat, $tc, $valeurs) {
            foreach ($this->saisie->enregistrerComposante($pv, $mat, $tc, $valeurs, (int) $u->id) as $et) {
                $this->resultats->recalculer((int) $pv->id, (int) $et);
            }
        }), array_keys($valeurs));
    }

    /**
     * État de chaque ligne d'affectation (une par PV) : « finalisee » quand les
     * composantes de la ligne sont toutes finalisées dans ce PV, « en_attente »
     * = notes enregistrées pas encore finalisées.
     */
    private function etats(Collection $affectations, string $mode): Collection
    {
        $pvIds = $affectations->pluck('pv_id')->filter()->unique()->all();
        $faites = DB::table('notes_finalisations')->whereIn('pv_id', $pvIds)->get()->groupBy(fn ($f) => $f->pv_id.'-'.$f->matiere_id);
        $attente = DB::table('notes_enseignants')->whereIn('pv_id', $pvIds)->groupBy('pv_id', 'matiere_id', 'composante')
            ->get(['pv_id', 'matiere_id', 'composante', DB::raw('COUNT(*) AS nb')])->groupBy(fn ($n) => $n->pv_id.'-'.$n->matiere_id);

        return $affectations->each(function ($af) use ($mode, $faites, $attente) {
            $composantes = array_keys($this->onglets($mode === 'enseignant' ? (string) $af->type_charge : 'cours_td', $af));
            $cle = $af->pv_id.'-'.$af->mat_id;
            $finies = $faites->has($cle) ? $faites[$cle]->pluck('composante')->all()
                // Finalisée avant la finalisation par composante (voir NotesEnseignants::finalisees)
                : ($af->type_session === 'normale' && $af->finalisee && ! $attente->has($cle) ? $composantes : []);
            $af->en_attente = (int) $attente->get($cle, collect())->whereIn('composante', $composantes)->sum('nb');
            $af->finalisee = $af->pv_id && $composantes && ! array_diff($composantes, $finies);
            $af->une_finalisee = $af->pv_id && array_intersect($composantes, $finies);
        });
    }

    /** Finaliser, déclarer les heures : admin ; chef dans son département ; doyen dans sa faculté ; enseignant affecté. */
    private function peutFinaliser(Utilisateur $u, string $mode, int $matId): bool
    {
        $filiere = fn () => DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
            ->join('promotions as p', 'p.id', '=', 'ue.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->where('m.id', $matId);

        return match ($mode) {
            'admin' => true,
            'chef' => $u->departementId() > 0 && $filiere()->where('f.departement_id', $u->departementId())->exists(),
            'faculte' => $u->faculteId() > 0 && $filiere()->where('f.faculte_id', $u->faculteId())->exists(),
            default => DB::table('affectations')->where(['enseignant_id' => $u->enseignant_id, 'matiere_id' => $matId])->exists(),
        };
    }
}
