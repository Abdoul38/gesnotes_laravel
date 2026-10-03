<?php

namespace App\Http\Controllers\Documents;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Navigation\Menu;
use App\Services\Identite;
use App\Services\Journal;
use App\Services\Parametres;
use App\Services\RegistreDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Certificat de scolarité — portage de pages/documents/certificat_scolarite.php,
 * pour une inscription donnée (ins_id) ou la dernière inscription d'un
 * étudiant (et_id). Numéro enregistré (certificats_emis) et QR.
 *
 * Accès (repris) : admin, secrétaire, sc_centrale, sc_entite avec la
 * permission « certificat_sco » ; l'étudiant pour lui-même.
 *
 * Différences voulues :
 *  - par ins_id, l'ancienne page enregistrait le certificat sous
 *    l'étudiant n° 0 : tous ces certificats d'une même année partageaient
 *    un seul numéro, et la vérification publique affichait le nom du dernier
 *    étudiant imprimé ; le certificat est maintenant celui de l'étudiant ;
 *  - seulement pour une inscription « inscrit » (une inscription annulée
 *    donnait un certificat par ins_id) ;
 *  - inscription de la faculté de l'agent (aucun contrôle avant) ;
 *  - numéro attribué sous verrou ; émission journalisée.
 */
class CertificatScolariteController extends Controller
{
    public function __invoke(Request $request, Perimetre $perimetre, RegistreDocuments $registre, Identite $identite, Parametres $parametres,
        Journal $journal, Menu $menu): View|RedirectResponse
    {
        $u = $request->user();
        $estEtudiant = $u->role === 'etudiant';
        if (! $estEtudiant && ! (in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite'], true) && $u->autorise('certificat_sco'))) {
            return redirect()->route('accueil')->with('erreur', 'Accès non autorisé.');
        }
        $faculte = $estEtudiant ? 0 : $perimetre->faculte($u);
        $insId = $estEtudiant ? 0 : (int) $request->query('ins_id');
        $etId = $estEtudiant ? (int) $u->etudiant_id : (int) $request->query('et_id');

        $d = DB::table('inscriptions as i')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->where('i.statut', 'inscrit')
            ->when($insId, fn ($q) => $q->where('i.id', $insId), fn ($q) => $q->where('i.etudiant_id', $etId)->orderByDesc('a.libelle'))
            ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
            ->first(['i.etudiant_id', 'e.nom', 'e.prenoms', 'e.sexe', 'e.date_naissance', 'e.lieu_naissance', 'e.numero', 'e.numero_dossier',
                'f.faculte_id', 'f.libelle as filiere', DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'), 'n.libelle as niveau', 'a.libelle as annee']);
        if (! $d) {
            return ($estEtudiant ? redirect($menu->lien('portail_etudiant')) : redirect($menu->lien('inscription_scolarite')))
                ->with('erreur', 'Inscription introuvable (seule une inscription en cours donne un certificat).');
        }

        $numero = $registre->enregistrer('certificats_emis', 'C'.date('y').'-', ['etudiant_id' => $d->etudiant_id, 'annee_academique' => $d->annee],
            ['nom' => $d->nom, 'prenoms' => $d->prenoms, 'filiere' => $d->filiere, 'niveau' => $d->niveau]);
        $journal->action('creer', 'certificat', "Certificat de scolarité : $numero — {$d->nom} {$d->prenoms} ({$d->annee})", (int) $d->etudiant_id);
        $fac = $d->faculte_id ? (int) $d->faculte_id : null;

        return view('documents.certificat', [
            'd' => $d,
            'numero' => $numero,
            'signataire' => $identite->signataire($fac),
            'logos' => $identite->logos($fac),
            'p' => fn (string $cle, string $defaut = '') => $parametres->valeur($cle, $defaut),
            'urlVerification' => $menu->lien('verifier_diplome', ['num' => $numero]),
        ]);
    }
}
