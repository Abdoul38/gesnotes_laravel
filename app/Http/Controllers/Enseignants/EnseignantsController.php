<?php

namespace App\Http\Controllers\Enseignants;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Services\Fichiers;
use App\Services\Journal;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Enseignants et affectations — portage de pages/enseignants/enseignants.php.
 *
 * Droits (repris de l'original) :
 *  - fiche (ajout, modification, suppression) : admin, secrétaire,
 *    sc_centrale, sc_entite (ancien hasRole, hiérarchique) ;
 *  - affectations : les mêmes, plus le chef de département pour les
 *    matières de SON département ;
 *  - un compte rattaché à une faculté ne voit et ne touche que les
 *    enseignants de sa faculté (ou sans faculté).
 *
 * Différences voulues :
 *  - un agent de faculté ne peut plus affecter une matière d'une autre
 *    faculté, ni retirer l'affectation d'un enseignant d'une autre faculté,
 *    ni reconduire les affectations des autres facultés (il reconduisait
 *    celles de toute l'université) ;
 *  - enseignant, matière et année d'une affectation doivent exister ;
 *  - suppression refusée si l'enseignant a encore des affectations, des
 *    séances ou un compte utilisateur (même règle que pour les étudiants :
 *    l'ancienne suppression effaçait ses affectations en cascade) ;
 *  - photo vérifiée par son contenu, servie par une route protégée (elle
 *    était publique dans uploads/photos_enseignants/) ;
 *  - e-mail et dates vérifiés ; message technique d'erreur non affiché ;
 *  - liste : une requête pour toutes les affectations (l'ancienne en
 *    faisait deux par enseignant).
 */
class EnseignantsController extends Controller
{
    private const CHARGES = ['cours', 'td', 'cours_td'];

    public function __construct(private readonly Journal $journal, private readonly Fichiers $fichiers) {}

    public function index(Request $request): View|RedirectResponse
    {
        $u = $request->user();
        $droits = $this->droits($u);
        $annees = DB::table('annees_academiques')->orderByDesc('libelle')->get();
        $anneeActive = (int) ($annees->firstWhere('active', 1)->id ?? 0);

        // Filtre des affectations : année active par défaut, 0 = toutes
        $filtre = $request->has('aff_annee') ? (int) $request->query('aff_annee') : $anneeActive;
        if ($filtre !== 0 && ! $annees->contains('id', $filtre)) {
            $filtre = $anneeActive;
        }
        $commun = [
            'droits' => $droits,
            'annees' => $annees,
            'anneeActive' => $anneeActive,
            'filtre' => $filtre,
            'filtreLibelle' => $annees->firstWhere('id', $filtre)->libelle ?? 'toutes les années',
            'filtreQs' => $request->has('aff_annee') ? ['aff_annee' => $filtre] : [],
            'facultes' => DB::table('facultes')->orderBy('libelle')->get(['id', 'code', 'libelle']),
            'departements' => DB::table('departements as d')->join('facultes as f', 'f.id', '=', 'd.faculte_id')
                ->orderBy('f.libelle')->orderBy('d.libelle')->get(['d.id', 'd.libelle', 'd.faculte_id']),
        ];

        if ($id = (int) $request->query('id')) {
            $ens = DB::table('enseignants')->where('id', $id)->first();
            if ($ens && ! $this->accessible($u, $ens)) {
                return redirect()->route('enseignants')->with('erreur', 'Accès non autorisé — cet enseignant appartient à une autre faculté.');
            }
            if ($ens) {
                return $this->detail($request, $ens, $commun);
            }
        }

        $enseignants = DB::table('enseignants')
            ->when($droits['faculte'], fn ($q, $f) => $q->where(fn ($q) => $q->where('faculte_id', $f)->orWhereNull('faculte_id')))
            ->orderBy('nom')->orderBy('prenoms')->get();
        $affectations = $this->affectations($filtre)->whereIn('a.enseignant_id', $enseignants->pluck('id'))->get()->groupBy('enseignant_id');

        return view('enseignants.liste', $commun + compact('enseignants', 'affectations'));
    }

    private function detail(Request $request, object $ens, array $commun): View
    {
        $droits = $commun['droits'];
        $annees = $commun['annees'];
        $anneeId = (int) $request->query('annee_id') ?: ($commun['anneeActive'] ?: (int) ($annees->first()->id ?? 0));

        // Reconduction : vers l'année active, depuis celle qui la précède
        $destination = $commun['anneeActive'] ?: $anneeId;
        $position = $annees->search(fn ($a) => (int) $a->id === $destination);
        $source = $position !== false ? (int) ($annees->get($position + 1)->id ?? 0) : 0;

        $photo = $ens->photo && is_file($this->fichiers->dossier('photos_enseignants').DIRECTORY_SEPARATOR.basename($ens->photo))
            ? route('fichiers.photo_enseignant', basename($ens->photo)) : null;

        return view('enseignants.detail', $commun + [
            'ens' => $ens,
            'photo' => $photo,
            'anneeId' => $anneeId,
            'reconSource' => $source,
            'reconDestination' => $destination,
            'affectations' => $this->affectations($commun['filtre'])->where('a.enseignant_id', $ens->id)->get(),
            'matieres' => $droits['affecter'] ? $this->matieresProposees($droits) : collect(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $u = $request->user();
        $droits = $this->droits($u);
        abort_unless($droits['modifier'], 403);
        $id = (int) $request->input('id');
        $retour = $id ? route('enseignants', ['id' => $id]) : route('enseignants');

        $ancien = $id ? DB::table('enseignants')->where('id', $id)->first() : null;
        if ($id && (! $ancien || ! $this->accessible($u, $ancien))) {
            return redirect()->route('enseignants')->with('erreur', 'Accès non autorisé — cet enseignant appartient à une autre faculté.');
        }

        $texte = fn (string $champ) => trim((string) $request->input($champ));
        $date = fn (string $champ) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texte($champ), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $texte($champ) : null;
        if ($texte('nom') === '') {
            return redirect($retour)->with('erreur', 'Nom requis.');
        }
        if ($texte('email') !== '' && ! filter_var($texte('email'), FILTER_VALIDATE_EMAIL)) {
            return redirect($retour)->withInput()->with('erreur', 'Adresse e-mail invalide.');
        }

        // Un agent rattaché à une faculté ne peut jamais en choisir une autre
        $faculte = $droits['faculte'] ?: ((int) $request->input('faculte_id') ?: null);
        if ($faculte && ! DB::table('facultes')->where('id', $faculte)->exists()) {
            $faculte = null;
        }
        $departement = (int) $request->input('departement_id') ?: null;
        if ($departement && ! DB::table('departements')->where('id', $departement)->when($faculte, fn ($q) => $q->where('faculte_id', $faculte))->exists()) {
            $departement = null;   // département d'une autre faculté (ou inexistant) : ignoré
        }

        $donnees = [
            'nom' => $texte('nom'), 'prenoms' => $texte('prenoms'), 'matricule' => $texte('matricule'),
            'grade' => $texte('grade'), 'specialite' => $texte('specialite'), 'email' => $texte('email'), 'telephone' => $texte('telephone'),
            'faculte_id' => $faculte, 'departement_id' => $departement,
            'date_naissance' => $date('date_naissance'), 'lieu_naissance' => $texte('lieu_naissance') ?: null,
            'diplome_plus_eleve' => $texte('diplome_plus_eleve') ?: null, 'date_recrutement' => $date('date_recrutement'),
            'adresse' => $texte('adresse') ?: null,
        ];
        if ($ancien) {
            DB::table('enseignants')->where('id', $id)->update($donnees);
            $this->journal->action('modifier', 'enseignant', "Modification enseignant : {$donnees['nom']} {$donnees['prenoms']}", $id);
            $message = 'Enseignant mis à jour.';
        } else {
            $id = DB::table('enseignants')->insertGetId($donnees);
            $this->journal->action('creer', 'enseignant', "Création enseignant : {$donnees['nom']} {$donnees['prenoms']}", $id);
            $message = 'Enseignant ajouté.';
        }

        if ($request->hasFile('photo')) {
            $photo = $this->fichiers->enregistrerImage($request->file('photo'), 'photos_enseignants', 'ens_'.$id, 5 * 1024 * 1024, Fichiers::PHOTOS);
            if (! $photo) {
                return redirect($ancien ? $retour : route('enseignants'))->with('avertissement', "$message Photo refusée : JPG, PNG ou WEBP de 5 Mo maximum.");
            }
            DB::table('enseignants')->where('id', $id)->update(['photo' => $photo]);
            if ($ancien?->photo && is_file($vieille = $this->fichiers->dossier('photos_enseignants').DIRECTORY_SEPARATOR.basename($ancien->photo))) {
                @unlink($vieille);
            }
        }

        return redirect($ancien ? $retour : route('enseignants'))->with('succes', $message);
    }

    public function supprimer(Request $request): RedirectResponse
    {
        $u = $request->user();
        abort_unless($this->droits($u)['modifier'], 403);
        $id = (int) $request->input('id');
        $ens = DB::table('enseignants')->where('id', $id)->first();
        if (! $ens || ! $this->accessible($u, $ens)) {
            return redirect()->route('enseignants')->with('erreur', 'Accès non autorisé — cet enseignant appartient à une autre faculté.');
        }
        $liens = array_filter([
            'affectation(s)' => DB::table('affectations')->where('enseignant_id', $id)->count(),
            'séance(s)' => DB::table('seances')->where('enseignant_id', $id)->count(),
            'compte utilisateur' => DB::table('utilisateurs')->where('enseignant_id', $id)->count(),
        ]);
        if ($liens) {
            $detail = implode(', ', array_map(fn ($n, $quoi) => "$n $quoi", $liens, array_keys($liens)));

            return redirect()->route('enseignants')->with('erreur', "Suppression impossible : {$ens->nom} {$ens->prenoms} a encore $detail. Retirez-les d'abord.");
        }
        DB::table('enseignants')->where('id', $id)->delete();
        if ($ens->photo && is_file($fichier = $this->fichiers->dossier('photos_enseignants').DIRECTORY_SEPARATOR.basename($ens->photo))) {
            @unlink($fichier);
        }
        $this->journal->action('supprimer', 'enseignant', "Suppression enseignant : {$ens->nom} {$ens->prenoms}", $id);

        return redirect()->route('enseignants')->with('succes', 'Enseignant supprimé.');
    }

    public function affecter(Request $request): RedirectResponse
    {
        $u = $request->user();
        $droits = $this->droits($u);
        abort_unless($droits['affecter'], 403);
        $ensId = (int) $request->input('enseignant_id');
        $matId = (int) $request->input('matiere_id');
        $anneeId = (int) $request->input('annee_id');
        $type = in_array($request->input('type_charge'), self::CHARGES, true) ? $request->input('type_charge') : 'cours';
        $retour = route('enseignants', ['id' => $ensId, 'aff_annee' => $anneeId]);

        $ens = DB::table('enseignants')->where('id', $ensId)->first();
        if (! $ens || ! $this->accessible($u, $ens)) {
            return redirect($retour)->with('erreur', 'Accès non autorisé — cet enseignant appartient à une autre faculté.');
        }
        if (! DB::table('matieres')->where('id', $matId)->exists() || ! DB::table('annees_academiques')->where('id', $anneeId)->exists()) {
            return redirect($retour)->with('erreur', 'Matière ou année introuvable.');
        }
        if (! $this->matiereAutorisee($droits, $matId)) {
            return redirect($retour)->with('erreur', $droits['modifier']
                ? "Cette matière n'appartient pas à votre faculté."
                : "Cette matière n'appartient pas à votre département.");
        }
        DB::table('affectations')->upsert(
            [['enseignant_id' => $ensId, 'matiere_id' => $matId, 'annee_id' => $anneeId, 'type_charge' => $type]],
            ['enseignant_id', 'matiere_id', 'annee_id'], ['type_charge'],
        );
        $this->journal->action('affecter', 'enseignant', "Affectation de la matière #$matId ($type)", $ensId);

        return redirect($retour)->with('succes', 'Affectation enregistrée.');
    }

    public function desaffecter(Request $request): RedirectResponse
    {
        $u = $request->user();
        $droits = $this->droits($u);
        abort_unless($droits['affecter'], 403);
        $aff = DB::table('affectations')->where('id', (int) $request->input('id'))->first();
        $retour = route('enseignants', array_filter(['id' => (int) $request->input('retour_id') ?: null]) + ($request->filled('aff_annee') ? ['aff_annee' => (int) $request->input('aff_annee')] : []));
        if (! $aff) {
            return redirect($retour)->with('erreur', 'Affectation introuvable.');
        }
        $ens = DB::table('enseignants')->where('id', $aff->enseignant_id)->first();
        $permis = $droits['modifier']
            ? ($ens && $this->accessible($u, $ens))
            : $this->dansDepartement($droits['departement'], (int) $aff->matiere_id);
        if (! $permis) {
            return redirect($retour)->with('erreur', $droits['modifier']
                ? 'Accès non autorisé — cet enseignant appartient à une autre faculté.'
                : 'Cette affectation ne concerne pas une matière de votre département.');
        }
        DB::table('affectations')->where('id', $aff->id)->delete();
        $this->journal->action('desaffecter', 'enseignant', "Retrait de la matière #{$aff->matiere_id}", (int) $aff->enseignant_id);

        return redirect($retour)->with('succes', 'Affectation supprimée.');
    }

    public function reconduire(Request $request): RedirectResponse
    {
        $droits = $this->droits($request->user());
        abort_unless($droits['affecter'], 403);
        $source = (int) $request->input('annee_src_id');
        $destination = (int) $request->input('annee_dest_id');
        $retour = route('enseignants', array_filter(['id' => (int) $request->input('retour_id') ?: null]) + ['aff_annee' => $destination]);
        if (! $source || ! $destination || $source === $destination
            || DB::table('annees_academiques')->whereIn('id', [$source, $destination])->count() !== 2) {
            return redirect($retour)->with('erreur', 'Veuillez sélectionner deux années différentes.');
        }

        $lignes = DB::table('affectations as a')->where('a.annee_id', $source)
            ->when(! $droits['modifier'], fn ($q) => $q->whereIn('a.matiere_id', $this->matieresDe(departement: $droits['departement'])))
            ->when($droits['modifier'] && $droits['faculte'], fn ($q) => $q->whereIn('a.matiere_id', $this->matieresDe(faculte: $droits['faculte'])))
            ->get(['a.enseignant_id', 'a.matiere_id', 'a.type_charge'])
            ->map(fn ($a) => ['enseignant_id' => $a->enseignant_id, 'matiere_id' => $a->matiere_id, 'annee_id' => $destination, 'type_charge' => $a->type_charge])
            ->all();
        if (! $lignes) {
            return redirect($retour)->with('erreur', "Aucune affectation à reconduire dans l'année source — rien n'a été copié.");
        }
        foreach (array_chunk($lignes, 500) as $paquet) {
            DB::table('affectations')->upsert($paquet, ['enseignant_id', 'matiere_id', 'annee_id'], ['type_charge']);
        }
        $this->journal->action('reconduire', 'enseignant', count($lignes)." affectation(s) reconduite(s) de l'année #$source vers #$destination");

        return redirect($retour)->with('succes', '✅ '.count($lignes)." affectation(s) reconduite(s) vers l'année sélectionnée.");
    }

    /** @return array{modifier: bool, chef: bool, departement: int, affecter: bool, faculte: int} */
    private function droits(Utilisateur $u): array
    {
        $modifier = $u->aRole('admin') || $u->aRole('sc_centrale') || $u->aRole('sc_entite');
        // Rôle comparé à l'identique : aRole() est hiérarchique
        $chef = $u->role === 'chef_departement';
        $departement = $chef ? $u->departementId() : 0;

        return [
            'modifier' => $modifier,
            'chef' => $chef,
            'departement' => $departement,
            'affecter' => $modifier || ($chef && $departement > 0),
            'faculte' => $u->faculteId(),
        ];
    }

    /** Enseignant de la faculté de l'utilisateur, ou sans faculté. */
    private function accessible(Utilisateur $u, object $ens): bool
    {
        return ! $u->faculteId() || ! $ens->faculte_id || (int) $ens->faculte_id === $u->faculteId();
    }

    private function matiereAutorisee(array $droits, int $matId): bool
    {
        if (! $droits['modifier']) {
            return $this->dansDepartement($droits['departement'], $matId);
        }

        return ! $droits['faculte'] || $this->matieresDe(faculte: $droits['faculte'])->where('m.id', $matId)->exists();
    }

    private function dansDepartement(int $departement, int $matId): bool
    {
        return $departement > 0 && $matId > 0 && $this->matieresDe(departement: $departement)->where('m.id', $matId)->exists();
    }

    /** Identifiants des matières d'un département ou d'une faculté (sous-requête). */
    private function matieresDe(int $departement = 0, int $faculte = 0): Builder
    {
        return DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
            ->join('promotions as p', 'p.id', '=', 'ue.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->when($departement, fn ($q) => $q->where('f.departement_id', $departement))
            ->when($faculte, fn ($q) => $q->where('f.faculte_id', $faculte))
            ->select('m.id');
    }

    private function affectations(int $annee): Builder
    {
        return DB::table('affectations as a')->join('matieres as m', 'm.id', '=', 'a.matiere_id')
            ->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')->join('promotions as p', 'p.id', '=', 'ue.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->join('annees_academiques as ann', 'ann.id', '=', 'a.annee_id')
            ->when($annee, fn ($q) => $q->where('a.annee_id', $annee))
            ->orderByDesc('ann.libelle')->orderBy('f.libelle')->orderBy('m.libelle')
            ->select('a.id', 'a.enseignant_id', 'a.type_charge', 'm.libelle as matiere', 'm.code as mat_code', 'ue.libelle as ue_libelle',
                'f.libelle as filiere', 'f.departement_id as dep_id', 'n.code as niveau', 'ann.libelle as annee');
    }

    /** Matières proposées dans le formulaire d'affectation, selon le périmètre. */
    private function matieresProposees(array $droits)
    {
        return DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
            ->join('promotions as p', 'p.id', '=', 'ue.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
            ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'ue.semestre_id')
            ->where('m.actif', 1)
            ->when(! $droits['modifier'], fn ($q) => $q->where('f.departement_id', $droits['departement']))
            ->when($droits['modifier'] && $droits['faculte'], fn ($q) => $q->where('f.faculte_id', $droits['faculte']))
            ->orderBy('f.libelle')->orderBy('n.code')->orderBy('s.code')->orderBy('m.libelle')
            ->get(['m.id', 'm.libelle', 'm.code', 'f.libelle as filiere', 'n.code as niveau', 'p.annee_id', 's.id as semestre_id', 's.libelle as sem_label']);
    }
}
