<?php

namespace App\Http\Controllers\Administration;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Fichiers;
use App\Services\Identite;
use App\Services\Journal;
use App\Services\Parametres;
use App\Services\Resultats;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Paramètres de l'établissement — portage de pages/admin/parametres.php.
 *
 * Corrections :
 *  - l'enregistrement « Général » acceptait n'importe quelle clé envoyée
 *    (on pouvait réécrire la licence, la configuration SMTP…) : seules les
 *    clés de l'institution sont désormais acceptées ;
 *  - page réservée à l'administrateur et au secrétariat (elle était ouverte
 *    à tout détenteur de inscription_ped, y compris les agents d'une faculté,
 *    alors que ses réglages valent pour toute l'université) ;
 *  - textes bornés, images vérifiées par leur contenu (App\Services\Fichiers),
 *    signataire de faculté limité au périmètre, année vérifiée au verrouillage.
 */
class ParametresController extends Controller
{
    public const ONGLETS = [
        'general' => ['⚙️', 'Général'],
        'regles_pv' => ['📐', 'Règles de validation des PV'],
        'signataires' => ['✍️', 'Signataires par faculté'],
        'visas_diplomes' => ['📜', 'Visas des diplômes'],
    ];

    /** Clés de l'onglet Général et leur libellé. */
    public const INSTITUTION = [
        'institution_nom' => "Nom de l'institution",
        'institution_ville' => 'Ville (utilisée sur les diplômes : "Fait à ...")',
        'institution_republique' => 'République (en-tête)',
        'institution_service' => 'Service',
        'institution_bp' => 'Adresse / BP',
        'institution_email' => 'Email',
        'institution_site' => 'Site web',
        'institution_tel' => 'Téléphone',
    ];

    public const MODELES_CARTE = [
        'moderne' => ['🔷', 'Moderne', 'Bandeau bleu marine en dégradé.'],
        'badge' => ['🪪', 'Badge officiel', 'Vertical, photo en avant, code-barres.'],
        'administratif' => ['📋', 'Administratif', 'Sobre, fidèle aux cartes UAM/Tahoua.'],
    ];

    public const NIVEAUX_VISA = [
        'dut' => ['DUT', 'Diplôme Universitaire de Technologie'],
        'licence' => ['Licence', 'Diplôme de Licence'],
        'master' => ['Master', 'Diplôme de Master'],
    ];

    private const REGLES_PV = [
        'seuil_validation' => ['10', 'Seuil de validation (/20)'],
        'note_eliminatoire' => ['5', 'Note éliminatoire (/20)'],
        'note_eliminatoire_ue' => ['5', 'Note éliminatoire — moyenne UE (/20)'],
        'compensation_ue' => ['oui', "Compensation entre matières d'une UE"],
        'compensation_semestre' => ['oui', "Compensation d'une UE par la moyenne du semestre"],
        'mention_tres_bien' => ['16', 'Seuil « Très bien » (/20)'],
        'mention_bien' => ['14', 'Seuil « Bien » (/20)'],
        'mention_assez_bien' => ['12', 'Seuil « Assez bien » (/20)'],
    ];

    public function __construct(
        private readonly Parametres $parametres,
        private readonly Journal $journal,
        private readonly Perimetre $perimetre,
    ) {}

