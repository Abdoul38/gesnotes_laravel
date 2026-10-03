<?php

namespace App\Http\Controllers\Notes;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Pv;
use App\Services\Resultats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Création d'un PV — portage de pages/notes_pv/pv_creer.php (admin,
 * secrétaire, sc_centrale, sc_entite).
 *  - normale : les notes validées des années précédentes sont reportées ;
 *  - rattrapage : les notes de la session normale des étudiants non
 *    validés sont recopiées, puis leurs résultats calculés ;
 *  - réclamation (fondées / déposées) : les notes du PV source des
 *    étudiants concernés sont recopiées, puis leurs résultats calculés.
 *
 * Différences voulues : promotion limitée à la faculté de l'agent, aussi à
 * l'enregistrement ; le PV de 1ère session (rattrapage) doit être un PV
 * normal de la même promotion et du même semestre ; le PV source
 * (réclamation) un PV normal ou de rattrapage de la même promotion et du
 * même semestre ; création, copies et calculs en une transaction.
 */
class PvCreationController extends Controller
{
    public function __construct(private readonly Resultats $resultats, private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    public function formulaire(Request $request): View
    {
        $faculte = $this->perimetre->faculte($request->user());
        $pvs = fn (array $types) => DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->whereIn('ps.type_session', $types)
            ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte));

        return view('notes.pv.creer', [
            'promotions' => DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
                ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('n.code')->get(['p.id', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']),
            'semestres' => DB::table('semestres')->orderBy('code')->get(),
            'pvSources' => $pvs(['normale', 'rattrapage'])->orderByDesc('ps.created_at')->limit(100)->get(['ps.id', 'ps.libelle']),
            'pvNormales' => $pvs(['normale'])->orderBy('ps.libelle')->get(['ps.id', 'ps.libelle', 'ps.promotion_id', 'ps.semestre_id']),
            'pvSource' => (int) $request->query('pv_source'),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $u = $request->user();
        $promo = (int) $request->input('promotion_id');
        $sem = (int) $request->input('semestre_id');
        $type = array_key_exists($request->input('type_session'), Pv::SESSIONS) ? $request->input('type_session') : 'normale';
        $libelle = trim((string) $request->input('libelle'));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('date_pv')) ? $request->input('date_pv') : null;
        $normale = $type === 'rattrapage' ? ((int) $request->input('pv_normale_id') ?: null) : null;
        $source = in_array($type, ['reclamation', 'reclamation_depot'], true) ? ((int) $request->input('pv_source_id') ?: null) : null;

        $erreurs = array_values(array_filter([
            ! $promo || ! DB::table('promotions')->where('id', $promo)->exists() ? 'Promotion requise.' : null,
            ! $sem || ! DB::table('semestres')->where('id', $sem)->exists() ? 'Semestre requis.' : null,
            $libelle === '' ? 'Intitulé requis.' : null,
            $type === 'rattrapage' && ! $normale ? 'Le PV de 1ère session (normale) est obligatoire pour créer un PV de rattrapage.' : null,
            in_array($type, ['reclamation', 'reclamation_depot'], true) && ! $source ? 'Le PV source est obligatoire pour créer un PV de réclamation.' : null,
        ]));
        if (! $erreurs && ! $this->perimetre->promotion($u, $promo)) {
            $erreurs[] = "Cette promotion n'appartient pas à votre faculté.";
        }
        if (! $erreurs && $normale && ! DB::table('pv_sessions')->where(['id' => $normale, 'promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale'])->exists()) {
            $erreurs[] = 'Le PV de 1ère session doit être un PV normal de la même promotion et du même semestre.';
        }
        if (! $erreurs && $source && ! DB::table('pv_sessions')->where(['id' => $source, 'promotion_id' => $promo, 'semestre_id' => $sem])->whereIn('type_session', ['normale', 'rattrapage'])->exists()) {
            $erreurs[] = 'Le PV source doit être un PV de session de la même promotion et du même semestre.';
        }
        if (! $erreurs && DB::table('pv_sessions')->where(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => $type])->exists()) {
            $erreurs[] = 'Un PV de ce type existe déjà pour cette promotion et ce semestre.';
        }
        if ($erreurs) {
            return back()->withInput()->with('erreurs_pv', $erreurs);
        }

        [$id, $message] = DB::transaction(function () use ($u, $promo, $sem, $type, $libelle, $date, $normale, $source) {
            $id = DB::table('pv_sessions')->insertGetId([
                'promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => $type, 'pv_normale_id' => $normale,
                'pv_source_id' => $source, 'libelle' => mb_substr($libelle, 0, 200), 'date_pv' => $date, 'statut' => 'brouillon', 'created_by' => $u->id,
            ]);
            $copier = function (int $depuis, array $etudiants) use ($id, $u) {
                foreach ($etudiants as $et) {
                    DB::insert('INSERT INTO notes (pv_id, etudiant_id, matiere_id, note_examen, note_cc, note_finale, defaillant, saisie_par)
                        SELECT ?, etudiant_id, matiere_id, note_examen, note_cc, note_finale, defaillant, ? FROM notes WHERE pv_id = ? AND etudiant_id = ?', [$id, $u->id, $depuis, $et]);
                    $this->resultats->recalculer($id, (int) $et);
                }

                return count($etudiants);
            };

            if ($type === 'reclamation' || $type === 'reclamation_depot') {
                $statuts = $type === 'reclamation' ? ['fondee'] : ['en_attente', 'fondee'];
                $nb = $copier($source, DB::table('reclamations')->where('pv_id', $source)->whereIn('statut', $statuts)->distinct()->pluck('etudiant_id')->all());
                $message = $nb ? "PV de réclamation créé — $nb étudiant(s) ".($type === 'reclamation' ? 'avec réclamation(s) fondée(s)' : 'ayant déposé une réclamation').' inclus.'
                    : 'PV de réclamation créé. Aucune réclamation '.($type === 'reclamation' ? 'fondée' : 'déposée').' trouvée pour ce PV source.';
            } elseif ($type === 'rattrapage') {
                $pv = app(Pv::class)->trouver($id);
                $nb = $copier($normale, app(Pv::class)->etudiants($pv)->pluck('e.id')->all());
                $message = $nb ? "PV rattrapage créé — $nb étudiant(s) non validés copiés depuis S1." : 'PV créé (aucun étudiant non validé trouvé en S1).';
            } else {
                $pv = DB::table('promotions')->where('id', $promo)->first();
                $etudiants = DB::table('inscriptions_pedagogiques as ip')->join('inscriptions as i', 'i.id', '=', 'ip.inscription_id')
                    ->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                    ->where(['ip.semestre_id' => $sem, 'ip.annee_id' => $pv->annee_id, 'ip.statut' => 'actif', 'p.filiere_id' => $pv->filiere_id, 'p.niveau_id' => $pv->niveau_id])
                    ->distinct()->pluck('ip.etudiant_id');
                $nb = $etudiants->sum(fn ($et) => $this->resultats->reporterNotes((int) $et, $id));
                $message = 'PV créé avec succès.'.($nb > 0 ? " $nb note(s) reportée(s) automatiquement." : '');
            }

            return [$id, $message];
        });
        $this->journal->action('creer', 'pv', "Création du PV « $libelle »", $id);

        return redirect()->route('pv_detail', ['id' => $id])->with('succes', $message);
    }
}
