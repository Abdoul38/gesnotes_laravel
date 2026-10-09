<?php

namespace App\Http\Controllers\Finances;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Paiements;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Suivi des paiements — portage de pages/finances/paiements.php : liste
 * filtrée et paginée, totaux, export CSV ; ajout, modification, annulation
 * (permission « paiements_gerer »).
 *
 * Différences voulues :
 *  - ajout, modification et annulation limités aux étudiants de la
 *    faculté de l'agent (n'importe quel identifiant était accepté) ;
 *  - type, mode, année, montants (positifs, plafonnés), date et longueurs
 *    contrôlés (une valeur inconnue provoquait une erreur SQL) ;
 *  - modification qui fonctionne (le bouton, en JavaScript en ligne, était
 *    bloqué par la CSP) ; l'étudiant d'un paiement ne change pas ;
 *  - plus de suppression : annulation avec motif obligatoire, le paiement
 *    est archivé dans paiements_annules (agent, date, ligne complète) ;
 *  - export CSV protégé contre l'injection de formules (une cellule
 *    commençant par =, +, - ou @ était exécutée par Excel) ;
 *  - compteur « en retard » limité au périmètre de l'agent.
 */
class PaiementsController extends Controller
{
    private const PAR_PAGE = 50;

    public function __construct(private readonly Paiements $paiements, private readonly Journal $journal) {}