    public function index(Request $request, Identite $identite, UniversiteCourante $universite): View
    {
        $this->initialiserDefauts($universite);
        $onglet = array_key_exists((string) $request->query('tab'), self::ONGLETS) ? (string) $request->query('tab') : 'general';
        $valeurs = DB::table('parametres')->pluck('valeur', 'cle');
        $donnees = ['onglet' => $onglet, 'valeurs' => $valeurs];

        if ($onglet === 'general') {
            $donnees += [
                'logo' => $identite->imageParametre('logo_universite'),
                // Aperçu par l'adresse protégée (la signature n'est plus servie avec les logos publics)
                'signature' => $identite->imageParametre('signature_autorite') ? $identite->urlImageOfficielle('signature') : null,
                'couleurDefaut' => $universite->get()->couleur,
            ];
        } elseif ($onglet === 'regles_pv') {
            $verrouillees = DB::table('parametres_annee')->distinct()->pluck('annee_id')->map(fn ($id) => (int) $id)->all();
            $donnees['annees'] = DB::table('annees_academiques')->orderByDesc('id')->get(['id', 'libelle'])
                ->map(fn ($a) => tap($a, fn ($a) => $a->verrouillee = in_array((int) $a->id, $verrouillees, true)));
        } elseif ($onglet === 'signataires') {
            $f = $this->perimetre->faculte($request->user());
            $donnees += [
                'facultes' => DB::table('facultes')->where('actif', 1)->when($f, fn ($q) => $q->where('id', $f))->orderBy('libelle')->get(['id', 'libelle']),
                'signatairesActifs' => DB::table('signataires as s')->leftJoin('facultes as f', 'f.id', '=', 's.faculte_id')
                    ->where('s.actif', 1)->when($f, fn ($q) => $q->where('s.faculte_id', $f))
                    ->orderBy('f.libelle')->get(['s.*', 'f.libelle as fac_lib', 'f.code as fac_code']),
            ];
        } else {
            $donnees += [
                'armoirie' => $identite->imageParametre('armoirie_niger'),
                'filieres' => DB::table('filieres')->orderBy('libelle')->get(['id', 'libelle', 'domaine', 'mention_academique', 'type_formation']),
            ];
        }

        return view('administration.parametres', $donnees);
    }

    public function general(Request $request): RedirectResponse
    {
        $saisis = (array) $request->input('params', []);
        foreach (self::INSTITUTION as $cle => $label) {
            if (array_key_exists($cle, $saisis)) {
                $this->ecrire($cle, mb_substr(trim((string) $saisis[$cle]), 0, 255), $label, 'institution');
            }
        }
        $this->journal->action('modifier', 'parametres', 'Paramètres généraux (institution) modifiés');

        return $this->retour('general', 'succes', 'Paramètres généraux enregistrés.');
    }

    public function reglesPv(Request $request): RedirectResponse
    {
        $nombre = fn (string $cle, string $defaut) => (string) max(0, min(20, (float) $request->input($cle, $defaut)));
        $seuil = (float) $nombre('seuil_validation', '10');
        // Champ vide = règle désactivée (distinct d'un seuil à 0)
        $elim = trim((string) $request->input('note_eliminatoire')) === '' ? '' : $nombre('note_eliminatoire', '5');
        $elimUe = trim((string) $request->input('note_eliminatoire_ue')) === '' ? '' : $nombre('note_eliminatoire_ue', '5');
        if ($elim !== '' && (float) $elim > $seuil) {
            return $this->retour('regles_pv', 'erreur', 'La note éliminatoire ne peut pas être supérieure au seuil de validation.');
        }
        if ($elimUe !== '' && (float) $elimUe > $seuil) {
            return $this->retour('regles_pv', 'erreur', 'La note éliminatoire (moyenne UE) ne peut pas être supérieure au seuil de validation.');
        }
        [$tb, $b, $ab] = [$nombre('mention_tres_bien', '16'), $nombre('mention_bien', '14'), $nombre('mention_assez_bien', '12')];
        if (! ((float) $tb >= (float) $b && (float) $b >= (float) $ab && (float) $ab >= $seuil)) {
            return $this->retour('regles_pv', 'erreur', 'Les seuils de mention doivent être décroissants (Très bien ≥ Bien ≥ Assez bien) et rester au-dessus du seuil de validation.');
        }
        $ouiNon = fn (string $cle) => $request->input($cle, 'oui') === 'oui' ? 'oui' : 'non';

        $nouvelles = [
            'seuil_validation' => (string) $seuil, 'note_eliminatoire' => $elim, 'note_eliminatoire_ue' => $elimUe,
            'compensation_ue' => $ouiNon('compensation_ue'), 'compensation_semestre' => $ouiNon('compensation_semestre'),
            'mention_tres_bien' => $tb, 'mention_bien' => $b, 'mention_assez_bien' => $ab,
        ];
        DB::transaction(function () use ($nouvelles) {
            foreach ($nouvelles as $cle => $v) {
                $this->ecrire($cle, $v, self::REGLES_PV[$cle][1], 'regles_pv');
            }
        });
        $this->journal->audit('MODIF_REGLES_PV', 'parametres', null,
            "seuil=$seuil, elim=".($elim === '' ? 'désactivée' : $elim).', elim_ue='.($elimUe === '' ? 'désactivée' : $elimUe)
            .", comp_ue={$nouvelles['compensation_ue']}, comp_sem={$nouvelles['compensation_semestre']}, mentions=$tb/$b/$ab");

        return $this->retour('regles_pv', 'succes', "Règles de validation des PV enregistrées. Elles s'appliqueront au prochain recalcul.");
    }

