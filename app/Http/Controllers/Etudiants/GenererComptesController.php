<?php

namespace App\Http\Controllers\Etudiants;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Universites\UniversiteCourante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Génération des comptes étudiants en masse — portage de
 * pages/etudiants/generer_comptes_etudiants.php (admin / sc_centrale).
 * Identifiant : prenom.nom.matricule@<code>.ne ; mot de passe initial :
 * le matricule (règle de l'université, voir README « Décisions à prendre »).
 *
 * Différences voulues :
 *  - un étudiant SANS matricule n'a plus de compte au mot de passe vide :
 *    il est signalé en erreur ;
 *  - domaine de l'identifiant d'après l'université (udh.ne pour l'UDH,
 *    comme avant ; uam.ne pour l'UAM… au lieu de udh.ne partout) ;
 *  - identifiant déjà pris : suffixe placé avant le « @ » (l'ancien
 *    « …@udh.ne_00012 » n'était pas une adresse valide) ;
 *  - la promotion doit appartenir à l'année choisie ; message technique
 *    d'erreur non affiché ; génération journalisée.
 */
class GenererComptesController extends Controller
{
    public function __construct(private readonly UniversiteCourante $universite, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        return $this->vue($request);
    }

    public function generer(Request $request): View
    {
        $anneeId = $this->annee($request);
        $promoId = (int) $request->input('promo_id');
        $ecraser = $request->boolean('ecraser');

        $etudiants = DB::table('inscriptions as i')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')
            ->join('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->where('i.statut', 'inscrit')->where('p.annee_id', $anneeId)
            ->when(! $request->boolean('tous') && $promoId, fn ($q) => $q->where('i.promotion_id', $promoId))
            ->distinct()->orderBy('e.nom')->orderBy('e.prenoms')->get(['e.id', 'e.numero', 'e.nom', 'e.prenoms']);

        $rapport = ['crees' => 0, 'existants' => 0, 'erreurs' => 0, 'details' => []];
        foreach ($etudiants as $et) {
            $matricule = trim((string) $et->numero);
            $ligne = ['nom' => $et->nom.' '.$et->prenoms, 'matricule' => $matricule, 'login' => '', 'motdepasse' => null, 'msg' => null];
            if ($matricule === '' || $matricule === '0') {
                $rapport['erreurs']++;
                $rapport['details'][] = ['statut' => 'erreur', 'msg' => 'Pas encore de matricule'] + $ligne;

                continue;
            }
            $ligne['login'] = $login = $this->identifiant($et, $matricule);
            $compte = DB::table('utilisateurs')->where('etudiant_id', $et->id)->value('id');

            if ($compte && ! $ecraser) {
                $rapport['existants']++;
                $rapport['details'][] = ['statut' => 'existant'] + $ligne;

                continue;
            }
            try {
                $valeurs = ['email' => $login, 'mot_de_passe' => Hash::make($matricule), 'nom' => $et->nom, 'prenoms' => $et->prenoms];
                if ($compte) {
                    DB::table('utilisateurs')->where('id', $compte)->update($valeurs);
                } else {
                    DB::table('utilisateurs')->insert($valeurs + ['role' => 'etudiant', 'etudiant_id' => $et->id, 'actif' => 1]);
                }
                $rapport['crees']++;
                $rapport['details'][] = ['statut' => $compte ? 'mis_a_jour' : 'cree', 'motdepasse' => $matricule] + $ligne;
            } catch (Throwable $e) {
                Log::error("Génération du compte de l'étudiant {$et->id} : ".$e->getMessage());
                $rapport['erreurs']++;
                $rapport['details'][] = ['statut' => 'erreur', 'msg' => 'Enregistrement impossible'] + $ligne;
            }
        }

        $this->journal->action('generer', 'comptes_etudiants', "Comptes étudiants : {$rapport['crees']} créé(s)/mis à jour, {$rapport['existants']} existant(s), {$rapport['erreurs']} erreur(s)"
            .($ecraser ? ' — comptes existants écrasés' : ''));

        return $this->vue($request, $rapport);
    }

    /** Liste imprimable des identifiants d'une promotion (ancien &pdf=1). */
    public function imprimer(Request $request): View
    {
        $promo = $this->promotion((int) $request->query('voir_promo'));
        abort_unless($promo, 404);

        return view('etudiants.comptes_impression', [
            'promo' => $promo,
            'comptes' => $this->comptes($promo->id),
            'urlConnexion' => url('/'),
        ]);
    }

    private function vue(Request $request, ?array $rapport = null): View
    {
        $anneeId = $this->annee($request);
        $voir = $this->promotion((int) $request->query('voir_promo'));

        return view('etudiants.generer_comptes', [
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'anneeId' => $anneeId,
            'promotions' => DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->leftJoin('inscriptions as i', fn ($j) => $j->on('i.promotion_id', '=', 'p.id')->where('i.statut', 'inscrit'))
                ->where('p.annee_id', $anneeId)->groupBy('p.id', 'f.libelle', 'n.code')->orderBy('f.libelle')->orderBy('n.code')
                ->get(['p.id', 'f.libelle as filiere', 'n.code as niveau', DB::raw('COUNT(i.id) AS nb_inscrits')]),
            'voir' => $voir,
            'comptes' => $voir ? $this->comptes($voir->id) : collect(),
            'rapport' => $rapport,
            'domaine' => $this->domaine(),
        ]);
    }

    /** Année demandée, sinon l'année active, sinon la plus récente. */
    private function annee(Request $request): int
    {
        $demandee = (int) $request->input('annee_id');
        if ($demandee && DB::table('annees_academiques')->where('id', $demandee)->exists()) {
            return $demandee;
        }

        return (int) (DB::table('annees_academiques')->where('active', 1)->value('id')
            ?? DB::table('annees_academiques')->orderByDesc('libelle')->value('id'));
    }

    private function promotion(int $id): ?object
    {
        return $id ? DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->where('p.id', $id)
            ->first(['p.id', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']) : null;
    }

    private function comptes(int $promoId)
    {
        return DB::table('utilisateurs as u')->join('etudiants as e', 'e.id', '=', 'u.etudiant_id')
            ->join('inscriptions as i', fn ($j) => $j->on('i.etudiant_id', '=', 'e.id')->where('i.statut', 'inscrit'))
            ->where('i.promotion_id', $promoId)->where('u.role', 'etudiant')
            ->orderBy('u.nom')->orderBy('u.prenoms')
            ->get(['u.email', 'u.nom', 'u.prenoms', 'e.numero', 'u.actif', 'u.derniere_connexion']);
    }

    private function domaine(): string
    {
        return strtolower($this->universite->get()->code).'.ne';
    }

    /** prenom.nom.matricule@domaine — premier prénom et premier nom, sans accents. */
    private function identifiant(object $et, string $matricule): string
    {
        $propre = fn (string $s) => preg_replace('/[^a-z0-9]/', '', explode(' ', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($s))))[0]);
        $local = $propre($et->prenoms).'.'.$propre($et->nom).'.'.$matricule;
        $login = "$local@{$this->domaine()}";
        $pris = fn (string $l) => DB::table('utilisateurs')->where('email', $l)
            ->where(fn ($q) => $q->where('etudiant_id', '!=', $et->id)->orWhereNull('etudiant_id'))->exists();
        for ($n = 2; $pris($login); $n++) {
            $login = "$local.$n@{$this->domaine()}";
        }

        return $login;
    }
}
