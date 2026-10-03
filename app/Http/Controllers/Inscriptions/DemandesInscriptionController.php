<?php

namespace App\Http\Controllers\Inscriptions;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Matricules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Demandes d'inscription — portage de pages/inscriptions/
 * demandes_inscription.php : accepter (fiche étudiant + matricule,
 * inscription dans une promotion au choix) ou refuser avec un motif.
 *
 * Différences voulues :
 *  - réservé à la gestion des inscriptions (admin, secrétaire,
 *    sc_centrale) : la page n'exigeait aucune permission ;
 *  - une demande ne s'accepte qu'une fois (chaque clic créait une nouvelle
 *    fiche) ; matricule attribué sous verrou ;
 *  - inscription complète (niveau, année, date) : l'ancienne requête
 *    omettait la date d'inscription, obligatoire, et échouait.
 */
class DemandesInscriptionController extends Controller
{
    private const STATUTS = ['en_attente' => '⏳ En attente', 'acceptee' => '✅ Acceptées', 'refusee' => '❌ Refusées'];

    public function __construct(private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $statut = isset(self::STATUTS[$request->query('statut')]) ? $request->query('statut') : 'en_attente';

        return view('inscriptions.demandes', [
            'statut' => $statut, 'statuts' => self::STATUTS,
            'compteurs' => DB::table('demandes_inscription')->groupBy('statut')->get(['statut', DB::raw('COUNT(*) AS nb')])->pluck('nb', 'statut'),
            'demandes' => DB::table('demandes_inscription as d')->leftJoin('filieres as f', 'f.id', '=', 'd.filiere_souhaitee')
                ->where('d.statut', $statut)->orderByDesc('d.created_at')->limit(500)->get(['d.*', 'f.libelle as filiere']),
            'promotions' => DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')->where('a.active', 1)->orderBy('f.libelle')->orderBy('n.code')
                ->get(['p.id', 'p.filiere_id', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']),
        ]);
    }

    public function accepter(Request $request, Matricules $matricules): RedirectResponse
    {
        $retour = redirect()->route('demandes_inscription');
        $demande = DB::table('demandes_inscription')->where(['id' => (int) $request->input('id'), 'statut' => 'en_attente'])->first();
        if (! $demande) {
            return $retour->with('erreur', 'Demande introuvable ou déjà traitée.');
        }
        $promotion = (int) $request->input('promotion_id') ? DB::table('promotions')->find((int) $request->input('promotion_id')) : null;
        if ((int) $request->input('promotion_id') && ! $promotion) {
            return $retour->with('erreur', 'Promotion introuvable.');
        }
        [$etudiant, $matricule] = $matricules->sousVerrou(fn () => DB::transaction(function () use ($demande, $promotion, $matricules) {
            if (DB::table('demandes_inscription')->where(['id' => $demande->id, 'statut' => 'en_attente'])->lockForUpdate()->doesntExist()) {
                return [null, null];
            }
            $matricule = Matricules::formater($matricules->dernier() + 1);
            $id = DB::table('etudiants')->insertGetId(['numero' => $matricule, 'nom' => mb_strtoupper($demande->nom), 'prenoms' => $demande->prenoms,
                'date_naissance' => $demande->date_naissance, 'lieu_naissance' => $demande->lieu_naissance, 'sexe' => $demande->sexe,
                'nationalite' => $demande->nationalite ?: 'Nigérienne', 'telephone' => $demande->telephone, 'email' => $demande->email,
                'bac_serie' => $demande->bac_serie, 'bac_annee' => $demande->bac_annee, 'bac_mention' => $demande->bac_mention,
                'etablissement_origine' => $demande->etablissement_origine, 'filiere_id' => $promotion->filiere_id ?? $demande->filiere_souhaitee]);
            if ($promotion) {
                DB::table('inscriptions')->insert(['etudiant_id' => $id, 'promotion_id' => $promotion->id, 'niveau_id' => $promotion->niveau_id,
                    'annee_id' => $promotion->annee_id, 'date_inscription' => now()->toDateString(), 'statut' => 'inscrit']);
            }
            DB::table('demandes_inscription')->where('id', $demande->id)->update(['statut' => 'acceptee', 'etudiant_id' => $id]);

            return [$id, $matricule];
        }));
        if (! $etudiant) {
            return $retour->with('erreur', 'Demande déjà traitée.');
        }
        $this->journal->action('creer', 'inscription', "Demande d'inscription #{$demande->id} acceptée — étudiant #$etudiant ($matricule)", $etudiant);

        return $retour->with('succes', "Demande acceptée — Matricule : $matricule")->with('recu_etudiant', $promotion ? $etudiant : null);
    }

    public function refuser(Request $request): RedirectResponse
    {
        $nb = DB::table('demandes_inscription')->where(['id' => (int) $request->input('id'), 'statut' => 'en_attente'])
            ->update(['statut' => 'refusee', 'notes_admin' => mb_substr(trim((string) $request->input('notes', '')), 0, 2000)]);
        if ($nb) {
            $this->journal->action('modifier', 'inscription', "Demande d'inscription #{$request->input('id')} refusée", (int) $request->input('id'));
        }

        return redirect()->route('demandes_inscription')->with($nb ? 'succes' : 'erreur', $nb ? 'Demande refusée.' : 'Demande introuvable ou déjà traitée.');
    }
}