    /** Fige les règles actuelles pour une année (ancien verrouillerAnnee()). */
    public function verrouiller(Request $request): RedirectResponse
    {
        $annee = DB::table('annees_academiques')->where('id', (int) $request->input('annee_id'))->first();
        if (! $annee) {
            return $this->retour('regles_pv', 'erreur', 'Année académique introuvable.');
        }
        $lignes = collect(Resultats::REGLES)->map(fn ($defaut, $cle) => ['cle' => $cle, 'annee_id' => $annee->id, 'valeur' => $this->parametres->brut($cle, $defaut)])->values()->all();
        DB::table('parametres_annee')->upsert($lignes, ['cle', 'annee_id'], ['valeur']);
        $this->journal->audit('VERROU_REGLES_ANNEE', 'parametres_annee', (int) $annee->id, "Règles figées pour {$annee->libelle}");

        return $this->retour('regles_pv', 'succes', 'Règles verrouillées pour cette année académique.');
    }

    public function deverrouiller(Request $request): RedirectResponse
    {
        $id = (int) $request->input('annee_id');
        DB::table('parametres_annee')->where('annee_id', $id)->delete();
        $this->journal->audit('DEVERROU_REGLES_ANNEE', 'parametres_annee', $id, 'Retour à la règle globale');

        return $this->retour('regles_pv', 'succes', 'Verrouillage retiré — cette année suit à nouveau la règle globale.');
    }

    /** Nouveau signataire actif d'une faculté (ou global), qui remplace l'actuel. */
    public function signataireFaculte(Request $request): RedirectResponse
    {
        $faculte = (int) $request->input('faculte_id') ?: null;
        $nom = mb_substr(trim((string) $request->input('sig_nom')), 0, 150);
        if ($nom === '') {
            return $this->retour('signataires', 'erreur', 'Le nom du signataire est requis.');
        }
        if (($faculte && ! DB::table('facultes')->where('id', $faculte)->exists()) || ! $this->perimetre->faculteAutorisee($request->user(), $faculte)) {
            return $this->retour('signataires', 'erreur', 'Faculté non autorisée.');
        }
        DB::transaction(function () use ($request, $faculte, $nom) {
            DB::table('signataires')->when($faculte, fn ($q) => $q->where('faculte_id', $faculte), fn ($q) => $q->whereNull('faculte_id'))->update(['actif' => 0]);
            DB::table('signataires')->insert([
                'faculte_id' => $faculte, 'nom' => $nom, 'actif' => 1,
                'titre' => mb_substr(trim((string) $request->input('sig_titre')), 0, 100),
                'qualite' => mb_substr(trim((string) $request->input('sig_qualite')), 0, 200),
            ]);
        });
        $this->journal->action('modifier', 'signataires', "Signataire actif défini : $nom");

        return $this->retour('signataires', 'succes', 'Signataire mis à jour.');
    }

