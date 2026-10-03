<?php

namespace App\Http\Controllers\Inscriptions;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Fichiers;
use App\Services\Inscriptions;
use App\Services\Journal;
use App\Services\Resultats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Inscription pédagogique individuelle — portage de
 * pages/inscriptions/inscription_pedagogique.php (admin, secrétaire,
 * sc_centrale, sc_entite). Coche les semestres que l'étudiant suit, avec
 * une suggestion d'après ses semestres validés ; décocher retire le
 * semestre. Les notes déjà validées d'années précédentes sont reportées dans
 * le PV de la session normale, puis les résultats recalculés.
 *
 * Différences voulues :
 *  - l'année de l'inscription pédagogique est celle de l'inscription
 *    scolarité retenue (l'ancienne page prenait toujours l'année active,
 *    même quand l'étudiant n'était inscrit qu'une année précédente) ;
 *  - l'inscription envoyée doit être une inscription « inscrit » de cet
 *    étudiant, dans la faculté de l'agent ; semestres vérifiés ;
 *  - enregistrement, report et recalcul en une transaction.
 */
class InscriptionPedagogiqueController extends Controller
{
    public function __construct(
        private readonly Inscriptions $inscriptions,
        private readonly Resultats $resultats,
        private readonly Perimetre $perimetre,
        private readonly Journal $journal,
        private readonly Fichiers $fichiers,
    ) {}

    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('matricule'));
        $etudiant = null;
        if ($id = (int) $request->query('et_id')) {
            $etudiant = $this->etudiant(fn ($q) => $q->where('e.id', $id));
        }
        if (! $etudiant && $recherche !== '') {
            $m = mb_strtoupper($recherche);
            $etudiant = $this->etudiant(fn ($q) => $q->where('e.numero', $m)->orWhere('e.numero_dossier', $m));
        }
        $donnees = ['recherche' => $recherche, 'cherche' => $recherche !== '' || $request->filled('et_id'), 'etudiant' => $etudiant];
        if (! $etudiant) {
            return view('inscriptions.pedagogique', $donnees);
        }

        $anneeActive = (int) DB::table('annees_academiques')->where('active', 1)->value('id');
        $active = $this->inscriptionRetenue($etudiant->id, $anneeActive);

        // Passage vers une filière spécialisée cette année : on affiche celle-ci
        $passage = $this->inscriptions->passage($etudiant->id, $anneeActive);
        if ($passage && $passage->filiere_id && (int) $passage->filiere_id !== (int) $etudiant->filiere_id) {
            $etudiant->filiere_lib = $passage->filiere_lib;
            $etudiant->filiere_code = DB::table('filieres')->where('id', $passage->filiere_id)->value('code');
        }
        $droit = Inscriptions::estDroit($etudiant->filiere_lib, $etudiant->filiere_code);
        $valides = $this->inscriptions->semestresValides($etudiant->id);
        $semestres = DB::table('semestres')->orderBy('id')->get();
        $suggeres = Inscriptions::semestresSuggeres($valides, $droit);

        return view('inscriptions.pedagogique', $donnees + [
            'photo' => $etudiant->photo && is_file($this->fichiers->dossier('photos').DIRECTORY_SEPARATOR.basename($etudiant->photo))
                ? route('fichiers.photo', basename($etudiant->photo)) : null,
            'active' => $active,
            'droit' => $droit,
            'valides' => $valides,
            'semestres' => $semestres,
            'suggeres' => $semestres->whereIn('code', $suggeres)->pluck('id')->map(fn ($i) => (int) $i)->all(),
            'deja' => $active ? DB::table('inscriptions_pedagogiques')->where(['etudiant_id' => $etudiant->id, 'annee_id' => $active->annee_id, 'statut' => 'actif'])
                ->pluck('semestre_id')->map(fn ($i) => (int) $i)->all() : [],
            'historique' => DB::table('inscriptions_pedagogiques as ip')->join('semestres as s', 's.id', '=', 'ip.semestre_id')
                ->join('annees_academiques as a', 'a.id', '=', 'ip.annee_id')->leftJoin('inscriptions as i', 'i.id', '=', 'ip.inscription_id')
                ->leftJoin('promotions as p', 'p.id', '=', 'i.promotion_id')->leftJoin('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->where('ip.etudiant_id', $etudiant->id)->where('ip.statut', 'actif')->orderByDesc('a.libelle')->orderBy('s.id')
                ->get(['ip.date_inscription', 's.code as sem_code', 's.libelle as sem_lib', 'a.libelle as annee_lib', 'a.active', 'n.code as niveau_code'])
                ->groupBy('annee_lib'),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $etId = (int) $request->input('etudiant_id');
        $retour = route('inscription_pedagogique', ['et_id' => $etId]);
        $semestres = array_values(array_intersect(array_map('intval', (array) $request->input('semestres', [])),
            DB::table('semestres')->pluck('id')->map(fn ($i) => (int) $i)->all()));
        $ins = DB::table('inscriptions')->where(['id' => (int) $request->input('inscription_id'), 'etudiant_id' => $etId, 'statut' => 'inscrit'])->first();

        if (! $ins || ! $semestres) {
            return redirect($retour)->with('erreur', 'Veuillez sélectionner au moins un semestre.');
        }
        if (! $this->perimetre->promotion($request->user(), (int) $ins->promotion_id)) {
            return redirect($retour)->with('erreur', "Cet étudiant n'appartient pas à votre faculté.");
        }
        $annee = (int) ($ins->annee_id ?: DB::table('promotions')->where('id', $ins->promotion_id)->value('annee_id'));

        $reportees = DB::transaction(function () use ($etId, $ins, $annee, $semestres, $request) {
            DB::table('inscriptions_pedagogiques')->upsert(array_map(fn ($s) => [
                'etudiant_id' => $etId, 'inscription_id' => $ins->id, 'semestre_id' => $s, 'annee_id' => $annee,
                'statut' => 'actif', 'date_inscription' => now()->toDateString(), 'saisie_par' => $request->user()->id,
            ], $semestres), ['etudiant_id', 'semestre_id', 'annee_id'], ['statut', 'date_inscription']);
            // Décocher un semestre le retire vraiment
            DB::table('inscriptions_pedagogiques')->where(['etudiant_id' => $etId, 'annee_id' => $annee, 'statut' => 'actif'])
                ->whereNotIn('semestre_id', $semestres)->update(['statut' => 'annule']);

            // Report des notes validées vers le PV normal de chaque semestre, puis recalcul
            $total = 0;
            foreach ($semestres as $s) {
                $pv = DB::table('pv_sessions')->where(['promotion_id' => $ins->promotion_id, 'semestre_id' => $s, 'type_session' => 'normale'])->orderBy('id')->value('id');
                if ($pv && ($n = $this->resultats->reporterNotes($etId, (int) $pv)) > 0) {
                    $total += $n;
                    $this->resultats->recalculer((int) $pv, $etId);
                }
            }

            return $total;
        });
        $this->journal->action('inscrire', 'inscription_pedagogique', "Inscription pédagogique — étudiant #$etId, ".count($semestres).' semestre(s) activé(s)', $etId);

        return redirect($retour)->with('succes', '✅ Inscription pédagogique enregistrée — '.count($semestres).' semestre(s) autorisé(s).'
            .($reportees > 0 ? " $reportees note(s) reportée(s) automatiquement." : ''));
    }

    /** Inscription de l'année active, sinon la plus récente. */
    private function inscriptionRetenue(int $etudiantId, int $anneeActive): ?object
    {
        $inscriptions = DB::table('inscriptions as i')->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')
            ->join('promotions as p', 'p.id', '=', 'i.promotion_id')->leftJoin('niveaux as np', 'np.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->where('i.etudiant_id', $etudiantId)->where('i.statut', 'inscrit')->orderByDesc('a.libelle')
            ->get(['i.id', 'p.annee_id', 'a.libelle as annee_lib', DB::raw('COALESCE(n.code, np.code) AS niveau_code')]);

        return $inscriptions->firstWhere('annee_id', $anneeActive) ?? $inscriptions->first();
    }

    private function etudiant(callable $condition): ?object
    {
        return DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->where(fn ($q) => $condition($q))->first(['e.*', 'f.libelle as filiere_lib', 'f.code as filiere_code']);
    }
}
