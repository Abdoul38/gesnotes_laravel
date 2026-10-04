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
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Diplôme (DUT, Licence, Master, A4 paysage) — portage de
 * pages/documents/diplome.php. Les données viennent du parcours validé ;
 * pour un Master antérieur au logiciel (aucun semestre de Master trouvé),
 * elles sont saisies une fois.
 *
 * Différences voulues :
 *  - réservé aux rôles des autres documents officiels (admin, secrétaire,
 *    sc_centrale, sc_entite), étudiant de la faculté de l'agent ; l'ancienne
 *    page n'avait aucun contrôle de rôle ;
 *  - saisie manuelle du Master : formulaire POST (jeton CSRF), champs
 *    contrôlés, refusée si le Master est détectable dans les résultats, et
 *    elle ne remplace JAMAIS un diplôme déjà enregistré (avant, un simple lien
 *    ?niveau=master&confirme=1&mention_grade=… créait ou réécrivait un
 *    diplôme que la page publique déclarait « authentique ») ;
 *  - DUT seulement pour un étudiant passé par DUT1 / DUT2 (la liste le
 *    vérifiait, pas le diplôme) ; Licence sans semestres de Master ;
 *  - numéro attribué sous verrou (voir RegistreDocuments).
 */
class DiplomeController extends Controller
{
    private const NIVEAUX = ['dut' => 'DU', 'licence' => 'LI', 'master' => 'MA'];

    private const MENTIONS = ['Passable', 'Assez Bien', 'Bien', 'Très Bien'];

    public function __construct(
        private readonly Parcours $parcours,
        private readonly RegistreDocuments $registre,
        private readonly Perimetre $perimetre,
        private readonly Journal $journal,
    ) {}

    public function afficher(Request $request, Identite $identite, Parametres $parametres, Menu $menu): View|RedirectResponse
    {
        $niveau = (string) $request->query('niveau');
        $et = $this->etudiant($request, $niveau);
        if ($et instanceof RedirectResponse) {
            return $et;
        }

        $bilan = $this->parcours->bilan($this->parcours->semestres($et->id), $niveau, $manquant);
        $existant = $this->registre->existant('diplomes_emis', ['etudiant_id' => $et->id, 'niveau' => $niveau]);
        if ($bilan) {
            $professionnelle = $bilan['professionnelle'];
            $d = [
                'domaine' => $bilan['dernier']->domaine ?: $bilan['filiere'],
                'mention_academique' => $bilan['dernier']->mention_academique ?: '',
                'specialite' => $bilan['filiere'],
                'mention_grade' => $bilan['mention'],
                'moyenne' => $bilan['moyenne'],
                'session_libelle' => 'Année académique '.$bilan['annee'],
                'type' => $niveau === 'master' ? ($professionnelle ? 'Professionnel' : 'Recherche') : '',
                'credits' => $niveau === 'licence' ? 180 : 120,
            ];
            $faculte = (int) ($bilan['dernier']->faculte_id ?: $et->faculte_id) ?: null;
        } elseif ($niveau === 'master' && ! $this->masterTrouve($et->id)) {
            // Master antérieur au logiciel : saisie manuelle (ou réimpression de celle déjà enregistrée)
            return view('documents.diplome_master_saisie', ['et' => $et, 'existant' => $existant, 'mentions' => self::MENTIONS]);
        } else {
            return redirect()->route('diplomes')->with('erreur', $manquant === 'DUT'
                ? "Impossible de générer ce diplôme : l'étudiant n'a pas suivi de cycle DUT."
                : "Impossible de générer ce diplôme : le semestre $manquant n'est pas (ou plus) validé pour cet étudiant.");
        }

        return $this->rendre($et, $niveau, $d, $professionnelle, $faculte, $identite, $parametres, $menu);
    }

