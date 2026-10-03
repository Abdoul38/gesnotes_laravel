<?php

namespace App\Http\Controllers\Etudiants;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Fichiers;
use App\Services\Journal;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Liste des étudiants — portage de pages/etudiants/etudiants.php,
 * suppression (ancien etudiant_form.php?action=supprimer) et API de recherche.
 *
 * Non repris, sur décision de l'université :
 *  - le bouton « Supprimer matricule 0 » (effaçait tous les étudiants sans
 *    matricule, y compris ceux créés et pas encore inscrits) ;
 *  - la fenêtre « Inscrire un étudiant », qu'aucun bouton n'ouvrait.
 */
class EtudiantsController extends Controller
{
    private const PAR_PAGE = 50;

    public function index(Request $request, Perimetre $perimetre, Fichiers $fichiers): View
    {
        $u = $request->user();
        $faculte = $perimetre->faculte($u);
        $promoId = (int) $request->query('promo');
        $q = trim((string) $request->query('q'));

        $promos = DB::table('promotions as p')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->when($faculte, fn ($r) => $r->where('f.faculte_id', $faculte))
            ->orderByDesc('a.libelle')->orderBy('n.code')
            ->get(['p.id', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']);

        $requete = DB::table('etudiants as e');
        if ($promoId) {
            $requete->join('inscriptions as i', fn ($j) => $j->on('i.etudiant_id', '=', 'e.id')->where('i.promotion_id', $promoId)->where('i.statut', 'inscrit'));
        }
        if ($q !== '') {
            $requete->where(fn ($w) => $w->where('e.nom', 'like', "%$q%")->orWhere('e.prenoms', 'like', "%$q%")
                ->orWhere('e.numero', 'like', "%$q%")->orWhere('e.numero_dossier', 'like', "%$q%")->orWhere('e.numero_depot', 'like', "%$q%"));
        }
        // Liste limitée à la faculté de l'utilisateur, mais une RECHERCHE
        // porte sur toutes les facultés (choix de l'ancienne page).
        if ($faculte && $q === '') {
            $requete->whereExists(fn ($s) => $s->from('inscriptions as ix2')
                ->join('promotions as px', 'px.id', '=', 'ix2.promotion_id')->join('filieres as fx', 'fx.id', '=', 'px.filiere_id')
                ->whereColumn('ix2.etudiant_id', 'e.id')->where('fx.faculte_id', $faculte));
        }

        $pag = Pagination::calculer((clone $requete)->distinct()->count('e.id'), self::PAR_PAGE, (int) $request->query('p', 1));
        $etudiants = $requete->distinct()->select('e.*')
            ->selectRaw("(SELECT GROUP_CONCAT(CONCAT(f.code,'/',n.code,'/',a.libelle) ORDER BY a.libelle DESC SEPARATOR ', ')
                FROM inscriptions i2 JOIN promotions p ON p.id=i2.promotion_id JOIN filieres f ON f.id=p.filiere_id
                JOIN niveaux n ON n.id=p.niveau_id JOIN annees_academiques a ON a.id=p.annee_id
                WHERE i2.etudiant_id=e.id AND i2.statut='inscrit') AS promotions_str")
            ->selectRaw("EXISTS (SELECT 1 FROM inscriptions ix WHERE ix.etudiant_id=e.id AND ix.statut='inscrit') AS est_inscrit")
            ->orderByRaw('CAST(e.numero AS UNSIGNED) DESC')->orderByDesc('e.id')
            ->limit(self::PAR_PAGE)->offset($pag['offset'])->get();

        $dossierPhotos = $fichiers->dossier('photos');
        foreach ($etudiants as $e) {
            $e->photo_fichier = ($e->photo && is_file($dossierPhotos.DIRECTORY_SEPARATOR.basename($e->photo))) ? basename($e->photo) : null;
        }

        return view('etudiants.liste', [
            'etudiants' => $etudiants, 'promos' => $promos, 'pag' => $pag,
            'promoId' => $promoId, 'q' => $q,
            'faculte' => $faculte ? DB::table('facultes')->where('id', $faculte)->first() : null,
            'nbSansMatricule' => DB::table('etudiants')->where(fn ($w) => $w->whereNull('numero')->orWhereIn('numero', ['', '0']))->count(),
            // Ajouter / importer : secrétaire ou admin, hors rôles en lecture seule
            'peutGerer' => $u->aRole('secretaire') && ! in_array($u->role, ['enseignant', 'sc_entite', 'sc_centrale'], true),
            'peutModifier' => $u->aRole('admin') || $u->aRole('sc_centrale'),
        ]);
    }

    /**
     * Suppression (admin). Refusée si l'étudiant a des notes, résultats ou
     * paiements : la cascade les effacerait (décision de l'université).
     */
    public function supprimer(Request $request, Journal $journal): RedirectResponse
    {
        abort_unless($request->user()->aRole('admin'), 403);
        $id = (int) $request->input('id');
        $etudiant = DB::table('etudiants')->where('id', $id)->first();
        if (! $etudiant) {
            return redirect()->route('etudiants')->with('erreur', 'Étudiant introuvable');
        }

        $historique = array_filter([
            'note(s)' => DB::table('notes')->where('etudiant_id', $id)->count(),
            'résultat(s)' => DB::table('resultats_semestre')->where('etudiant_id', $id)->count() + DB::table('resultats_ue')->where('etudiant_id', $id)->count(),
            'paiement(s)' => DB::table('paiements')->where('etudiant_id', $id)->count(),
        ]);
        if ($historique) {
            $detail = implode(', ', array_map(fn ($n, $quoi) => "$n $quoi", $historique, array_keys($historique)));

            return redirect()->route('etudiant_form', ['id' => $id])->with('erreur',
                "Suppression impossible : cet étudiant a un historique ($detail). Passez plutôt son statut à « Abandonné ».");
        }

        DB::table('etudiants')->where('id', $id)->delete();
        $journal->action('supprimer', 'etudiant', "Suppression étudiant : {$etudiant->nom} {$etudiant->prenoms}", $id);

        return redirect()->route('etudiants')->with('succes', 'Étudiant supprimé.');
    }

    /** Ancien api_etudiants.php (messagerie, e-mails) — qui échouait toujours (paramètre :q répété). */
    public function rechercher(Request $request): JsonResponse
    {
        return response()->json($this->recherche($request, ['id', 'nom', 'prenoms', 'numero', 'email'], 10));
    }

    /** Ancien api_recherche_etudiants.php (comptes parents). */
    public function rechercherCourt(Request $request): JsonResponse
    {
        return response()->json($this->recherche($request, ['id', 'nom', 'prenoms', 'numero'], 15), options: JSON_UNESCAPED_UNICODE);
    }

    private function recherche(Request $request, array $colonnes, int $limite): array
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return [];
        }

        return DB::table('etudiants')
            ->where(fn ($w) => $w->where('nom', 'like', "%$q%")->orWhere('prenoms', 'like', "%$q%")->orWhere('numero', 'like', "%$q%"))
            ->orderBy('nom')->orderBy('prenoms')->limit($limite)->get($colonnes)->all();
    }
}