    public function index(Request $request): View|StreamedResponse
    {
        $u = $request->user();
        $f = $this->filtres($request);
        $requete = $this->filtrer($this->paiements->requete($u), $f);

        if ($request->query('export') === 'csv') {
            return $this->csv($this->avecAnneeEtNiveau(clone $requete)->orderByDesc('p.date_paiement')->orderByDesc('p.id')->get());
        }

        $total = (clone $requete)->count();
        $pagination = Pagination::calculer($total, self::PAR_PAGE, (int) $request->query('pg', 1));
        $totaux = (clone $requete)->selectRaw('COALESCE(SUM(p.montant_du),0) AS du, COALESCE(SUM(p.montant_paye),0) AS paye')->first();
        $etudiant = $f['et_id'] ? DB::table('etudiants')->find($f['et_id']) : null;
        $annees = DB::table('annees_academiques')->orderByDesc('id')->get(['id', 'libelle']);
        $peutGerer = $u->autorise('paiements_gerer');

        return view('finances.paiements', [
            'paiements' => $this->avecAnneeEtNiveau($requete)->orderByDesc('p.date_paiement')->orderByDesc('p.id')->offset($pagination['offset'])->limit(self::PAR_PAGE)
                ->get(),
            'total' => $total,
            'pagination' => $pagination,
            'totalDu' => (float) $totaux->du,
            'totalPaye' => (float) $totaux->paye,
            'nbRetard' => $this->paiements->requete($u)->whereIn('p.statut', ['en_attente', 'partiel'])
                ->where('p.created_at', '<', now()->subDays(30))->count(),
            'filtres' => $f,
            'etudiant' => $f['et_id'] && $this->paiements->etudiantAutorise($u, $f['et_id']) ? $etudiant : null,
            'annees' => $annees,
            'anneeDefaut' => $this->anneeActive() ?: ($annees->first()->id ?? null),
            'anneeActive' => $this->anneeActive(),
            'peutGerer' => $peutGerer,
            'etudiants' => $peutGerer && ! $f['et_id'] ? $this->etudiantsPourSaisie($request) : collect(),
            'grille' => $peutGerer ? DB::table('grille_tarifaire')->get(['filiere_id', 'niveau_id', 'type_paiement', 'montant'])
                ->mapWithKeys(fn ($t) => ["{$t->filiere_id}_{$t->niveau_id}_{$t->type_paiement}" => (float) $t->montant]) : collect(),
            'inscriptionEtudiant' => $f['et_id'] ? $this->derniereInscription($f['et_id']) : null,
            'annules' => $f['et_id'] && $this->paiements->etudiantAutorise($u, $f['et_id'])
                ? DB::table('paiements_annules as a')->leftJoin('utilisateurs as us', 'us.id', '=', 'a.annule_par')
                    ->where('a.etudiant_id', $f['et_id'])->orderByDesc('a.annule_le')->get(['a.*', 'us.nom as agent_nom', 'us.prenoms as agent_prenom'])
                : collect(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $u = $request->user();
        $modification = $request->input('action') === 'modifier';
        $retour = redirect()->route('paiements', array_filter(['et_id' => (int) $request->input('retour_et_id')]));
        $existant = $modification ? $this->paiements->trouver($u, (int) $request->input('id')) : null;
        if ($modification && ! $existant) {
            return $retour->with('erreur', 'Paiement introuvable.');
        }
        $v = Validator::make($request->all(), [
            'etudiant_id' => $modification ? 'nullable' : 'required|integer',
            'type_paiement' => 'required|in:'.implode(',', array_keys(Paiements::TYPES)),
            'mode_paiement' => 'required|in:'.implode(',', array_keys(Paiements::MODES)),
            'annee_id' => 'nullable|integer',
            'montant_du' => 'required|numeric|min:0|max:'.Paiements::MONTANT_MAX,
            'montant_paye' => 'required|numeric|min:0|max:'.Paiements::MONTANT_MAX,
            'date_paiement' => 'nullable|date_format:Y-m-d',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
        ], [], [
            'etudiant_id' => 'étudiant', 'type_paiement' => 'type', 'mode_paiement' => 'mode', 'annee_id' => 'année',
            'montant_du' => 'montant dû', 'montant_paye' => 'montant payé', 'date_paiement' => 'date',
        ]);
        if ($v->fails()) {
            return $retour->with('erreur', 'Paiement non enregistré : '.$v->errors()->first());
        }
        $d = $v->validated();
        if (! empty($d['annee_id']) && ! DB::table('annees_academiques')->where('id', $d['annee_id'])->exists()) {
            return $retour->with('erreur', 'Paiement non enregistré : année académique inconnue.');
        }
        $etudiantId = $modification ? (int) $existant->etudiant_id : (int) $d['etudiant_id'];
        if (! $modification && ! $this->paiements->etudiantAutorise($u, $etudiantId)) {
            return $retour->with('erreur', 'Étudiant introuvable.');
        }
        $du = (float) $d['montant_du'];
        $paye = (float) $d['montant_paye'];
        $valeurs = [
            'type_paiement' => $d['type_paiement'], 'montant_du' => $du, 'montant_paye' => $paye,
            'date_paiement' => $d['date_paiement'] ?? ($modification ? $existant->date_paiement : date('Y-m-d')),
            'mode_paiement' => $d['mode_paiement'], 'reference' => trim($d['reference'] ?? '') ?: null,
            'statut' => Paiements::statut($du, $paye), 'notes' => trim($d['notes'] ?? '') ?: null,
            'annee_id' => $d['annee_id'] ?? DB::table('annees_academiques')->max('id'),
        ];

        if ($modification) {
            DB::table('paiements')->where('id', $existant->id)->update($valeurs);
            $this->journal->action('modifier', 'paiement', "Modification paiement #{$existant->id} : {$existant->montant_paye}/{$existant->montant_du} → $paye/$du", (int) $existant->id);

            return $retour->with('succes', 'Paiement mis à jour.');
        }
        $id = DB::table('paiements')->insertGetId($valeurs + ['etudiant_id' => $etudiantId, 'saisi_par' => $u->id]);
        $this->journal->action('creer', 'paiement', "Paiement enregistré : $paye/$du — étudiant #$etudiantId", $id);

        return $retour->with('succes', 'Paiement enregistré.');
    }

    /**
     * Annulation (remplace la suppression) : le paiement sort des totaux et des
     * reçus, mais il est conservé dans paiements_annules avec son motif, l'agent
     * et la date — un encaissement ne peut plus disparaître sans trace.
     */
    public function annuler(Request $request): RedirectResponse
    {
        $u = $request->user();
        $p = $this->paiements->trouver($u, (int) $request->input('id'));
        $retour = redirect()->route('paiements', array_filter(['et_id' => (int) $request->input('retour_et_id')]));
        if (! $p) {
            return $retour->with('erreur', 'Paiement introuvable.');
        }
        $motif = trim((string) $request->input('motif'));
        if (mb_strlen($motif) < 5 || mb_strlen($motif) > 500) {
            return $retour->with('erreur', "Indiquez le motif de l'annulation (5 à 500 caractères).");
        }
        $ligne = (array) DB::table('paiements')->where('id', $p->id)->first();
        DB::transaction(function () use ($ligne, $motif, $u) {
            DB::table('paiements_annules')->insert([
                'paiement_id' => $ligne['id'], 'etudiant_id' => $ligne['etudiant_id'], 'annee_id' => $ligne['annee_id'],
                'type_paiement' => $ligne['type_paiement'], 'montant_du' => $ligne['montant_du'], 'montant_paye' => $ligne['montant_paye'],
                'date_paiement' => $ligne['date_paiement'], 'mode_paiement' => $ligne['mode_paiement'], 'reference' => $ligne['reference'],
                'statut' => $ligne['statut'], 'saisi_par' => $ligne['saisi_par'], 'donnees' => json_encode($ligne, JSON_UNESCAPED_UNICODE),
                'motif' => $motif, 'annule_par' => $u->id,
            ]);
            DB::table('paiements')->where('id', $ligne['id'])->delete();
        });
        $this->journal->action('annuler', 'paiement', "Annulation paiement #{$p->id} ({$p->type_paiement}, {$p->montant_paye}/{$p->montant_du} FCFA, {$p->statut}) — {$p->nom} {$p->prenoms} ({$p->numero}) — motif : $motif", (int) $p->id);

        return $retour->with('succes', "Paiement annulé (conservé dans l'historique des annulations).");
    }

    private function filtres(Request $request): array
    {
        $date = fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($k)) ? $request->query($k) : '';
        $type = (string) $request->query('type_paiement', '');
        $statut = (string) $request->query('statut', '');

        return [
            'et_id' => (int) $request->query('et_id'),
            // Année active par défaut ; « Toutes » (0) reste possible en le choisissant
            'annee_id' => $request->has('annee_id') ? (int) $request->query('annee_id') : $this->anneeActive(),
            'type_paiement' => isset(Paiements::TYPES[$type]) ? $type : '',
            'statut' => isset(Paiements::STATUTS[$statut]) ? $statut : '',
            'q' => trim((string) $request->query('q', '')),
            'date_debut' => $date('date_debut'),
            'date_fin' => $date('date_fin'),
        ];
    }

    private function filtrer($q, array $f)
    {
        return $q
            ->when($f['et_id'], fn ($r, $v) => $r->where('p.etudiant_id', $v))
            ->when($f['annee_id'], fn ($r, $v) => $r->where('p.annee_id', $v))
            ->when($f['type_paiement'], fn ($r, $v) => $r->where('p.type_paiement', $v))
            ->when($f['statut'], fn ($r, $v) => $r->where('p.statut', $v))
            ->when($f['date_debut'], fn ($r, $v) => $r->where('p.date_paiement', '>=', $v))
            ->when($f['date_fin'], fn ($r, $v) => $r->where('p.date_paiement', '<=', $v))
            ->when($f['q'] !== '', fn ($r) => $r->where(fn ($w) => $w->where('e.nom', 'like', "%{$f['q']}%")->orWhere('e.prenoms', 'like', "%{$f['q']}%")
                ->orWhere('e.numero', 'like', "%{$f['q']}%")->orWhere('p.reference', 'like', "%{$f['q']}%")));
    }

    private function anneeActive(): int
    {
        return (int) DB::table('annees_academiques')->where('active', 1)->value('id');
    }

    /**
     * Colonnes de la liste et de l'export : paiement, étudiant, année académique
     * du paiement, filière et niveau de l'étudiant cette année-là (inscription de
     * l'année du paiement, à défaut sa dernière inscription).
     */
    private function avecAnneeEtNiveau($requete)
    {
        $inscription = fn (string $colonne, bool $memeAnnee) => "SELECT $colonne FROM inscriptions i JOIN promotions pr ON pr.id = i.promotion_id
            JOIN niveaux n ON n.id = pr.niveau_id JOIN filieres f ON f.id = pr.filiere_id
            WHERE i.etudiant_id = p.etudiant_id".($memeAnnee ? ' AND pr.annee_id = p.annee_id' : '').' ORDER BY i.id DESC LIMIT 1';
        $colonne = fn (string $expr, string $alias) => DB::raw('COALESCE(('.$inscription($expr, true).'), ('.$inscription($expr, false).")) AS $alias");

        return $requete->leftJoin('annees_academiques as an', 'an.id', '=', 'p.annee_id')
            ->select(['p.*', 'e.nom', 'e.prenoms', 'e.numero', 'an.libelle as annee',
                $colonne('f.libelle', 'filiere'), $colonne('f.code', 'filiere_code'), $colonne('n.code', 'niveau')]);
    }

    /** Étudiants proposés à la saisie, avec la filière / le niveau de leur dernière inscription (suggestion du montant). */
    private function etudiantsPourSaisie(Request $request)
    {
        $faculte = app(Perimetre::class)->faculte($request->user());
        $derniere = fn (string $col) => DB::raw("(SELECT pr3.$col FROM inscriptions i3 JOIN promotions pr3 ON pr3.id=i3.promotion_id
            WHERE i3.etudiant_id=e.id AND i3.statut='inscrit' ORDER BY i3.id DESC LIMIT 1) AS $col");

        return DB::table('etudiants as e')
            ->when($faculte, fn ($q) => $q->whereExists(fn ($s) => $s->from('inscriptions as i')->join('promotions as pr', 'pr.id', '=', 'i.promotion_id')
                ->join('filieres as f', 'f.id', '=', 'pr.filiere_id')->whereColumn('i.etudiant_id', 'e.id')->where('f.faculte_id', $faculte)))
            ->orderBy('e.nom')->orderBy('e.prenoms')
            ->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero', $derniere('filiere_id'), $derniere('niveau_id')]);
    }

    private function derniereInscription(int $etudiantId): ?object
    {
        return DB::table('inscriptions as i')->join('promotions as pr', 'pr.id', '=', 'i.promotion_id')
            ->where('i.etudiant_id', $etudiantId)->where('i.statut', 'inscrit')->orderByDesc('i.id')->first(['pr.filiere_id', 'pr.niveau_id']);
    }

    private function csv($lignes): StreamedResponse
    {
        // Une cellule commençant par = + - @ (ou tabulation / retour) serait exécutée comme formule par Excel
        $cellule = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($lignes, $cellule) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Étudiant', 'Matricule', 'Année académique', 'Filière', 'Niveau', 'Type', 'Montant dû', 'Montant payé', 'Reste', 'Date', 'Mode', 'Référence', 'Statut'], ';');
            foreach ($lignes as $r) {
                fputcsv($out, array_map($cellule, [
                    $r->nom.' '.$r->prenoms, $r->numero, $r->annee, $r->filiere, $r->niveau, $r->type_paiement, $r->montant_du, $r->montant_paye,
                    (float) $r->montant_du - (float) $r->montant_paye, $r->date_paiement, $r->mode_paiement, $r->reference, $r->statut,
                ]), ';');
            }
            fclose($out);
        }, 'paiements_'.date('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