    public function carteModele(Request $request): RedirectResponse
    {
        $modele = (string) $request->input('carte_etudiant_modele');
        if (! array_key_exists($modele, self::MODELES_CARTE)) {
            return $this->retour('general', 'erreur', 'Modèle de carte inconnu.');
        }
        $this->ecrire('carte_etudiant_modele', $modele, 'Modèle de carte étudiant', 'documents');

        return $this->retour('general', 'succes', 'Modèle de carte étudiant mis à jour.');
    }

    public function couleur(Request $request): RedirectResponse
    {
        $couleur = trim((string) $request->input('couleur_marque'));
        if ($couleur === '') {
            DB::table('parametres')->where('cle', 'couleur_marque')->delete();

            return $this->retour('general', 'succes', 'Couleur de marque réinitialisée à la valeur par défaut.');
        }
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $couleur)) {
            return $this->retour('general', 'erreur', 'Couleur invalide — utilisez le sélecteur ou un code au format #RRGGBB.');
        }
        $this->ecrire('couleur_marque', $couleur, 'Couleur de marque (barre latérale, boutons, documents)', 'institution');

        return $this->retour('general', 'succes', 'Couleur de marque mise à jour.');
    }

    public function logo(Request $request, Fichiers $fichiers): RedirectResponse
    {
        return $this->image($request, $fichiers, 'logo_universite', 'logo_univ', 'Logo université (filigrane, en-tête)', 'institution', 'general', 'Logo université mis à jour.');
    }

    public function armoirie(Request $request, Fichiers $fichiers): RedirectResponse
    {
        return $this->image($request, $fichiers, 'armoirie_niger', 'armoirie_niger', 'Armoirie du Niger (diplômes)', 'diplomes', 'visas_diplomes', 'Armoirie du Niger mise à jour.');
    }

    public function signature(Request $request, Fichiers $fichiers): RedirectResponse
    {
        return $this->image($request, $fichiers, 'signature_autorite', 'signature_autorite', 'Signature autorité (cartes étudiantes)', 'documents', 'general', "Signature de l'autorité mise à jour.",
            fn (string $ancienne) => $this->effacerSignature($fichiers, $ancienne));
    }

    public function supprimerSignature(Fichiers $fichiers): RedirectResponse
    {
        $this->effacerSignature($fichiers, $this->parametres->brut('signature_autorite'));
        DB::table('parametres')->where('cle', 'signature_autorite')->delete();

        return $this->retour('general', 'succes', "Signature de l'autorité retirée.");
    }

    public function visa(Request $request): RedirectResponse
    {
        $niveau = (string) $request->input('niveau');
        if (! array_key_exists($niveau, self::NIVEAUX_VISA)) {
            return $this->retour('visas_diplomes', 'erreur', 'Niveau inconnu.');
        }
        $this->ecrire('visa_diplome_'.$niveau, mb_substr(trim((string) $request->input('visa_texte')), 0, 5000), 'Visas diplôme — '.ucfirst($niveau), 'diplomes');

        return $this->retour('visas_diplomes', 'succes', 'Visas mis à jour pour '.ucfirst($niveau).'.');
    }

    public function signataireDiplome(Request $request): RedirectResponse
    {
        foreach (['titre' => 100, 'nom' => 150, 'qualite' => 200] as $champ => $max) {
            $cle = 'signataire_diplome_'.$champ;
            $this->ecrire($cle, mb_substr(trim((string) $request->input($cle)), 0, $max), 'Signataire des diplômes', 'diplomes');
        }

        return $this->retour('visas_diplomes', 'succes', 'Signataire des diplômes mis à jour.');
    }

    /** Domaine, mention et type de formation de chaque filière (onglet Visas). */
    public function filieres(Request $request): RedirectResponse
    {
        $types = (array) $request->input('type_formation', []);
        $mentions = (array) $request->input('mention_academique', []);
        $ids = DB::table('filieres')->pluck('id')->all();
        DB::transaction(function () use ($request, $types, $mentions, $ids) {
            foreach ((array) $request->input('domaine', []) as $id => $domaine) {
                if (! in_array((int) $id, $ids)) {
                    continue;
                }
                $type = $types[$id] ?? 'classique';
                DB::table('filieres')->where('id', (int) $id)->update([
                    'domaine' => mb_substr(trim((string) $domaine), 0, 150),
                    'mention_academique' => mb_substr(trim((string) ($mentions[$id] ?? '')), 0, 150),
                    'type_formation' => in_array($type, ['classique', 'professionnelle'], true) ? $type : 'classique',
                ]);
            }
        });

        return $this->retour('visas_diplomes', 'succes', 'Domaines, mentions et types de formation mis à jour.');
    }

    /**
     * Valeurs par défaut créées à la première visite (ancien INSERT IGNORE).
     * Nom et ville : ceux de l'université courante — l'ancien code écrivait
     * ceux de l'UDH, quelle que soit l'université.
     */
    private function initialiserDefauts(UniversiteCourante $universite): void
    {
        $u = $universite->get();
        $defauts = [
            'institution_nom' => $u->nom, 'institution_ville' => $u->ville,
            'institution_republique' => 'République du Niger', 'institution_service' => 'Service Central de la Scolarité',
        ];
        $lignes = [];
        foreach (self::INSTITUTION as $cle => $label) {
            $lignes[] = ['cle' => $cle, 'valeur' => $defauts[$cle] ?? '', 'label' => $label, 'groupe' => 'institution'];
        }
        foreach (self::REGLES_PV as $cle => [$defaut, $label]) {
            $lignes[] = ['cle' => $cle, 'valeur' => $defaut, 'label' => $label, 'groupe' => 'regles_pv'];
        }
        $lignes[] = ['cle' => 'carte_etudiant_modele', 'valeur' => 'administratif', 'label' => 'Modèle de carte étudiant', 'groupe' => 'documents'];
        DB::table('parametres')->insertOrIgnore($lignes);
    }

    /** @param  ?callable(string): void  $apres  reçoit l'ancien fichier, une fois le nouveau enregistré */
    private function image(Request $request, Fichiers $fichiers, string $champ, string $prefixe, string $label, string $groupe, string $onglet, string $message, ?callable $apres = null): RedirectResponse
    {
        $ancien = $this->parametres->brut($champ);
        if (! $request->hasFile($champ)) {
            return $this->retour($onglet, 'erreur', 'Aucun fichier sélectionné (ou fichier trop volumineux pour le serveur).');
        }
        $nom = $fichiers->enregistrerImage($request->file($champ), 'logos', $prefixe);
        if ($nom === null) {
            return $this->retour($onglet, 'erreur', 'Image refusée : PNG ou JPG valide, 2 Mo maximum.');
        }
        $this->ecrire($champ, $nom, $label, $groupe);
        if ($apres) {
            $apres($ancien);
        }
        $this->journal->action('modifier', 'parametres', "$label : $nom");

        return $this->retour($onglet, 'succes', $message);
    }

    /** Supprime l'ancienne image de signature (seulement un fichier portant ce préfixe). */
    private function effacerSignature(Fichiers $fichiers, string $fichier): void
    {
        $fichier = basename(trim($fichier));
        if (str_starts_with($fichier, 'signature_autorite_')) {
            $chemin = $fichiers->dossier('logos').DIRECTORY_SEPARATOR.$fichier;
            if (is_file($chemin)) {
                @unlink($chemin);
            }
        }
    }

    private function ecrire(string $cle, string $valeur, string $label, string $groupe): void
    {
        // La valeur seule est mise à jour, comme l'ancien ON DUPLICATE KEY UPDATE valeur=…
        DB::table('parametres')->upsert([['cle' => $cle, 'valeur' => $valeur, 'label' => $label, 'groupe' => $groupe]], ['cle'], ['valeur']);
        $this->parametres->oublier();
    }

    private function retour(string $onglet, string $type, string $message): RedirectResponse
    {
        return redirect()->route('parametres', ['tab' => $onglet])->with($type, $message);
    }
}