    /** Saisie manuelle d'un Master antérieur au logiciel. */
    public function enregistrerMaster(Request $request, Identite $identite, Parametres $parametres, Menu $menu): View|RedirectResponse
    {
        $et = $this->etudiant($request, 'master');
        if ($et instanceof RedirectResponse) {
            return $et;
        }
        if ($this->masterTrouve($et->id)) {
            return redirect()->route('diplome', ['et_id' => $et->id, 'niveau' => 'master'])
                ->with('erreur', 'Des résultats de Master existent pour cet étudiant : le diplôme est établi à partir de ces résultats.');
        }
        $existant = $this->registre->existant('diplomes_emis', ['etudiant_id' => $et->id, 'niveau' => 'master']);
        $regles = [
            'type_master' => 'required|in:Professionnel,Recherche',
            'credits' => 'required|integer|min:1|max:300',
        ];
        if (! $existant) {
            $regles += [
                'domaine' => 'required|string|max:150',
                'mention_master' => 'required|string|max:150',
                'specialite' => 'required|string|max:200',
                'session' => 'required|string|max:100',
                'mention_grade' => 'required|in:'.implode(',', self::MENTIONS),
            ];
        }
        $validation = Validator::make($request->all(), $regles);
        if ($validation->fails()) {
            return back()->withInput()->with('erreur', 'Formulaire incomplet ou invalide : '.$validation->errors()->first());
        }
        $v = $validation->validated();

        // Un diplôme déjà enregistré n'est jamais réécrit : on réimprime ce qui a été enregistré
        $d = $existant
            ? ['domaine' => $existant->domaine, 'mention_academique' => $existant->mention_academique, 'specialite' => $existant->specialite,
                'mention_grade' => $existant->mention_grade, 'moyenne' => $existant->moyenne, 'session_libelle' => $existant->session_libelle]
            : ['domaine' => trim($v['domaine']), 'mention_academique' => trim($v['mention_master']), 'specialite' => trim($v['specialite']),
                'mention_grade' => $v['mention_grade'], 'moyenne' => null, 'session_libelle' => trim($v['session'])];
        $d += ['type' => $v['type_master'], 'credits' => (int) $v['credits']];

        return $this->rendre($et, 'master', $d, $v['type_master'] === 'Professionnel', $et->faculte_id ? (int) $et->faculte_id : null,
            $identite, $parametres, $menu, manuel: ! $existant);
    }

    private function rendre(object $et, string $niveau, array $d, bool $professionnelle, ?int $faculte, Identite $identite, Parametres $parametres, Menu $menu, bool $manuel = false): View
    {
        $numero = $this->registre->enregistrer('diplomes_emis', 'D'.date('y').'-'.self::NIVEAUX[$niveau].'-',
            ['etudiant_id' => $et->id, 'niveau' => $niveau],
            ['nom' => $et->nom, 'prenoms' => $et->prenoms, 'domaine' => $d['domaine'] ?: null, 'mention_academique' => $d['mention_academique'] ?: null,
                'specialite' => $d['specialite'] ?: null, 'mention_grade' => $d['mention_grade'] ?: null, 'moyenne' => $d['moyenne'],
                'session_libelle' => $d['session_libelle'] ?: null]);
        $this->journal->action('creer', 'diplome', "Diplôme généré : $numero — {$et->nom} {$et->prenoms}".($manuel ? ' (saisie manuelle du Master)' : ''), (int) $et->id);

        $visas = array_values(array_filter(array_map('trim', explode("\n", $parametres->valeur('visa_diplome_'.$niveau)))));
        $logos = $identite->logos($faculte);

        return view('documents.diplome', [
            'et' => $et,
            'd' => $d,
            'numero' => $numero,
            'niveauLabel' => ['dut' => 'DUT', 'licence' => $professionnelle ? 'LICENCE PROFESSIONNELLE' : 'LICENCE', 'master' => 'MASTER'][$niveau],
            'visas' => $visas,
            'signataire' => $identite->signataireDiplome($faculte),
            'logos' => $logos,
            'armoirie' => $identite->imageParametre('armoirie_niger') ?? $logos['droite'],
            'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
            'faculteDefaut' => $parametres->valeur('institution_faculte_defaut'),
            'ville' => $parametres->valeur('institution_ville', 'Tahoua'),
            'urlVerification' => app(\App\Services\CleVerification::class)->url($numero),
        ]);
    }

    /** Étudiant demandé, dans le périmètre de l'agent, ou redirection. */
    private function etudiant(Request $request, string $niveau): object
    {
        $id = (int) $request->input('et_id');
        if (! $id || ! array_key_exists($niveau, self::NIVEAUX)) {
            return redirect()->route('diplomes')->with('erreur', 'Paramètres invalides.');
        }
        $et = DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')->where('e.id', $id)->first(['e.*', 'f.faculte_id']);
        if (! $et || ! $this->perimetre->etudiant($request->user(), $id)) {
            return redirect()->route('diplomes')->with('erreur', 'Étudiant introuvable.');
        }

        return $et;
    }

    /** Au moins un semestre de Master validé dans les résultats. */
    private function masterTrouve(int $etudiantId): bool
    {
        return $this->parcours->semestres($etudiantId)['master'] !== [];
    }
}
