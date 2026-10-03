<?php

namespace App\Http\Controllers\Inscriptions;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Choix des matières optionnelles — portage de pages/etudiants/choix_options.php
 * (admin, secrétaire, sc_entite — ancien hasRole hiérarchique). Pour une
 * promotion et un semestre, saisit pour chaque étudiant inscrit
 * pédagogiquement les options choisies dans les UE « à options ».
 *
 * Différences voulues :
 *  - décocher toutes les options d'un étudiant les retire (avant, rien
 *    n'était envoyé pour lui et ses anciens choix restaient) ;
 *  - seuls les étudiants inscrits pédagogiquement à ce semestre et les UE
 *    de ce semestre sont acceptés (avant : n'importe quel identifiant, et
 *    une UE d'un autre semestre était rangée sous le semestre envoyé) ;
 *  - promotion limitée à la faculté de l'agent, aussi à l'enregistrement ;
 *  - enregistrement en une transaction, journalisé.
 */
class ChoixOptionsController extends Controller
{
    public function __construct(private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $faculte = $this->perimetre->faculte($request->user());
        $anneeId = (int) $request->query('annee_id') ?: (int) DB::table('annees_academiques')->where('active', 1)->value('id');
        $promoId = (int) $request->query('promo_id');
        $semId = (int) $request->query('sem_id');
        $valide = $promoId && $semId && $this->perimetre->promotion($request->user(), $promoId);

        $ues = $valide ? $this->uesAOptions($promoId, $semId) : collect();
        $matieres = DB::table('matieres')->whereIn('ue_id', $ues->pluck('id'))->where('actif', 1)
            ->orderByDesc('type_matiere')->orderBy('ordre')->get(['id', 'ue_id', 'code', 'libelle', 'credits', 'type_matiere'])->groupBy('ue_id');
        $ues = $ues->map(function ($ue) use ($matieres) {
            $liste = $matieres->get($ue->id, collect());
            $ue->optionnelles = $liste->where('type_matiere', 'optionnelle')->values();
            $ue->obligatoires = $liste->where('type_matiere', '!=', 'optionnelle')->values();

            return $ue;
        });

        return view('inscriptions.choix_options', [
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'promotions' => DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->where('p.annee_id', $anneeId)
                ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))->orderBy('f.libelle')->orderBy('n.code')
                ->get(['p.id', 'f.code as filiere_code', 'n.code as niveau', 'a.libelle as annee']),
            'semestres' => DB::table('semestres')->orderBy('id')->get(),
            'anneeId' => $anneeId,
            'promoId' => $promoId,
            'semId' => $semId,
            'ues' => $ues,
            'etudiants' => $valide ? $this->etudiants($promoId, $semId)->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero']) : collect(),
            'choix' => $valide ? DB::table('choix_options_etudiants')->where('promotion_id', $promoId)->where('semestre_id', $semId)
                ->get(['etudiant_id', 'ue_id', 'matiere_id'])->groupBy(fn ($c) => $c->etudiant_id.'-'.$c->ue_id)
                ->map(fn ($g) => $g->pluck('matiere_id')->map(fn ($i) => (int) $i)->all()) : collect(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $promoId = (int) $request->input('promo_id');
        $semId = (int) $request->input('sem_id');
        $promo = DB::table('promotions')->where('id', $promoId)->first();
        $retour = route('choix_options', ['annee_id' => $promo->annee_id ?? null, 'promo_id' => $promoId, 'sem_id' => $semId]);
        if (! $promo || ! $semId) {
            return redirect($retour)->with('erreur', 'Promotion ou semestre introuvable.');
        }
        if (! $this->perimetre->promotion($request->user(), $promoId)) {
            return redirect($retour)->with('erreur', "Cette promotion n'appartient pas à votre faculté.");
        }

        // Étudiants affichés dans le formulaire ET réellement inscrits à ce semestre
        $etudiants = $this->etudiants($promoId, $semId)->whereIn('e.id', array_map('intval', (array) $request->input('etudiants', [])))->pluck('e.id')->map(fn ($i) => (int) $i)->all();
        $ues = $this->uesAOptions($promoId, $semId)->keyBy('id');
        $options = DB::table('matieres')->whereIn('ue_id', $ues->keys())->where('type_matiere', 'optionnelle')->where('actif', 1)
            ->get(['id', 'ue_id'])->groupBy('ue_id')->map(fn ($g) => $g->pluck('id')->map(fn ($i) => (int) $i)->all());
        $saisie = (array) $request->input('choix', []);

        DB::transaction(function () use ($ues, $etudiants, $options, $saisie, $promo, $semId, $request) {
            foreach ($ues as $ueId => $ue) {
                DB::table('choix_options_etudiants')->where(['ue_id' => $ueId, 'promotion_id' => $promo->id, 'semestre_id' => $semId])
                    ->whereIn('etudiant_id', $etudiants)->delete();
                $lignes = [];
                foreach ($etudiants as $etId) {
                    $coches = array_map('intval', (array) ($saisie[$ueId][$etId] ?? []));
                    $coches = array_slice(array_values(array_intersect($coches, $options->get($ueId, []))), 0, (int) $ue->nb_options_a_choisir);
                    foreach ($coches as $matId) {
                        $lignes[] = ['etudiant_id' => $etId, 'matiere_id' => $matId, 'ue_id' => $ueId, 'annee_id' => $promo->annee_id,
                            'promotion_id' => $promo->id, 'semestre_id' => $semId, 'saisie_par' => $request->user()->id];
                    }
                }
                foreach (array_chunk($lignes, 500) as $paquet) {
                    DB::table('choix_options_etudiants')->insertOrIgnore($paquet);
                }
            }
        });
        $this->journal->action('modifier', 'choix_options', 'Choix des options : '.count($etudiants)." étudiant(s), promotion #{$promo->id}, semestre #$semId");

        return redirect($retour)->with('succes', '✅ Choix des matières optionnelles enregistrés.');
    }

    private function uesAOptions(int $promoId, int $semId)
    {
        return DB::table('unites_enseignement')->where('promotion_id', $promoId)->where('semestre_id', $semId)
            ->where('nb_options_a_choisir', '>', 0)->orderBy('ordre')->get(['id', 'code', 'libelle', 'nb_options_a_choisir']);
    }

    private function etudiants(int $promoId, int $semId)
    {
        return DB::table('etudiants as e')
            ->whereExists(fn ($q) => $q->from('inscriptions as i')->whereColumn('i.etudiant_id', 'e.id')->where('i.promotion_id', $promoId)->where('i.statut', 'inscrit'))
            ->whereExists(fn ($q) => $q->from('inscriptions_pedagogiques as ip')->whereColumn('ip.etudiant_id', 'e.id')->where('ip.semestre_id', $semId)->where('ip.statut', 'actif'))
            ->orderBy('e.nom')->orderBy('e.prenoms');
    }
}
