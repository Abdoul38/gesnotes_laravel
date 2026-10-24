<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\HistoriqueNotes;
use App\Services\Journal;
use App\Services\Reclamations;
use App\Services\Resultats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Traitement d'une réclamation — portage de
 * pages/reclamations/reclamation_traitement.php : fondée (note corrigée puis
 * résultats recalculés), non fondée ou rejetée.
 *
 * Différences voulues :
 *  - une réclamation déjà traitée ne peut plus être retraitée (l'ancien
 *    formulaire était masqué mais le POST restait accepté) ;
 *  - notes corrigées bornées côté serveur (0 … maximum de l'examen / du CC) ;
 *  - si seule la note d'examen est corrigée, le CC existant est conservé
 *    (l'ancien l'effaçait et prenait l'examen seul comme note finale) ;
 *  - commentaire limité à 2000 caractères ;
 *  - réclamation fondée refusée tant que le PV est verrouillé : il faut le
 *    faire déverrouiller, comme pour toute autre modification de notes
 *    (décision de l'université ; avant, la note était corrigée quand même).
 *    « Non fondée » et « rejetée » restent possibles (aucune note touchée).
 */
class ReclamationTraitementController extends Controller
{
    private const ACTIONS = ['fondee', 'non_fondee', 'rejetee'];

    private const PV_VERROUILLE = 'Le PV est verrouillé : faites-le déverrouiller avant de corriger la note (« non fondée » et « rejetée » restent possibles).';

    public function __construct(private readonly Reclamations $reclamations) {}

    public function afficher(Request $request, int $id): View|RedirectResponse
    {
        $r = $this->charger($id);
        if ($refus = $this->refus($request, $r)) {
            return $refus;
        }

        return view('notes.reclamations.traitement', [
            'r' => $r,
            'noteActuelle' => $this->noteActuelle($r),
            'toutesNotes' => DB::table('notes as n')->join('matieres as m', 'm.id', '=', 'n.matiere_id')->leftJoin('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
                ->where(['n.pv_id' => $r->pv_id, 'n.etudiant_id' => $r->etudiant_id])->orderBy('ue.libelle')->orderBy('m.libelle')
                ->get(['n.*', 'm.libelle as matiere']),
            'resultat' => DB::table('resultats_semestre')->where(['pv_id' => $r->pv_id, 'etudiant_id' => $r->etudiant_id])->first(),
        ]);
    }

    public function traiter(Request $request, int $id, Resultats $resultats, Journal $journal): RedirectResponse
    {
        $r = $this->charger($id);
        if ($refus = $this->refus($request, $r)) {
            return $refus;
        }
        if ($r->statut !== 'en_attente') {
            return redirect()->route('reclamation_traitement', $id)->with('erreur', 'Cette réclamation a déjà été traitée.');
        }
        $maxExam = (float) ($r->note_max_examen ?? 20);
        $maxCc = (float) ($r->note_max_cc ?? 0);
        $validation = Validator::make($request->all(), [
            'action' => 'required|in:'.implode(',', self::ACTIONS),
            'commentaire' => 'nullable|string|max:2000',
            'note_examen' => "nullable|numeric|min:0|max:$maxExam",
            'note_cc' => 'nullable|numeric|min:0|max:'.max($maxCc, 0),
            'note_finale_override' => 'nullable|numeric|min:0|max:'.($maxExam + $maxCc),
        ], [
            'action.*' => 'Action invalide.',
            'note_examen.*' => "Note d'examen invalide (0 à $maxExam).",
            'note_cc.*' => "Note de CC invalide (0 à $maxCc).",
            'note_finale_override.*' => 'Note finale invalide (0 à '.($maxExam + $maxCc).').',
        ]);
        if ($validation->fails()) {
            return back()->withInput()->with('erreur', $validation->errors()->first());
        }
        $v = $validation->validated();
        $exam = isset($v['note_examen']) ? (float) $v['note_examen'] : null;
        $cc = $maxCc > 0 && isset($v['note_cc']) ? (float) $v['note_cc'] : null;
        $finale = isset($v['note_finale_override']) ? (float) $v['note_finale_override'] : null;
        if ($v['action'] === 'fondee' && $r->verrouille) {
            return back()->withInput()->with('erreur', self::PV_VERROUILLE);
        }
        if ($v['action'] === 'fondee' && $exam === null && $cc === null && $finale === null) {
            return back()->withInput()->with('erreur', 'Veuillez saisir au moins une note corrigée si la réclamation est fondée.');
        }

        $noteCorrigee = null;
        $verrouilleEntreTemps = false;
        // Ancienne → nouvelle note dans les journaux d'activité
        app(HistoriqueNotes::class)->suivre((int) $r->pv_id, 'réclamation fondée', function () use ($r, $v, $exam, $cc, $finale, $maxCc, $request, $resultats, $journal, &$noteCorrigee, &$verrouilleEntreTemps) {
            DB::transaction(function () use ($r, $v, $exam, $cc, $finale, $maxCc, $request, $resultats, $journal, &$noteCorrigee, &$verrouilleEntreTemps) {
                // Verrouillé pendant le traitement : rien n'est enregistré
                if ($v['action'] === 'fondee' && DB::table('pv_sessions')->where('id', $r->pv_id)->lockForUpdate()->value('verrouille')) {
                    $verrouilleEntreTemps = true;

                    return;
                }
                if ($v['action'] === 'fondee') {
                    $actuelle = $this->noteActuelle($r);
                    if ($maxCc > 0 && $cc === null) {
                        $cc = $actuelle?->note_cc !== null ? (float) $actuelle->note_cc : null;
                    }
                    if ($finale !== null) {
                        $noteCorrigee = round($finale, 2);
                        $exam ??= $noteCorrigee;
                    } elseif ($exam !== null) {
                        $noteCorrigee = round($exam + ($cc ?? 0), 2);
                    }
                    $cle = ['pv_id' => $r->pv_id, 'etudiant_id' => $r->etudiant_id, 'matiere_id' => $r->matiere_id];
                    $valeurs = ['note_examen' => $exam, 'note_cc' => $cc, 'note_finale' => $noteCorrigee];
                    if ($actuelle) {
                        DB::table('notes')->where($cle)->update($valeurs + ['updated_at' => now()]);
                    } else {
                        DB::table('notes')->insert($cle + $valeurs + ['saisie_par' => $request->user()->id]);
                    }
                    $resultats->recalculer((int) $r->pv_id, (int) $r->etudiant_id);
                }
                DB::table('reclamations')->where('id', $r->id)->update([
                    'statut' => $v['action'], 'note_corrigee' => $noteCorrigee, 'commentaire' => ($v['commentaire'] ?? '') ?: null,
                    'traitee_par' => $request->user()->id, 'traitee_le' => now(), 'updated_at' => now(),
                ]);
                $journal->audit('reclamation_'.$v['action'], 'reclamations', (int) $r->id, "Réclamation de {$r->nom} {$r->prenoms} sur {$r->matiere} — PV #{$r->pv_id}");
                $journal->action('modifier', 'reclamation', "Traitement réclamation ({$v['action']}) — {$r->nom} {$r->prenoms}, {$r->matiere}", (int) $r->id);
            });
        }, [(int) $r->etudiant_id]);

        if ($verrouilleEntreTemps) {
            return back()->withInput()->with('erreur', self::PV_VERROUILLE);
        }

        return redirect()->route('reclamations')->with('succes', match ($v['action']) {
            'fondee' => '✅ Réclamation fondée — note corrigée et résultats recalculés.',
            'non_fondee' => '❌ Réclamation marquée non fondée.',
            'rejetee' => '🚫 Réclamation rejetée.',
        });
    }

    private function charger(int $id): ?object
    {
        return DB::table('reclamations as r')
            ->join('etudiants as e', 'e.id', '=', 'r.etudiant_id')->join('pv_sessions as ps', 'ps.id', '=', 'r.pv_id')
            ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->join('matieres as m', 'm.id', '=', 'r.matiere_id')->leftJoin('utilisateurs as u', 'u.id', '=', 'r.traitee_par')
            ->where('r.id', $id)
            ->first(['r.*', 'e.nom', 'e.prenoms', 'e.numero as matricule', 'ps.libelle as pv_libelle', 'ps.type_session', 'ps.verrouille',
                'm.libelle as matiere', 'm.note_max_examen', 'm.note_max_cc', 'f.code as filiere_code', 'f.faculte_id', 'f.departement_id',
                'n.code as niveau', 'a.libelle as annee', 'u.nom as traitee_par_nom']);
    }

    private function refus(Request $request, ?object $r): ?RedirectResponse
    {
        if (! $r) {
            return redirect()->route('reclamations')->with('erreur', 'Réclamation introuvable.');
        }
        if (! $this->reclamations->peutTraiter($request->user(), $r)) {
            return redirect()->route('reclamations')->with('erreur', 'Cette réclamation ne relève pas de votre périmètre.');
        }

        return null;
    }

    private function noteActuelle(object $r): ?object
    {
        return DB::table('notes')->where(['pv_id' => $r->pv_id, 'etudiant_id' => $r->etudiant_id, 'matiere_id' => $r->matiere_id])->first();
    }
}
