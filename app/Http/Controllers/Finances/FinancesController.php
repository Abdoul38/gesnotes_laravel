<?php

namespace App\Http\Controllers\Finances;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Paiements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Finances — portage de pages/finances/finances.php : aperçu (recettes,
 * dépenses, solde, graphiques), grille tarifaire, dépenses. Modifications
 * avec la permission « paiements_gerer ».
 *
 * Différences voulues :
 *  - recettes d'un agent de faculté calculées sans doublons (la jointure sur
 *    les inscriptions comptait un paiement autant de fois que l'étudiant
 *    avait d'inscriptions dans la faculté) ;
 *  - dépenses et catégories (communes à toute l'université) modifiables
 *    seulement par un compte sans restriction de faculté ;
 *  - tarifs limités aux filières de la faculté de l'agent, aussi à
 *    l'enregistrement et à la suppression ;
 *  - montants, dates, modes, catégories, années et longueurs contrôlés ;
 *  - modification des tarifs et des dépenses qui fonctionne (boutons en
 *    JavaScript en ligne bloqués par la CSP) ; graphiques servis localement.
 */
class FinancesController extends Controller
{
    private const ONGLETS = ['apercu', 'tarifs', 'depenses'];

    public const CATEGORIES_PREDEFINIES = ['Salaires', 'Loyer', 'Matériel pédagogique', 'Entretien & réparations', 'Électricité & eau',
        'Fournitures de bureau', 'Transport', 'Communication', 'Autre'];

    public function __construct(private readonly Paiements $paiements, private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $onglet = in_array($request->query('tab'), self::ONGLETS, true) ? $request->query('tab') : 'apercu';
        $annees = DB::table('annees_academiques')->orderByDesc('id')->get(['id', 'libelle', 'active']);
        $annee = (int) $request->query('annee_id') ?: ($annees->firstWhere('active', 1)->id ?? $annees->first()->id ?? 0);
        $faculte = $this->perimetre->faculte($u);
        $filieres = DB::table('filieres')->when($faculte, fn ($q) => $q->where('faculte_id', $faculte))->orderBy('libelle')->get(['id', 'libelle']);

        $donnees = [
            'onglet' => $onglet, 'annees' => $annees, 'annee' => $annee, 'filieres' => $filieres,
            'peutGerer' => $u->autorise('paiements_gerer'),
            'peutGererDepenses' => $u->autorise('paiements_gerer') && $faculte === 0,
        ];

        return view('finances.finances', $donnees + match ($onglet) {
            'apercu' => $this->apercu($u, $annee, $faculte),
            'tarifs' => [
                'tarifs' => DB::table('grille_tarifaire as gt')->join('filieres as f', 'f.id', '=', 'gt.filiere_id')->join('niveaux as n', 'n.id', '=', 'gt.niveau_id')
                    ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
                    ->orderBy('f.libelle')->orderBy('n.code')->orderBy('gt.type_paiement')->get(['gt.*', 'n.code as niveau_code'])->groupBy('filiere_id'),
                'niveaux' => DB::table('niveaux')->orderBy('code')->get(['id', 'code', 'libelle']),
            ],
            'depenses' => $this->depenses($request, $annee),
        });
    }

    private function apercu($u, int $annee, int $faculte): array
    {
        $recettes = fn () => $this->paiements->requete($u)->when($annee, fn ($q) => $q->where('p.annee_id', $annee));
        $depenses = fn () => DB::table('depenses')->when($annee, fn ($q) => $q->where('annee_id', $annee));
        $mois = date('Y-m');
        $totalRecettes = (float) $recettes()->sum('p.montant_paye');
        $totalDepenses = (float) $depenses()->sum('montant');

        // Évolution sur 12 mois glissants (indépendante de l'année académique choisie)
        $debut = date('Y-m-d', strtotime('-12 months'));
        $recettesMois = $this->paiements->requete($u)->where('p.date_paiement', '>=', $debut)
            ->groupByRaw("DATE_FORMAT(p.date_paiement,'%Y-%m')")->selectRaw("DATE_FORMAT(p.date_paiement,'%Y-%m') AS mois, SUM(p.montant_paye) AS total")->pluck('total', 'mois');
        $depensesMois = DB::table('depenses')->where('date_depense', '>=', $debut)
            ->groupByRaw("DATE_FORMAT(date_depense,'%Y-%m')")->selectRaw("DATE_FORMAT(date_depense,'%Y-%m') AS mois, SUM(montant) AS total")->pluck('total', 'mois');
        $noms = ['01' => 'Jan', '02' => 'Fév', '03' => 'Mar', '04' => 'Avr', '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Aoû', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Déc'];
        $serie = ['labels' => [], 'recettes' => [], 'depenses' => []];
        for ($i = 11; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("first day of -$i months"));
            [$a, $n] = explode('-', $m);
            $serie['labels'][] = $noms[$n].' '.substr($a, 2);
            $serie['recettes'][] = round((float) ($recettesMois[$m] ?? 0));
            $serie['depenses'][] = round((float) ($depensesMois[$m] ?? 0));
        }

        // Recettes par filière : celle de l'inscription de l'année du paiement, sinon l'inscription en cours (ancienne règle)
        $filiere = "COALESCE(
            (SELECT pr1.filiere_id FROM inscriptions i1 JOIN promotions pr1 ON pr1.id=i1.promotion_id
             WHERE i1.etudiant_id=p.etudiant_id AND p.annee_id IS NOT NULL AND pr1.annee_id=p.annee_id ORDER BY i1.id DESC LIMIT 1),
            (SELECT pr2.filiere_id FROM inscriptions i2 JOIN promotions pr2 ON pr2.id=i2.promotion_id
             WHERE i2.etudiant_id=p.etudiant_id AND i2.statut='inscrit' ORDER BY i2.id DESC LIMIT 1))";
        $anneePaiement = "COALESCE(p.annee_id, (SELECT pr4.annee_id FROM inscriptions i4 JOIN promotions pr4 ON pr4.id=i4.promotion_id
            WHERE i4.etudiant_id=p.etudiant_id AND i4.statut='inscrit' ORDER BY i4.id DESC LIMIT 1))";
        $parFiliere = DB::table('paiements as p')->join('filieres as f', 'f.id', '=', DB::raw($filiere))
            ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
            ->when($annee, fn ($q) => $q->whereRaw("$anneePaiement = ?", [$annee]))
            ->groupBy('f.id', 'f.libelle')->orderByDesc('total')->limit(10)
            ->get(['f.libelle', DB::raw('SUM(p.montant_paye) AS total')]);

        return [
            'totalRecettes' => $totalRecettes,
            'totalDepenses' => $totalDepenses,
            'resteAPercevoir' => (float) $recettes()->sum(DB::raw('p.montant_du - p.montant_paye')),
            'recettesMois' => (float) $recettes()->whereRaw("DATE_FORMAT(p.date_paiement,'%Y-%m') = ?", [$mois])->sum('p.montant_paye'),
            'depensesMois' => (float) $depenses()->whereRaw("DATE_FORMAT(date_depense,'%Y-%m') = ?", [$mois])->sum('montant'),
            'depensesParCategorie' => DB::table('depenses as d')->join('categories_depenses as c', 'c.id', '=', 'd.categorie_id')
                ->when($annee, fn ($q) => $q->where('d.annee_id', $annee))
                ->groupBy('c.id', 'c.libelle')->orderByDesc('total')->limit(8)->get(['c.libelle', DB::raw('SUM(d.montant) AS total')]),
            'recettesParFiliere' => $parFiliere,
            'serie' => $serie,
        ];
    }

    private function depenses(Request $request, int $annee): array
    {
        $categorie = (int) $request->query('categorie_id');
        $mois = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('mois')) ? (string) $request->query('mois') : '';
        $liste = DB::table('depenses as d')->join('categories_depenses as c', 'c.id', '=', 'd.categorie_id')
            ->when($annee, fn ($q) => $q->where('d.annee_id', $annee))
            ->when($categorie, fn ($q) => $q->where('d.categorie_id', $categorie))
            ->when($mois, fn ($q) => $q->whereRaw("DATE_FORMAT(d.date_depense,'%Y-%m') = ?", [$mois]))
            ->orderByDesc('d.date_depense')->orderByDesc('d.id')->limit(200)->get(['d.*', 'c.libelle as categorie']);

        return [
            'categories' => $this->categories(),
            'depensesListe' => $liste,
            'categorieFiltre' => $categorie,
            'moisFiltre' => $mois,
            'totalFiltre' => (float) $liste->sum('montant'),
            'totalMoisCourant' => (float) DB::table('depenses')->when($annee, fn ($q) => $q->where('annee_id', $annee))
                ->whereRaw("DATE_FORMAT(date_depense,'%Y-%m') = ?", [date('Y-m')])->sum('montant'),
        ];
    }

    /** Catégories, avec les catégories prédéfinies créées au besoin (ancien INSERT IGNORE). */
    private function categories()
    {
        $existantes = DB::table('categories_depenses')->pluck('libelle')->map(fn ($l) => mb_strtolower($l))->all();
        foreach (self::CATEGORIES_PREDEFINIES as $c) {
            if (! in_array(mb_strtolower($c), $existantes, true)) {
                DB::table('categories_depenses')->insertOrIgnore(['libelle' => $c, 'predefinie' => 1]);
            }
        }

        return DB::table('categories_depenses')->orderByDesc('predefinie')->orderBy('libelle')->get(['id', 'libelle']);
    }

    public function enregistrerTarif(Request $request): RedirectResponse
    {
        $retour = $this->retour($request, 'tarifs');
        $v = Validator::make($request->all(), [
            'filiere_id' => 'required|integer', 'niveau_id' => 'required|integer',
            'type_paiement' => 'required|in:'.implode(',', array_keys(Paiements::TYPES)),
            'montant' => 'required|numeric|min:0|max:'.Paiements::MONTANT_MAX,
        ], [], ['type_paiement' => 'type', 'filiere_id' => 'filière', 'niveau_id' => 'niveau']);
        if ($v->fails()) {
            return $retour->with('erreur', 'Tarif non enregistré : '.$v->errors()->first());
        }
        $d = $v->validated();
        if (! DB::table('filieres')->where('id', $d['filiere_id'])->exists() || ! $this->perimetre->filiere($request->user(), (int) $d['filiere_id'])
            || ! DB::table('niveaux')->where('id', $d['niveau_id'])->exists()) {
            return $retour->with('erreur', 'Filière ou niveau introuvable.');
        }
        DB::table('grille_tarifaire')->upsert([['filiere_id' => $d['filiere_id'], 'niveau_id' => $d['niveau_id'], 'type_paiement' => $d['type_paiement'],
            'montant' => $d['montant']]], ['filiere_id', 'niveau_id', 'type_paiement'], ['montant']);
        $this->journal->action('modifier', 'tarif', "Tarif filière #{$d['filiere_id']}, niveau #{$d['niveau_id']}, {$d['type_paiement']} : {$d['montant']} FCFA");

        return $retour->with('succes', 'Tarif enregistré.');
    }

    public function supprimerTarif(Request $request): RedirectResponse
    {
        $t = DB::table('grille_tarifaire')->find((int) $request->input('id'));
        if (! $t || ! $this->perimetre->filiere($request->user(), (int) $t->filiere_id)) {
            return $this->retour($request, 'tarifs')->with('erreur', 'Tarif introuvable.');
        }
        DB::table('grille_tarifaire')->where('id', $t->id)->delete();
        $this->journal->action('supprimer', 'tarif', "Tarif supprimé : filière #{$t->filiere_id}, niveau #{$t->niveau_id}, {$t->type_paiement} ({$t->montant} FCFA)");

        return $this->retour($request, 'tarifs')->with('succes', 'Tarif supprimé.');
    }

    public function ajouterCategorie(Request $request): RedirectResponse
    {
        if ($refus = $this->refusDepenses($request)) {
            return $refus;
        }
        $libelle = trim((string) $request->input('libelle_categorie'));
        if ($libelle === '' || mb_strlen($libelle) > 100) {
            return $this->retour($request, 'depenses')->with('erreur', 'Nom de catégorie invalide (1 à 100 caractères).');
        }
        DB::table('categories_depenses')->insertOrIgnore(['libelle' => $libelle, 'predefinie' => 0]);

        return $this->retour($request, 'depenses')->with('succes', 'Catégorie ajoutée.');
    }

    public function enregistrerDepense(Request $request): RedirectResponse
    {
        if ($refus = $this->refusDepenses($request)) {
            return $refus;
        }
        $retour = $this->retour($request, 'depenses');
        $modification = $request->input('action') === 'modifier';
        $existante = $modification ? DB::table('depenses')->find((int) $request->input('id')) : null;
        if ($modification && ! $existante) {
            return $retour->with('erreur', 'Dépense introuvable.');
        }
        $v = Validator::make($request->all(), [
            'categorie_id' => 'required|integer', 'annee_id' => 'nullable|integer',
            'libelle' => 'required|string|max:200',
            'montant' => 'required|numeric|min:0|max:'.Paiements::MONTANT_MAX,
            'date_depense' => 'required|date_format:Y-m-d',
            'mode_paiement' => 'required|in:'.implode(',', array_keys(Paiements::MODES)),
            'beneficiaire' => 'nullable|string|max:150', 'reference' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:2000',
        ], [], ['categorie_id' => 'catégorie', 'libelle' => 'libellé', 'date_depense' => 'date', 'mode_paiement' => 'mode']);
        if ($v->fails()) {
            return $retour->with('erreur', 'Dépense non enregistrée : '.$v->errors()->first());
        }
        $d = $v->validated();
        if (! DB::table('categories_depenses')->where('id', $d['categorie_id'])->exists()
            || (! empty($d['annee_id']) && ! DB::table('annees_academiques')->where('id', $d['annee_id'])->exists())) {
            return $retour->with('erreur', 'Catégorie ou année introuvable.');
        }
        $valeurs = ['categorie_id' => $d['categorie_id'], 'libelle' => trim($d['libelle']), 'montant' => $d['montant'], 'date_depense' => $d['date_depense'],
            'mode_paiement' => $d['mode_paiement'], 'beneficiaire' => trim($d['beneficiaire'] ?? '') ?: null, 'reference' => trim($d['reference'] ?? '') ?: null,
            'notes' => trim($d['notes'] ?? '') ?: null,
            'annee_id' => $d['annee_id'] ?? (DB::table('annees_academiques')->where('active', 1)->value('id') ?? DB::table('annees_academiques')->max('id'))];
        if ($modification) {
            DB::table('depenses')->where('id', $existante->id)->update($valeurs);
            $this->journal->action('modifier', 'depense', "Modification dépense #{$existante->id} : {$valeurs['libelle']} ({$existante->montant} → {$valeurs['montant']} FCFA)", (int) $existante->id);

            return $retour->with('succes', 'Dépense mise à jour.');
        }
        $id = DB::table('depenses')->insertGetId($valeurs + ['saisi_par' => $request->user()->id]);
        $this->journal->action('creer', 'depense', "Dépense enregistrée : {$valeurs['libelle']} — {$valeurs['montant']}", $id);

        return $retour->with('succes', 'Dépense enregistrée.');
    }

    public function supprimerDepense(Request $request): RedirectResponse
    {
        if ($refus = $this->refusDepenses($request)) {
            return $refus;
        }
        $d = DB::table('depenses')->find((int) $request->input('id'));
        if (! $d) {
            return $this->retour($request, 'depenses')->with('erreur', 'Dépense introuvable.');
        }
        DB::table('depenses')->where('id', $d->id)->delete();
        $this->journal->action('supprimer', 'depense', "Suppression dépense #{$d->id} : {$d->libelle} ({$d->montant} FCFA, {$d->date_depense})", (int) $d->id);

        return $this->retour($request, 'depenses')->with('succes', 'Dépense supprimée.');
    }

    /** Dépenses communes à toute l'université : réservées aux comptes sans restriction de faculté. */
    private function refusDepenses(Request $request): ?RedirectResponse
    {
        return $this->perimetre->faculte($request->user()) === 0 ? null
            : $this->retour($request, 'depenses')->with('erreur', "Les dépenses concernent toute l'université : elles se gèrent depuis un compte sans restriction de faculté.");
    }

    private function retour(Request $request, string $onglet): RedirectResponse
    {
        return redirect()->route('finances', ['tab' => $onglet, 'annee_id' => (int) $request->input('annee_id_filtre') ?: null]);
    }
}
