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
 * filtrée et paginée, totaux, export CSV ; ajout, modification, suppression
 * (permission « paiements_gerer »).
 *
 * Différences voulues :
 *  - ajout, modification et suppression limités aux étudiants de la
 *    faculté de l'agent (n'importe quel identifiant était accepté) ;
 *  - type, mode, année, montants (positifs, plafonnés), date et longueurs
 *    contrôlés (une valeur inconnue provoquait une erreur SQL) ;
 *  - modification qui fonctionne (le bouton, en JavaScript en ligne, était
 *    bloqué par la CSP) ; l'étudiant d'un paiement ne change pas ;
 *  - suppression journalisée avec le montant et l'étudiant ;
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
            return $this->csv((clone $requete)->orderByDesc('p.date_paiement')->orderByDesc('p.id')->get(['p.*', 'e.nom', 'e.prenoms', 'e.numero']));
        }

        $total = (clone $requete)->count();
        $pagination = Pagination::calculer($total, self::PAR_PAGE, (int) $request->query('pg', 1));
        $totaux = (clone $requete)->selectRaw('COALESCE(SUM(p.montant_du),0) AS du, COALESCE(SUM(p.montant_paye),0) AS paye')->first();
        $etudiant = $f['et_id'] ? DB::table('etudiants')->find($f['et_id']) : null;
        $annees = DB::table('annees_academiques')->orderByDesc('id')->get(['id', 'libelle']);
        $peutGerer = $u->autorise('paiements_gerer');

        return view('finances.paiements', [
            'paiements' => $requete->orderByDesc('p.date_paiement')->orderByDesc('p.id')->offset($pagination['offset'])->limit(self::PAR_PAGE)
                ->get(['p.*', 'e.nom', 'e.prenoms', 'e.numero']),
            'total' => $total,
            'pagination' => $pagination,
            'totalDu' => (float) $totaux->du,
            'totalPaye' => (float) $totaux->paye,
            'nbRetard' => $this->paiements->requete($u)->whereIn('p.statut', ['en_attente', 'partiel'])
                ->where('p.created_at', '<', now()->subDays(30))->count(),
            'filtres' => $f,
            'etudiant' => $f['et_id'] && $this->paiements->etudiantAutorise($u, $f['et_id']) ? $etudiant : null,
            'annees' => $annees,
            'anneeDefaut' => $annees->first()->id ?? null,
            'peutGerer' => $peutGerer,
            'etudiants' => $peutGerer && ! $f['et_id'] ? $this->etudiantsPourSaisie($request) : collect(),
            'grille' => $peutGerer ? DB::table('grille_tarifaire')->get(['filiere_id', 'niveau_id', 'type_paiement', 'montant'])
                ->mapWithKeys(fn ($t) => ["{$t->filiere_id}_{$t->niveau_id}_{$t->type_paiement}" => (float) $t->montant]) : collect(),
            'inscriptionEtudiant' => $f['et_id'] ? $this->derniereInscription($f['et_id']) : null,
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

    public function supprimer(Request $request): RedirectResponse
    {
        $p = $this->paiements->trouver($request->user(), (int) $request->input('id'));
        $retour = redirect()->route('paiements', array_filter(['et_id' => (int) $request->input('retour_et_id')]));
        if (! $p) {
            return $retour->with('erreur', 'Paiement introuvable.');
        }
        DB::table('paiements')->where('id', $p->id)->delete();
        $this->journal->action('supprimer', 'paiement', "Suppression paiement #{$p->id} ({$p->type_paiement}, {$p->montant_paye}/{$p->montant_du} FCFA, {$p->statut}) — {$p->nom} {$p->prenoms} ({$p->numero})", (int) $p->id);

        return $retour->with('succes', 'Paiement supprimé.');
    }

    private function filtres(Request $request): array
    {
        $date = fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($k)) ? $request->query($k) : '';
        $type = (string) $request->query('type_paiement', '');
        $statut = (string) $request->query('statut', '');

        return [
            'et_id' => (int) $request->query('et_id'),
            'annee_id' => (int) $request->query('annee_id'),
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
            fputcsv($out, ['Étudiant', 'Matricule', 'Type', 'Montant dû', 'Montant payé', 'Reste', 'Date', 'Mode', 'Référence', 'Statut'], ';');
            foreach ($lignes as $r) {
                fputcsv($out, array_map($cellule, [
                    $r->nom.' '.$r->prenoms, $r->numero, $r->type_paiement, $r->montant_du, $r->montant_paye,
                    (float) $r->montant_du - (float) $r->montant_paye, $r->date_paiement, $r->mode_paiement, $r->reference, $r->statut,
                ]), ';');
            }
            fclose($out);
        }, 'paiements_'.date('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
