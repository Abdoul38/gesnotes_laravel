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
 * Inscription pédagogique en masse — portage de
 * pages/inscriptions/inscription_ped_masse.php. Pour une promotion
 * (année × filière × niveau), coche les semestres de chaque étudiant
 * inscrit, avec une suggestion d'après ses semestres validés. Ajoute ou
 * réactive des semestres ; n'en retire aucun (comme l'original).
 *
 * Différences voulues :
 *  - la page fonctionne : ses scripts en ligne étaient bloqués par la
 *    politique de sécurité (CSP), le formulaire partait vide et rien
 *    n'était enregistré ;
 *  - filière limitée à la faculté de l'agent, aussi à l'enregistrement ;
 *    seuls les étudiants inscrits dans la promotion et des semestres
 *    existants sont pris en compte ;
 *  - mêmes règles de suggestion que l'inscription individuelle ;
 *  - deux requêtes pour toute la promotion (l'ancienne en faisait deux par étudiant).
 */
class InscriptionPedMasseController extends Controller
{
    public function __construct(private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $faculte = $this->perimetre->faculte($request->user());
        $anneeActive = (int) DB::table('annees_academiques')->where('active', 1)->value('id');
        $anneeId = $request->has('annee_id') ? (int) $request->query('annee_id') : $anneeActive;
        $filiereId = (int) $request->query('filiere_id');
        $niveauId = (int) $request->query('niveau_id');
        $semestres = DB::table('semestres')->orderBy('id')->get();

        $etudiants = collect();
        if ($anneeId && $filiereId && $niveauId && $this->perimetre->filiere($request->user(), $filiereId)) {
            $filiere = DB::table('filieres')->where('id', $filiereId)->first();
            $droit = Inscriptions::estDroit($filiere->libelle ?? '');
            $codes = $semestres->pluck('code', 'id');
            $etudiants = $this->inscrits($anneeId, $filiereId, $niveauId)->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero']);
            $ids = $etudiants->pluck('id');
            $deja = DB::table('inscriptions_pedagogiques')->whereIn('etudiant_id', $ids)->where('annee_id', $anneeId)->where('statut', 'actif')
                ->get(['etudiant_id', 'semestre_id'])->groupBy('etudiant_id');
            $valides = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
                ->whereIn('rs.etudiant_id', $ids)->where('rs.valide', 1)->whereIn('ps.type_session', ['normale', 'rattrapage'])
                ->distinct()->orderBy('s.code')->get(['rs.etudiant_id', 's.code'])->groupBy('etudiant_id');

            $etudiants = $etudiants->map(function ($et) use ($deja, $valides, $droit, $codes) {
                $et->valides = $valides->get($et->id, collect())->pluck('code')->all();
                $et->suggeres = Inscriptions::semestresSuggeres($et->valides, $droit);
                $et->sugg_ids = $codes->filter(fn ($c) => in_array($c, $et->suggeres, true))->keys()->values()->all();
                $et->deja = $deja->get($et->id, collect())->pluck('semestre_id')->map(fn ($i) => (int) $i)->all();
                $et->deja_codes = array_map(fn ($i) => $codes[$i] ?? '', $et->deja);

                return $et;
            });
        }

        return view('inscriptions.ped_masse', [
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'filieres' => DB::table('filieres')->where('actif', 1)->when($faculte, fn ($q) => $q->where('faculte_id', $faculte))->orderBy('libelle')->get(['id', 'libelle']),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(),
            'semestres' => $semestres,
            'anneeId' => $anneeId,
            'filiereId' => $filiereId,
            'niveauId' => $niveauId,
            'etudiants' => $etudiants,
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $anneeId = (int) $request->input('annee_id');
        $filiereId = (int) $request->input('filiere_id');
        $niveauId = (int) $request->input('niveau_id');
        $retour = route('inscription_ped_masse', ['annee_id' => $anneeId, 'filiere_id' => $filiereId, 'niveau_id' => $niveauId]);
        $choix = json_decode((string) $request->input('etudiants_json'), true);

        if (! $anneeId || ! $filiereId || ! $niveauId || ! is_array($choix) || ! $choix) {
            return redirect($retour)->with('erreur', 'Aucun semestre coché.');
        }
        if (! $this->perimetre->filiere($request->user(), $filiereId)) {
            return redirect($retour)->with('erreur', "Cette filière n'appartient pas à votre faculté.");
        }

        // Inscription scolarité de chaque étudiant DANS cette promotion
        $inscriptions = $this->inscrits($anneeId, $filiereId, $niveauId)->select('i.id as ins_id', 'e.id as et_id')->pluck('ins_id', 'et_id');
        $semestres = DB::table('semestres')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $lignes = [];
        $ignores = 0;
        foreach ($choix as $etId => $semIds) {
            $semIds = array_intersect(array_map('intval', (array) $semIds), $semestres);
            if (! $semIds) {
                continue;
            }
            if (! isset($inscriptions[(int) $etId])) {
                $ignores++;

                continue;
            }
            foreach ($semIds as $semId) {
                $lignes[] = ['etudiant_id' => (int) $etId, 'inscription_id' => $inscriptions[(int) $etId], 'semestre_id' => $semId,
                    'annee_id' => $anneeId, 'statut' => 'actif', 'date_inscription' => now()->toDateString(), 'saisie_par' => $request->user()->id];
            }
        }
        $nb = count(array_unique(array_column($lignes, 'etudiant_id')));
        foreach (array_chunk($lignes, 500) as $paquet) {
            DB::table('inscriptions_pedagogiques')->upsert($paquet, ['etudiant_id', 'semestre_id', 'annee_id'], ['statut', 'date_inscription']);
        }
        $this->journal->action('inscrire', 'inscription_pedagogique', "Inscription pédagogique en masse : $nb étudiant(s), filière #$filiereId, niveau #$niveauId, année #$anneeId");

        return redirect($retour)->with('succes', "✅ Inscription pédagogique en masse — $nb étudiant(s) inscrits. $ignores ignoré(s).");
    }

    private function inscrits(int $anneeId, int $filiereId, int $niveauId)
    {
        return DB::table('inscriptions as i')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->where(['p.filiere_id' => $filiereId, 'p.niveau_id' => $niveauId, 'p.annee_id' => $anneeId, 'i.statut' => 'inscrit'])
            ->orderBy('e.nom')->orderBy('e.prenoms');
    }
}
