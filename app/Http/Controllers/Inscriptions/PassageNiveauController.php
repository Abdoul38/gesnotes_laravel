<?php

namespace App\Http\Controllers\Inscriptions;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Inscriptions;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Passage en masse — portage de pages/inscriptions/passage_niveau.php.
 * Pour une promotion, enregistre la décision de passage (passage,
 * redoublement, licence validée) de chaque étudiant coché ; aucune
 * inscription n'est créée.
 *
 * Différences voulues :
 *  - règle de décision commune avec la page individuelle (voir
 *    Inscriptions::decisionPassage) : l'enregistrement donne la même
 *    décision que celle affichée (avant, deux règles différentes) ;
 *  - seuls les étudiants inscrits dans la promotion choisie sont traités
 *    (avant : n'importe quel identifiant, avec le niveau de sa dernière
 *    inscription quelle qu'elle soit) ; filière limitée à la faculté de
 *    l'agent ; filière de spécialisation contrôlée ; nouvelle année vérifiée ;
 *  - semestres validés comptés en sessions normale et rattrapage seulement,
 *    comme à l'affichage.
 */
class PassageNiveauController extends Controller
{
    public function __construct(private readonly Inscriptions $inscriptions, private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $faculte = $this->perimetre->faculte($request->user());
        $anneeId = $request->has('annee_id') ? (int) $request->query('annee_id') : (int) DB::table('annees_academiques')->where('active', 1)->value('id');
        $filiereId = (int) $request->query('filiere_id');
        $niveauId = (int) $request->query('niveau_id');
        $visible = $filiereId && $this->perimetre->filiere($request->user(), $filiereId);

        $etudiants = collect();
        if ($anneeId && $niveauId && $visible) {
            $etudiants = $this->inscrits($anneeId, $filiereId, $niveauId)->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero', 'n.code as niveau_code']);
            $valides = $this->valides($etudiants->pluck('id')->all());
            $etudiants = $etudiants->map(function ($et) use ($valides) {
                $et->valides = $valides[$et->id] ?? [];
                [$et->suivant, , $et->statut] = Inscriptions::decisionPassage($et->niveau_code, $et->valides);

                return $et;
            });
        }

        return view('inscriptions.passage_masse', [
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'filieres' => DB::table('filieres')->where('actif', 1)->when($faculte, fn ($q) => $q->where('faculte_id', $faculte))->orderBy('libelle')->get(['id', 'libelle']),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(),
            'anneeId' => $anneeId,
            'filiereId' => $filiereId,
            'niveauId' => $niveauId,
            'etudiants' => $etudiants,
            'filieresL3' => $visible ? $this->inscriptions->filieresSpecialisation($filiereId, 'L3') : collect(),
            'filieresLP' => $visible ? $this->inscriptions->filieresSpecialisation($filiereId, 'LP') : collect(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $anneeId = (int) $request->input('annee_id');
        $filiereId = (int) $request->input('filiere_id');
        $niveauId = (int) $request->input('niveau_id');
        $nouvelleAnnee = (int) $request->input('nouvel_annee_id');
        $coches = array_map('intval', (array) $request->input('etudiants', []));
        $retour = route('passage_niveau', ['annee_id' => $anneeId, 'filiere_id' => $filiereId, 'niveau_id' => $niveauId]);

        if (! $coches || ! $nouvelleAnnee || $nouvelleAnnee === $anneeId || ! DB::table('annees_academiques')->where('id', $nouvelleAnnee)->exists()) {
            return redirect($retour)->with('erreur', 'Sélectionnez au moins un étudiant et une nouvelle année différente de celle de la promotion.');
        }
        if (! $this->perimetre->filiere($request->user(), $filiereId)) {
            return redirect($retour)->with('erreur', "Cette filière n'appartient pas à votre faculté.");
        }

        $etudiants = $this->inscrits($anneeId, $filiereId, $niveauId)->whereIn('e.id', $coches)->get(['e.id', 'n.code as niveau_code']);
        $valides = $this->valides($etudiants->pluck('id')->all());
        $niveaux = DB::table('niveaux')->pluck('id', 'code');
        $choixL3 = (array) $request->input('filiere_l3', []);
        $choixLP = (array) $request->input('filiere_lp', []);
        $proposees = ['L3' => $this->inscriptions->filieresSpecialisation($filiereId, 'L3')->pluck('id')->all(),
            'LP' => $this->inscriptions->filieresSpecialisation($filiereId, 'LP')->pluck('id')->all()];

        $traites = 0;
        DB::transaction(function () use ($etudiants, $valides, $niveaux, $choixL3, $choixLP, $proposees, $filiereId, $niveauId, $anneeId, $nouvelleAnnee, &$traites) {
            foreach ($etudiants as $et) {
                [$suivant, $decision] = Inscriptions::decisionPassage($et->niveau_code, $valides[$et->id] ?? []);
                if (! isset($niveaux[$suivant])) {
                    continue;
                }
                $choisie = (int) (['L3' => $choixL3, 'LP' => $choixLP][$suivant][$et->id] ?? 0);
                $filiere = $choisie && in_array($choisie, $proposees[$suivant] ?? [], false) ? $choisie : $filiereId;
                $this->inscriptions->enregistrerPassage($et->id, $filiere, $niveauId, (int) $niveaux[$suivant], $anneeId, $nouvelleAnnee, $decision);
                $traites++;
            }
        });
        $ignores = count($coches) - $traites;
        $this->journal->action('modifier', 'passage_niveau', "Décisions de passage — $traites étudiant(s) traité(s), $ignores ignoré(s)");

        return redirect($retour)->with('succes', "✅ Décisions enregistrées — $traites étudiant(s) traité(s). Vous pouvez maintenant les inscrire académiquement via Inscription Scolarité. $ignores ignoré(s).");
    }

    private function inscrits(int $anneeId, int $filiereId, int $niveauId)
    {
        return DB::table('inscriptions as i')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->where(['p.filiere_id' => $filiereId, 'p.niveau_id' => $niveauId, 'p.annee_id' => $anneeId, 'i.statut' => 'inscrit'])
            ->distinct()->orderBy('e.nom')->orderBy('e.prenoms');
    }

    /** @return array<int, list<string>> semestres validés par étudiant */
    private function valides(array $ids): array
    {
        return DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
            ->whereIn('rs.etudiant_id', $ids)->where('rs.valide', 1)->whereIn('ps.type_session', ['normale', 'rattrapage'])
            ->distinct()->orderBy('s.code')->get(['rs.etudiant_id', 's.code'])
            ->groupBy('etudiant_id')->map(fn ($g) => $g->pluck('code')->all())->all();
    }
}
