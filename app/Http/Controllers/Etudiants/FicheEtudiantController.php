<?php

namespace App\Http\Controllers\Etudiants;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Matricules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Fiche étudiant (création / modification) — portage de pages/etudiants/etudiant_form.php.
 *
 * Différences voulues :
 *  - sans promotion, le matricule reste vide (NULL) et non « 0 » : la colonne
 *    est unique, si bien qu'un seul étudiant à la fois pouvait être « en
 *    attente » (le second échouait sur « Duplicate entry '0' ») ;
 *  - avec promotion, le matricule vient du service unique (00001…) au lieu
 *    de « UDH » + année + 4 chiffres ;
 *  - un matricule saisi déjà pris est refusé avec un message clair.
 */
class FicheEtudiantController extends Controller
{
    public const NATIONALITES = [
        'Nigérienne', 'Nigériane', 'Malienne', 'Burkinabè', 'Tchadienne', 'Béninoise',
        'Algérienne', 'Libyenne', 'Sénégalaise', 'Ivoirienne', 'Camerounaise', 'Togolaise',
        'Guinéenne', 'Ghanéenne', 'Marocaine', 'Tunisienne', 'Française',
    ];

    public const STATUTS = ['actif' => 'Actif', 'suspendu' => 'Suspendu', 'transfere' => 'Transféré', 'abandonne' => 'Abandonné', 'diplome' => 'Diplômé'];

    public function __construct(private readonly Journal $journal) {}

    public function formulaire(Request $request): View|RedirectResponse
    {
        $id = (int) $request->query('id');
        $etudiant = $id ? DB::table('etudiants')->where('id', $id)->first() : null;
        if ($id && ! $etudiant) {
            return redirect()->route('etudiants')->with('erreur', 'Étudiant introuvable');
        }

        return view('etudiants.fiche', [
            'id' => $id,
            'v' => $etudiant,
            'inscriptions' => $id ? DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                ->where('i.etudiant_id', $id)->orderByDesc('a.libelle')
                ->get(['i.*', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']) : collect(),
            'promos' => DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                ->orderByDesc('a.libelle')->orderBy('f.libelle')->orderBy('n.code')
                ->get(['p.id', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']),
            'filieres' => DB::table('filieres')->where('actif', 1)->orderBy('faculte')->orderBy('libelle')->get(['id', 'libelle', 'faculte']),
        ]);
    }

    public function enregistrer(Request $request, Matricules $matricules): RedirectResponse
    {
        $id = (int) $request->input('id');
        $etudiant = $id ? DB::table('etudiants')->where('id', $id)->first() : null;
        if ($id && ! $etudiant) {
            return redirect()->route('etudiants')->with('erreur', 'Étudiant introuvable');
        }

        $nom = mb_strtoupper(trim((string) $request->input('nom')));
        $prenoms = mb_convert_case(mb_strtolower(trim((string) $request->input('prenoms'))), MB_CASE_TITLE);
        if ($nom === '') {
            return back()->withInput()->with('erreur', 'Le nom est requis.');
        }

        $nationalite = $request->input('nationalite') === '__autre__' ? $request->input('nationalite_autre') : $request->input('nationalite');
        $valeurs = [
            'nom' => $nom,
            'prenoms' => $prenoms,
            'date_naissance' => $request->input('date_naissance') ?: null,
            'lieu_naissance' => trim((string) $request->input('lieu_naissance')) ?: null,
            'sexe' => in_array($request->input('sexe'), ['M', 'F'], true) ? $request->input('sexe') : null,
            'telephone' => trim((string) $request->input('telephone')) ?: null,
            'email' => mb_strtolower(trim((string) $request->input('email'))) ?: null,
            'nationalite' => trim((string) $nationalite) ?: null,
            'adresse' => trim((string) $request->input('adresse')) ?: null,
            'nom_pere' => trim((string) $request->input('nom_pere')) ?: null,
            'nom_mere' => trim((string) $request->input('nom_mere')) ?: null,
            'tel_urgence' => trim((string) $request->input('tel_urgence')) ?: null,
            'bac_serie' => trim((string) $request->input('bac_serie')) ?: null,
            'bac_annee' => (int) $request->input('bac_annee') ?: null,
            'bac_mention' => $request->input('bac_mention') ?: null,
            'etablissement_origine' => trim((string) $request->input('etablissement_origine')) ?: null,
            'statut_etudiant' => array_key_exists($request->input('statut_etudiant'), self::STATUTS) ? $request->input('statut_etudiant') : 'actif',
            'filiere_id' => (int) $request->input('filiere_id') ?: null,
            'numero_dossier' => mb_strtoupper(trim((string) $request->input('numero_dossier'))) ?: null,
            'numero_depot' => mb_strtolower(trim((string) $request->input('numero_depot'))) ?: null,
        ];

        if ($id) {
            // Matricule modifiable seulement s'il existe déjà
            $numero = trim((string) $request->input('numero'));
            if ($numero !== '' && $numero !== '0') {
                if (DB::table('etudiants')->where('numero', $numero)->where('id', '!=', $id)->exists()) {
                    return back()->withInput()->with('erreur', "Le matricule « $numero » est déjà attribué à un autre étudiant.");
                }
                $valeurs['numero'] = $numero;
            }
            DB::table('etudiants')->where('id', $id)->update($valeurs);
            $this->journal->action('modifier', 'etudiant', "Modification étudiant : $nom $prenoms", $id);

            return redirect()->to(app('App\Navigation\Menu')->lien('dossier_etudiant', ['id' => $id]))->with('succes', 'Étudiant mis à jour.');
        }

        $nouveau = DB::table('etudiants')->insertGetId($valeurs + ['numero' => null]);
        $this->journal->action('creer', 'etudiant', "Création étudiant : $nom $prenoms", $nouveau);

        $promo = (int) $request->input('promotion_id');
        if ($promo) {
            $matricule = $matricules->attribuer($nouveau);
            DB::table('inscriptions')->insertOrIgnore([
                'etudiant_id' => $nouveau, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => now()->toDateString(),
            ]);
            $message = "Étudiant créé et inscrit — Matricule : $matricule";
        } else {
            $message = "Étudiant créé — sans matricule (il sera attribué à l'inscription).";
        }

        return redirect()->to(app('App\Navigation\Menu')->lien('dossier_etudiant', ['id' => $nouveau]))->with('succes', $message);
    }
}
