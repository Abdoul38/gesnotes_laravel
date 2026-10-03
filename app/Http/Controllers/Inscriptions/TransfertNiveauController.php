<?php

namespace App\Http\Controllers\Inscriptions;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Fichiers;
use App\Services\Inscriptions;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Transfert de filière et décision de passage individuelle — portage de
 * pages/inscriptions/transfert_niveau.php (admin, secrétaire, sc_centrale,
 * sc_entite). Le passage enregistre seulement une décision ; l'inscription
 * se fait ensuite par l'inscription scolarité.
 *
 * Différences voulues :
 *  - règle de décision commune avec le passage en masse
 *    (Inscriptions::decisionPassage) : la licence validée est enregistrée
 *    comme telle, les parcours IUT (DUT, LP) sont gérés ;
 *  - la filière de spécialisation choisie doit faire partie de celles
 *    proposées ; la nouvelle année doit exister et différer de l'actuelle ;
 *  - transfert : filière active exigée, limité à la faculté de l'agent
 *    (inscription actuelle et filière visée), en une transaction ;
 *  - décisions et transferts journalisés.
 */
class TransfertNiveauController extends Controller
{
    public function __construct(
        private readonly Inscriptions $inscriptions,
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

        $donnees = [
            'recherche' => $recherche,
            'cherche' => $recherche !== '' || $request->filled('et_id'),
            'etudiant' => $etudiant,
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'anneeActive' => (int) DB::table('annees_academiques')->where('active', 1)->value('id'),
            'filieres' => DB::table('filieres')->where('actif', 1)->orderBy('faculte')->orderBy('libelle')->get(['id', 'libelle', 'faculte']),
        ];
        if (! $etudiant) {
            return view('inscriptions.transfert', $donnees);
        }

        $inscriptions = DB::table('inscriptions as i')->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')
            ->leftJoin('annees_academiques as a', 'a.id', '=', 'i.annee_id')->leftJoin('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->leftJoin('filieres as f', 'f.id', '=', 'p.filiere_id')->where('i.etudiant_id', $etudiant->id)->orderByDesc('a.libelle')
            ->get(['i.*', 'n.code as niveau_code', 'a.libelle as annee_lib', 'a.active', 'f.libelle as filiere_lib']);
        $derniere = $inscriptions->first();
        $valides = $this->inscriptions->semestresValides($etudiant->id);
        [$suivant, , $statut] = $derniere ? Inscriptions::decisionPassage((string) $derniere->niveau_code, $valides) : [null, null, null];

        return view('inscriptions.transfert', $donnees + [
            'photo' => $etudiant->photo && is_file($this->fichiers->dossier('photos').DIRECTORY_SEPARATOR.basename($etudiant->photo))
                ? route('fichiers.photo', basename($etudiant->photo)) : null,
            'inscriptions' => $inscriptions,
            'derniere' => $derniere,
            'valides' => $valides,
            'suivant' => $suivant,
            'statut' => $statut,
            'specialisations' => in_array($suivant, ['L3', 'LP'], true) && $etudiant->filiere_id
                ? $this->inscriptions->filieresSpecialisation((int) $etudiant->filiere_id, $suivant) : collect(),
        ]);
    }

    public function passage(Request $request): RedirectResponse
    {
        $etId = (int) $request->input('etudiant_id');
        $nouvelleAnnee = (int) $request->input('nouvel_annee_id');
        $retour = route('transfert_niveau', ['et_id' => $etId]);

        $ins = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')->join('niveaux as n', 'n.id', '=', 'i.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'i.annee_id')->where('i.etudiant_id', $etId)->where('i.statut', 'inscrit')
            ->orderByDesc('a.libelle')->first(['i.niveau_id', 'i.annee_id', 'i.promotion_id', 'p.filiere_id', 'n.code as niv_code']);
        if (! $etId || ! $nouvelleAnnee || ! DB::table('annees_academiques')->where('id', $nouvelleAnnee)->exists()) {
            return redirect($retour)->with('erreur', 'Données manquantes.');
        }
        if (! $ins) {
            return redirect($retour)->with('erreur', 'Aucune inscription active trouvée pour cet étudiant.');
        }
        if ((int) $ins->annee_id === $nouvelleAnnee) {
            return redirect($retour)->with('erreur', "La nouvelle année doit être différente de l'année de l'inscription actuelle.");
        }
        if (! $this->perimetre->promotion($request->user(), (int) $ins->promotion_id)) {
            return redirect($retour)->with('erreur', "Cet étudiant n'appartient pas à votre faculté.");
        }

        [$suivant, $decision] = Inscriptions::decisionPassage($ins->niv_code, $this->inscriptions->semestresValides($etId));
        $niveauSuivant = DB::table('niveaux')->where('code', $suivant)->value('id');
        if (! $niveauSuivant) {
            return redirect($retour)->with('erreur', "Niveau cible « $suivant » introuvable dans la base.");
        }
        $filiere = (int) $ins->filiere_id;
        $choisie = (int) $request->input('filiere_l3_id');
        if ($choisie && in_array($suivant, ['L3', 'LP'], true)) {
            if (! $this->inscriptions->filieresSpecialisation($filiere, $suivant)->contains('id', $choisie)) {
                return redirect($retour)->with('erreur', "Cette filière n'est pas proposée pour ce passage.");
            }
            $filiere = $choisie;
        }

        $this->inscriptions->enregistrerPassage($etId, $filiere, (int) $ins->niveau_id, (int) $niveauSuivant, (int) $ins->annee_id, $nouvelleAnnee, $decision);
        $this->journal->action('modifier', 'passage_niveau', "Décision de passage : $decision vers $suivant", $etId);
        $et = DB::table('etudiants')->where('id', $etId)->first(['nom', 'prenoms']);
        $libelle = $decision === 'passage' ? "📈 Passage → $suivant" : ($decision === 'licence_validee' ? "🎓 Licence validée ($suivant)" : "🔄 Redoublement $suivant");

        return redirect($retour)->with('succes', "✅ Décision enregistrée pour {$et->nom} {$et->prenoms} : $libelle. L'inscription académique se fait via Inscription Scolarité.");
    }

