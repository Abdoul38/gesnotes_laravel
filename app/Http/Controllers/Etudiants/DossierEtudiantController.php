<?php

namespace App\Http\Controllers\Etudiants;

use App\Http\Controllers\Controller;
use App\Services\Fichiers;
use App\Services\Journal;
use App\Services\Parcours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Dossier étudiant — portage de pages/etudiants/dossier_etudiant.php
 * (côté personnel ; la consultation par l'étudiant lui-même relève du
 * portail étudiant, étape 9).
 */
class DossierEtudiantController extends Controller
{
    public const TYPES_DOCUMENTS = ['cni' => "Carte d'identité", 'acte_naissance' => 'Acte de naissance', 'bac' => 'Baccalauréat', 'photo' => 'Photo', 'certificat_residence' => 'Cert. résidence', 'autre' => 'Autre'];

    public const STATUTS = ['actif', 'suspendu', 'transfere', 'abandonne', 'diplome'];

    public function __construct(private readonly Journal $journal) {}

    public function afficher(Request $request, Fichiers $fichiers, Parcours $parcours): View|RedirectResponse
    {
        $id = (int) $request->query('id');
        if (! $id) {
            return redirect()->route('etudiants');
        }
        $et = DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->where('e.id', $id)->first(['e.*', 'f.libelle as filiere']);
        if (! $et) {
            return redirect()->route('etudiants')->with('erreur', 'Étudiant introuvable.');
        }
        $this->journal->action('consulter', 'etudiant', 'Consultation dossier : '.trim($et->nom.' '.$et->prenoms), $id);

        $u = $request->user();
        $resultats = $this->resultats($id);

        return view('etudiants.dossier', [
            'et' => $et,
            'photo' => ($et->photo && is_file($fichiers->dossier('photos').DIRECTORY_SEPARATOR.basename($et->photo))) ? basename($et->photo) : null,
            // Modification : secrétaire ou admin uniquement (comme l'original)
            'lectureSeule' => ! $u->aRole('secretaire'),
            'documents' => DB::table('documents_etudiants')->where('etudiant_id', $id)->orderByDesc('created_at')->get(),
            'inscriptions' => DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
                ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                ->where('i.etudiant_id', $id)->orderByDesc('a.libelle')
                ->get(['i.*', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee']),
            'notesGroupees' => $this->notesGroupees($id),
            'resultatsIdx' => $this->indexerResultats($resultats),
            'attestations' => $this->attestationsPossibles($parcours->semestres($id), $parcours),
            'paiements' => DB::table('paiements')->where('etudiant_id', $id)->orderByDesc('created_at')->limit(10)->get(),
            'candidature' => $this->candidature($et),
        ]);
    }

    public function modifier(Request $request, Fichiers $fichiers): RedirectResponse
    {
        $id = $this->idModifiable($request);
        $nom = trim((string) $request->input('nom'));
        $prenoms = trim((string) $request->input('prenoms'));
        if ($nom === '' || $prenoms === '') {
            return $this->retour($id, 'erreur', 'Le nom et les prénoms sont requis.');
        }
        $texte = fn (string $champ) => trim((string) $request->input($champ)) ?: null;
        DB::table('etudiants')->where('id', $id)->update([
            'nom' => $nom, 'prenoms' => $prenoms,
            'telephone' => $texte('telephone'), 'email' => $texte('email'),
            'sexe' => in_array($request->input('sexe'), ['M', 'F'], true) ? $request->input('sexe') : null,
            'nationalite' => $texte('nationalite'), 'adresse' => $texte('adresse'),
            'nom_pere' => $texte('nom_pere'), 'nom_mere' => $texte('nom_mere'), 'tel_urgence' => $texte('tel_urgence'),
            'bac_serie' => $texte('bac_serie'), 'bac_annee' => (int) $request->input('bac_annee') ?: null,
            'bac_mention' => $texte('bac_mention'), 'etablissement_origine' => $texte('etablissement_origine'),
            // Statut toujours valide (l'ancien code pouvait l'enregistrer vide)
            'statut_etudiant' => in_array($request->input('statut_etudiant'), self::STATUTS, true) ? $request->input('statut_etudiant') : 'actif',
        ]);

        if ($request->hasFile('photo')) {
            $nomPhoto = $fichiers->enregistrerImage($request->file('photo'), 'photos', "photo_$id", 2 * 1024 * 1024, Fichiers::PHOTOS);
            if ($nomPhoto === null) {
                return $this->retour($id, 'erreur', 'Informations enregistrées, mais photo refusée : image JPG, PNG ou WebP de 2 Mo maximum.');
            }
            DB::table('etudiants')->where('id', $id)->update(['photo' => $nomPhoto]);
        }

        return $this->retour($id, 'succes', 'Dossier mis à jour.');
    }

    public function ajouterDocument(Request $request, Fichiers $fichiers): RedirectResponse
    {
        $id = $this->idModifiable($request);
        $type = (string) $request->input('type_doc');
        if (! array_key_exists($type, self::TYPES_DOCUMENTS) || ! $request->hasFile('doc_fichier')) {
            return $this->retour($id, 'erreur', 'Type de document et fichier requis.');
        }
        $fichier = $request->file('doc_fichier');
        $taille = $fichier->getSize();
        $nom = $fichiers->enregistrerDocument($fichier, 'documents', "doc_$id");
        if ($nom === null) {
            return $this->retour($id, 'erreur', 'Fichier refusé. Formats acceptés : PDF, DOC, DOCX, JPG, PNG (5 Mo maximum).');
        }
        DB::table('documents_etudiants')->insert([
            'etudiant_id' => $id, 'type_doc' => $type,
            'libelle' => trim((string) $request->input('libelle')) ?: $type,
            'fichier' => $nom, 'taille' => $taille,
        ]);

        return $this->retour($id, 'succes', 'Document ajouté.');
    }

    public function validerDocument(Request $request): RedirectResponse
    {
        $id = $this->idModifiable($request);
        DB::table('documents_etudiants')->where('id', (int) $request->input('doc_id'))->where('etudiant_id', $id)->update(['valide' => 1]);

        return $this->retour($id, 'succes', 'Document validé.');
    }

    public function supprimerDocument(Request $request, Fichiers $fichiers): RedirectResponse
    {
        $id = $this->idModifiable($request);
        $doc = DB::table('documents_etudiants')->where('id', (int) $request->input('doc_id'))->where('etudiant_id', $id)->first();
        if ($doc) {
            $chemin = $fichiers->dossier('documents').DIRECTORY_SEPARATOR.basename($doc->fichier);
            if (is_file($chemin)) {
                unlink($chemin);
            }
            DB::table('documents_etudiants')->where('id', $doc->id)->delete();
        }

        return $this->retour($id, 'succes', 'Document supprimé.');
    }

    // ── Données affichées ─────────────────────────────────────

    /** Requête de l'ancienne page, à l'identique : notes des semestres suivis (sessions 1 et 2). */
    private function notesGroupees(int $id): array
    {
        $lignes = DB::select("
            SELECT m.libelle AS matiere, m.coefficient, m.credits,
                   m.note_max_totale, m.note_max_examen, m.note_max_cc, m.type_matiere,
                   ue.code AS ue_code, ue.libelle AS ue_lib, ue.credits_total, ue.ordre AS ue_ordre,
                   s.code AS sem_code, s.libelle AS semestre,
                   a.libelle AS annee, a.id AS annee_id, nv.code AS niveau,
                   n1.note_cc, n1.note_examen, n1.note_finale, n1.defaillant, n1.observation, n1.reportee,
                   n2.note_finale AS note_finale_s2, n2.defaillant AS defaillant_s2, n2.observation AS observation_s2
            FROM (
                SELECT DISTINCT ps.promotion_id, ps.semestre_id, a.id AS annee_id
                FROM notes n
                JOIN pv_sessions ps ON ps.id = n.pv_id
                JOIN promotions p ON p.id = ps.promotion_id
                JOIN annees_academiques a ON a.id = p.annee_id
                JOIN inscriptions_pedagogiques ip ON ip.etudiant_id = n.etudiant_id AND ip.semestre_id = ps.semestre_id
                     AND ip.annee_id = a.id AND ip.statut = 'actif'
                WHERE n.etudiant_id = :e_src
            ) src
            JOIN promotions p ON p.id = src.promotion_id
            JOIN annees_academiques a ON a.id = src.annee_id
            JOIN niveaux nv ON nv.id = p.niveau_id
            JOIN unites_enseignement ue ON ue.promotion_id = p.id AND ue.semestre_id = src.semestre_id
            JOIN semestres s ON s.id = ue.semestre_id
            JOIN matieres m ON m.ue_id = ue.id AND m.actif = 1
            LEFT JOIN pv_sessions ps1 ON ps1.promotion_id = p.id AND ps1.semestre_id = ue.semestre_id AND ps1.type_session = 'normale'
            LEFT JOIN notes n1 ON n1.pv_id = ps1.id AND n1.matiere_id = m.id AND n1.etudiant_id = :e1
            LEFT JOIN pv_sessions ps2 ON ps2.promotion_id = p.id AND ps2.semestre_id = ue.semestre_id AND ps2.type_session = 'rattrapage'
            LEFT JOIN notes n2 ON n2.pv_id = ps2.id AND n2.matiere_id = m.id AND n2.etudiant_id = :e2
            ORDER BY a.libelle DESC, s.code, ue.ordre, m.ordre
        ", ['e_src' => $id, 'e1' => $id, 'e2' => $id]);

        $groupes = [];
        foreach ($lignes as $l) {
            $groupes[$l->annee][$l->semestre][] = $l;
        }

        return $groupes;
    }

    private function resultats(int $id): array
    {
        return DB::select("
            SELECT rs.valide, rs.mention, rs.moyenne_generale, rs.rang,
                   rs.credits_obtenus, rs.credits_total, rs.decision,
                   ps.id AS pv_id, s.libelle AS semestre, s.code AS sem_code,
                   a.libelle AS annee, nv.code AS niveau, ps.type_session
            FROM resultats_semestre rs
            JOIN pv_sessions ps ON ps.id = rs.pv_id
            JOIN semestres s ON s.id = ps.semestre_id
            JOIN promotions p ON p.id = ps.promotion_id
            JOIN annees_academiques a ON a.id = p.annee_id
            JOIN niveaux nv ON nv.id = p.niveau_id
            WHERE rs.etudiant_id = :e
            ORDER BY a.libelle DESC, s.code, rs.valide DESC,
                     CASE ps.type_session WHEN 'normale' THEN 1 ELSE 2 END ASC
        ", ['e' => $id]);
    }

    /** [année][semestre][session] ; rattrapage ignoré si la session normale est validée. */
    private function indexerResultats(array $resultats): array
    {
        $idx = [];
        foreach ($resultats as $r) {
            $normaleValidee = ($idx[$r->annee][$r->semestre]['normale'] ?? null)?->valide;
            if ($r->type_session !== 'normale' && $normaleValidee) {
                continue;
            }
            $idx[$r->annee][$r->semestre][$r->type_session] = $r;
        }

        return $idx;
    }

    /**
     * Attestations proposées : même règle que la page des attestations
     * (App\Services\Parcours : premier cycle Licence, DUT et LP ensemble,
     * Master à part). Différence voulue : avant, seuls les niveaux L1, L2, L3
     * comptaient — un étudiant de l'IUT (DUT1, DUT2, LP) qui avait validé ses
     * 4 ou 6 semestres n'avait aucun bouton. Licence complète d'un parcours
     * IUT : attestation de licence professionnelle ET de DUT.
     *
     * @return list<array{0: string, 1: string, 2: string}> [type, libellé, couleurs]
     */
    private function attestationsPossibles(array $semestres, Parcours $parcours): array
    {
        $master = $parcours->bilan($semestres, 'master_complet');
        if ($master || $parcours->bilan($semestres, 'master1')) {
            return [$master ? ['master_complet', '🎓 Attestation Master', '#b45309,#92400e'] : ['master1', '🎓 Attestation M1', '#b45309,#92400e']];
        }
        $liste = [];
        if ($licence = $parcours->bilan($semestres, 'licence')) {
            $liste[] = ['licence', $licence['professionnelle'] ? '🎓 Attestation Licence professionnelle' : '🎓 Attestation Licence', '#7c3aed,#5b21b6'];
        }
        $l2 = $parcours->bilan($semestres, 'validation_l2');
        $dut = $l2 && $l2['cycle_dut'] && in_array($l2['dernier']->niveau, ['DUT1', 'DUT2'], true);
        if ($dut) {
            $liste[] = ['validation_l2', '🎓 Attestation DUT', '#0891b2,#0e7490'];
        } elseif ($l2 && ! $licence) {
            $liste[] = ['validation_l2', '✅ Attestation Validation L2', '#059669,#047857'];
        }

        return $liste;
    }

    /**
     * Dossier de candidature lié (par etudiant_id, n° de dossier ou n° de
     * dépôt). Comme l'original, le lien est mémorisé s'il manquait.
     */
    private function candidature(object $et): ?object
    {
        $ndos = mb_strtoupper(trim((string) $et->numero_dossier));
        $ndep = mb_strtolower(trim((string) $et->numero_depot));
        $c = DB::table('candidatures as c')
            ->leftJoin('filieres as f1', 'f1.id', '=', 'c.choix1_id')->leftJoin('filieres as f2', 'f2.id', '=', 'c.choix2_id')
            ->leftJoin('filieres as f3', 'f3.id', '=', 'c.choix3_id')
            ->where(function ($w) use ($et, $ndos, $ndep) {
                $w->where('c.etudiant_id', $et->id);
                if ($ndos !== '') {
                    $w->orWhereRaw('UPPER(TRIM(c.numero_dossier)) = ?', [$ndos]);
                }
                if ($ndep !== '') {
                    $w->orWhereRaw('LOWER(TRIM(c.numero_depot)) = ?', [$ndep]);
                }
            })
            ->orderByDesc('c.id')
            ->first(['c.*', 'f1.libelle as ch1_lib', 'f1.faculte as ch1_fac', 'f2.libelle as ch2_lib', 'f2.faculte as ch2_fac', 'f3.libelle as ch3_lib', 'f3.faculte as ch3_fac']);

        if ($c && empty($c->etudiant_id)) {
            DB::table('candidatures')->where('id', $c->id)->update(['etudiant_id' => $et->id]);
        }

        return $c;
    }

    // ── Outils ────────────────────────────────────────────────

    /** Étudiant visé par une action ; refus si l'utilisateur n'a pas le droit de modifier. */
    private function idModifiable(Request $request): int
    {
        abort_unless($request->user()->aRole('secretaire'), 403);
        $id = (int) $request->input('id');
        abort_unless(DB::table('etudiants')->where('id', $id)->exists(), 404);

        return $id;
    }

    private function retour(int $id, string $type, string $message): RedirectResponse
    {
        return redirect()->route('dossier_etudiant', ['id' => $id])->with($type, $message);
    }
}
