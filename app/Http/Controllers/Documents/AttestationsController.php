<?php

namespace App\Http\Controllers\Documents;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Navigation\Menu;
use App\Services\Identite;
use App\Services\Journal;
use App\Services\Parametres;
use App\Services\Parcours;
use App\Services\RegistreDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Attestations de validation (4 semestres), de licence (6), de M1, de
 * Master — portage de pages/documents/attestations.php. Numéro enregistré
 * (attestations_emises) et QR de vérification.
 *
 * Accès (repris) : admin, secrétaire, sc_centrale, sc_entite ; l'étudiant
 * pour lui-même (actif avec son portail).
 *
 * Différences voulues :
 *  - étudiant de la faculté de l'agent seulement (aucun contrôle avant) ;
 *  - type inconnu refusé (il devenait silencieusement « validation ») ;
 *  - semestres, moyenne et mention du service Parcours (voir sa note) ;
 *  - numéro attribué sous verrou (voir RegistreDocuments).
 */
class AttestationsController extends Controller
{
    private const TYPES = ['validation_l2' => 'VA', 'licence' => 'LI', 'master1' => 'M1', 'master_complet' => 'MC'];

    private const NIVEAUX = ['L1' => 'Licence 1ère Année', 'L2' => 'Licence 2ème Année', 'L3' => 'Licence 3ème Année',
        'M1' => 'Master 1', 'M2' => 'Master 2', 'DUT1' => 'DUT 1ère Année', 'DUT2' => 'DUT 2ème Année'];

    public function __invoke(Request $request, Parcours $parcours, RegistreDocuments $registre, Perimetre $perimetre, Identite $identite,
        Parametres $parametres, Journal $journal, Menu $menu): View|RedirectResponse
    {
        $u = $request->user();
        if ($u->role !== 'etudiant' && ! (in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite'], true) && $u->autorise('inscription_ped'))) {
            return redirect()->route('accueil')->with('erreur', 'Accès non autorisé.');
        }
        $etId = $u->role === 'etudiant' ? (int) $u->etudiant_id : (int) $request->query('et_id');
        $type = (string) $request->query('type', 'validation_l2');
        $retour = $u->role === 'etudiant' ? redirect($menu->lien('portail_etudiant')) : redirect()->route('diplomes');
        if (! $etId || ! isset(self::TYPES[$type])) {
            return $retour->with('erreur', 'Paramètres invalides.');
        }
        $et = DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')
            ->where('e.id', $etId)->first(['e.*', 'f.faculte_id', 'fac.libelle as faculte']);
        if (! $et || ($u->role !== 'etudiant' && ! $perimetre->etudiant($u, $etId))) {
            return $retour->with('erreur', 'Étudiant introuvable.');
        }

        $b = $parcours->bilan($parcours->semestres($etId), $type, $manquant);
        if (! $b) {
            return $retour->with('erreur', "Impossible de générer cette attestation : le semestre $manquant n'est pas (ou plus) validé pour cet étudiant.");
        }
        $pro = $b['professionnelle'];
        $niveau = $b['dernier']->niveau;
        [$titre, $sousTitre] = match ($type) {
            'master1' => ['ATTESTATION DE VALIDATION', $pro ? 'DE MASTER 1 PROFESSIONNEL' : 'DE MASTER 1 RECHERCHE'],
            'master_complet' => ['ATTESTATION', $pro ? 'DE DIPLOME DE MASTER PROFESSIONNEL' : 'DE DIPLOME DE MASTER RECHERCHE'],
            'licence' => ['ATTESTATION', $pro ? 'DE LICENCE PROFESSIONNELLE' : 'DE DIPLOME DE LICENCE'],
            default => $b['cycle_dut'] && in_array($niveau, ['DUT1', 'DUT2'], true)
                ? ['ATTESTATION', 'DE DIPLOME DE DUT']
                : ['ATTESTATION DE VALIDATION', mb_strtoupper(self::NIVEAUX[$niveau] ?? ($b['dernier']->niveau_libelle ?: $niveau))],
        };

        $numero = $registre->enregistrer('attestations_emises', 'A'.date('y').'-'.self::TYPES[$type].'-', ['etudiant_id' => $etId, 'type' => $type],
            ['nom' => $et->nom, 'prenoms' => $et->prenoms, 'filiere' => $b['filiere'] ?: null, 'mention_grade' => $b['mention'],
                'moyenne' => $b['moyenne'], 'annee_academique' => $b['annee'] ?: null]);
        $journal->action('creer', 'attestation', "Attestation générée : $numero ($type) — {$et->nom} {$et->prenoms}", $etId);

        $faculte = $et->faculte_id ? (int) $et->faculte_id : null;

        return view('documents.attestation_parcours', [
            'et' => $et,
            'type' => $type,
            'b' => $b,
            'titre' => $titre,
            'sousTitre' => $sousTitre,
            'dut' => $b['cycle_dut'] && in_array($niveau, ['DUT1', 'DUT2'], true),
            'numero' => $numero,
            'faculte' => $et->faculte ?: $parametres->valeur('institution_faculte_defaut'),
            'signataire' => $identite->signataire($faculte),
            'logos' => $identite->logos($faculte),
            'republique' => $parametres->valeur('institution_republique', 'REPUBLIQUE DU NIGER'),
            'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
            'ville' => $parametres->valeur('institution_ville', 'Tahoua'),
            'urlVerification' => app(\App\Services\CleVerification::class)->url($numero),
        ]);
    }
}