    public function transferer(Request $request): RedirectResponse
    {
        $u = $request->user();
        $etId = (int) $request->input('etudiant_id');
        $filiereId = (int) $request->input('nouvelle_filiere_id');
        $anneeId = (int) $request->input('annee_id');
        $retour = route('transfert_niveau', ['et_id' => $etId]);

        $et = DB::table('etudiants')->where('id', $etId)->first();
        $erreurs = array_filter([
            ! $et ? 'Étudiant introuvable.' : null,
            ! $filiereId || ! DB::table('filieres')->where('id', $filiereId)->where('actif', 1)->exists() ? 'La nouvelle filière est obligatoire.' : null,
            ! $anneeId || ! DB::table('annees_academiques')->where('id', $anneeId)->exists() ? "L'année académique est obligatoire." : null,
        ]);
        if ($erreurs) {
            return redirect($retour)->with('erreur', implode(' ', $erreurs));
        }
        $ins = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->where(['i.etudiant_id' => $etId, 'i.annee_id' => $anneeId, 'i.statut' => 'inscrit'])->orderByDesc('i.id')
            ->first(['i.id', 'i.promotion_id', 'p.niveau_id']);
        if (! $this->perimetre->filiere($u, $filiereId) || ($ins && ! $this->perimetre->promotion($u, (int) $ins->promotion_id))) {
            return redirect($retour)->with('erreur', "Cet étudiant ou cette filière n'appartient pas à votre faculté.");
        }

        DB::transaction(function () use ($etId, $filiereId, $anneeId, $ins) {
            DB::table('etudiants')->where('id', $etId)->update(['filiere_id' => $filiereId]);
            if ($ins) {
                // L'inscription existante change de promotion (pas de nouvelle inscription)
                DB::table('inscriptions')->where('id', $ins->id)->update(['promotion_id' => $this->inscriptions->promotion($filiereId, (int) $ins->niveau_id, $anneeId)]);
            }
        });
        $libelle = DB::table('filieres')->where('id', $filiereId)->value('libelle');
        $this->journal->action('modifier', 'transfert_filiere', "Transfert vers la filière $libelle", $etId);

        return redirect()->route('dossier_etudiant', ['id' => $etId])
            ->with('succes', "✅ Transfert de {$et->nom} {$et->prenoms} vers $libelle effectué. L'inscription existante a été mise à jour.");
    }

    private function etudiant(callable $condition): ?object
    {
        return DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->where(fn ($q) => $condition($q))->first(['e.*', 'f.libelle as filiere_lib']);
    }
}
