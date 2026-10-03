<?php

namespace App\Http\Controllers\Etudiants;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Support\Format;
use App\Support\Tableur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

/**
 * Import d'étudiants sans inscription — portage de
 * pages/etudiants/etudiant_importer.php. Les étudiants sont créés sans
 * matricule ni promotion ; l'inscription se fait ensuite au guichet.
 *
 * Deux formats reconnus automatiquement : le modèle « classique »
 * (19 colonnes, Nom en premier) et l'export du portail des candidatures.
 *
 * Différences voulues :
 *  - format Portail : colonnes repérées par leurs en-têtes (voir
 *    indexPortail) et en-tête cherché sous le titre du fichier Excel —
 *    l'ancien import décalait les champs et, en .xlsx, traitait l'export
 *    comme un modèle classique en perdant les 3 premiers candidats ;
 *  - les lignes Excel sont lues directement : l'ancien code les recollait
 *    en texte « ; » puis les redécoupait en devinant le séparateur, ce qui
 *    décalait les colonnes d'une ligne contenant beaucoup de virgules ;
 *  - CSV classique : une première ligne d'en-têtes (« Nom;Prénoms;… »)
 *    n'est plus importée comme un étudiant nommé « NOM » ;
 *  - année du bac, sexe, e-mail : une valeur invalide est laissée vide au
 *    lieu de faire échouer tout l'import ;
 *  - la filière doit exister et être active ; noms en majuscules
 *    accentuées (mb_strtoupper), comme la fiche étudiant ;
 *  - en cas d'erreur, le message technique n'est plus affiché.
 *  - les onglets « Ajouter manuellement » et « Format CSV » de l'ancienne
 *    page n'ouvraient plus aucun panneau (supprimés) : ils sont retirés,
 *    l'ajout manuel se fait par la fiche étudiant.
 */
class EtudiantImporterController extends Controller
{
    private const STATUTS = ['actif', 'suspendu', 'transfere', 'abandonne', 'diplome'];

    /** Mots qui signalent une ligne d'en-tête ou d'aide dans le modèle classique. */
    private const MOTS_EN_TETE = ['nom', 'name', 'prenom', 'date', 'naissance', 'sexe', 'genre', 'telephone',
        'email', 'nationalite', 'bac', 'universite', 'service', 'scolarite',
        'important', 'instruction', 'colonne', 'filiere', 'niveau', 'matricule',
        'etudiant', 'statut', 'etablissement', 'adresse', 'urgence', 'pere', 'mere',
        'portail', 'exemple'];

    public function __construct(private readonly Journal $journal) {}

    public function formulaire(): View
    {
        return view('etudiants.importer', [
            'filieres' => DB::table('filieres')->where('actif', 1)->orderBy('faculte')->orderBy('libelle')->get(['id', 'libelle', 'faculte']),
        ]);
    }

    public function importer(Request $request): RedirectResponse
    {
        $fichier = $request->file('fichier_import');
        $filiere = (int) $request->input('filiere_id_import');
        $erreurs = [];

        [$lignes, $format] = $fichier && $fichier->isValid()
            ? $this->lire($fichier->getRealPath(), strtolower($fichier->getClientOriginalExtension()))
            : [[], 'classique'];
        if (! $lignes) {
            $erreurs[] = 'Aucun fichier valide fourni ou fichier vide.';
        }
        if (! $filiere) {
            $erreurs[] = 'Veuillez sélectionner une filière.';
        } elseif (! DB::table('filieres')->where('id', $filiere)->where('actif', 1)->exists()) {
            $erreurs[] = 'Filière introuvable.';
        }
        if ($erreurs) {
            return back()->with('erreurs_import', $erreurs);
        }

        $colonnes = Schema::getColumnListing('etudiants');
        $importes = 0;
        $ignores = 0;
        try {
            DB::transaction(function () use ($lignes, $filiere, $colonnes, &$importes, &$ignores) {
                foreach ($lignes as $cols) {
                    $et = $this->etudiant($cols);
                    if ($et['nom'] === '') {
                        $ignores++;

                        continue;
                    }
                    if ($et['prenoms'] !== '' && $et['date_naissance']
                        && DB::table('etudiants')->where('nom', $et['nom'])->where('prenoms', $et['prenoms'])->where('date_naissance', $et['date_naissance'])->exists()) {
                        $ignores++;

                        continue;
                    }
                    $donnees = ['nom' => $et['nom'], 'prenoms' => $et['prenoms'], 'date_naissance' => $et['date_naissance'], 'lieu_naissance' => $et['lieu_naissance'] ?: null, 'filiere_id' => $filiere];
                    foreach (array_diff_key($et, $donnees) as $colonne => $valeur) {
                        if (in_array($colonne, $colonnes, true) && $valeur !== null && $valeur !== '') {
                            $donnees[$colonne] = $valeur;
                        }
                    }
                    DB::table('etudiants')->insert($donnees);
                    $importes++;
                }
            });
        } catch (Throwable $e) {
            Log::error('Import étudiants : '.$e->getMessage());

            return back()->with('erreurs_import', ["L'import a échoué : aucune ligne n'a été enregistrée. Vérifiez le contenu du fichier."]);
        }

        $this->journal->action('importer', 'etudiant', "Import de $importes étudiant(s) — format $format");

        return redirect()->route('etudiants')->with('succes', "$importes étudiant(s) importé(s) avec succès (format ".($format === 'portail' ? 'Portail' : 'classique').')'
            .($ignores ? " — $ignores doublon(s) ignoré(s)" : '').'.');
    }

    /**
     * Lignes à importer, toutes remises dans l'ordre du modèle classique.
     *
     * @return array{0: list<list<string>>, 1: string}
     */
    private function lire(string $chemin, string $extension): array
    {
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $brutes = Tableur::xlsx($chemin);
            $numerotees = true;
        } else {
            $brutes = [];
            foreach (explode("\n", Tableur::texteCsv($chemin)) as $ligne) {
                $ligne = trim($ligne);
                if ($ligne !== '' && ! str_starts_with($ligne, '#')) {
                    $brutes[] = array_map('trim', str_getcsv($ligne, Tableur::separateur($ligne), '"', ''));
                }
            }
            $numerotees = false;
        }

        $brutes = array_filter($brutes, fn ($cols) => (bool) array_filter($cols, fn ($v) => trim((string) $v) !== ''));

        // Export du portail : sa ligne d'en-têtes est cherchée dans les
        // premières lignes (le fichier Excel commence par un titre et une
        // date de génération, que l'ancienne détection prenait pour l'en-tête).
        $index = null;
        foreach (array_slice($brutes, 0, 10, true) as $numero => $cols) {
            if (self::estPortail($cols) && isset(($candidat = self::indexPortail($cols))['nom'])) {
                $index = $candidat;
                $debut = $numero;
                break;
            }
        }
        if ($index !== null) {
            $lignes = [];
            foreach ($brutes as $numero => $cols) {
                if ($numero > $debut && ($ligne = self::depuisPortail($cols, $index))[0] !== '') {
                    $lignes[] = $ligne;
                }
            }

            return [$lignes, 'portail'];
        }

        $lignes = [];
        $premiere = true;
        foreach ($brutes as $numero => $cols) {
            // Modèle Excel : 7 lignes de titre et d'aide avant les données ;
            // CSV : seule une première ligne d'en-têtes est sautée.
            $sauter = $numerotees ? ($numero <= 7 || $this->estEnTete($cols)) : ($premiere && $this->estEnTete($cols));
            $premiere = false;
            if (! $sauter) {
                $lignes[] = $cols;
            }
        }

        return [$lignes, 'classique'];
    }

    private function estEnTete(array $cols): bool
    {
        $premiere = mb_strtolower(trim($cols[0] ?? ''));
        foreach (self::MOTS_EN_TETE as $mot) {
            if (str_contains($premiere, $mot)) {
                return true;
            }
        }

        return (bool) preg_match('/[*!():\/\[\]#@⚠]/u', $cols[0] ?? '');
    }

    /** En-tête de l'export du portail : « N° Dossier » / « N° Dépôt » en tête, ou « Nom » en 2e colonne. */
    public static function estPortail(array $cols): bool
    {
        $c0 = mb_strtolower(trim($cols[0] ?? ''));
        $c1 = mb_strtolower(trim($cols[1] ?? ''));

        return str_contains($c0, 'dossier') || str_contains($c0, 'dép') || str_contains($c1, 'dép') || $c1 === 'nom';
    }

    /**
     * Position de chaque colonne utile dans l'export du portail, lue sur sa
     * ligne d'en-têtes. L'ancien code supposait des positions fixes (Genre
     * en 8e colonne, bac en 11e…) qui ne correspondaient plus à l'export
     * actuel (Adresse, Père, Mère, Urgence, Lieu BAC ajoutés) : adresse et
     * parents finissaient dans les champs du bac.
     *
     * @return array<string, int>
     */
    public static function indexPortail(array $entetes): array
    {
        $noms = [
            'dossier' => ['n° dossier'], 'depot' => ['n° dépôt', 'n° depot'],
            'nom' => ['nom'], 'prenoms' => ['prénom', 'prénoms', 'prenom', 'prenoms'],
            'ddn' => ['date naissance'], 'lieu' => ['lieu naissance'], 'nationalite' => ['nationalité', 'nationalite'],
            'sexe' => ['sexe', 'genre'], 'telephone' => ['téléphone', 'telephone'], 'email' => ['email', 'e-mail'],
            'adresse' => ['adresse'], 'pere' => ['nom père', 'nom pere'], 'mere' => ['nom mère', 'nom mere'],
            'urgence' => ['tél urgence', 'tel urgence'], 'bac_serie' => ['type bac'], 'bac_annee' => ['année bac', 'annee bac'],
            'bac_mention' => ['mention bac'], 'etablissement' => ['établissement bac', 'établissement', 'etablissement'],
            'statut' => ['statut'],
        ];
        $index = [];
        foreach ($entetes as $i => $entete) {
            $entete = str_replace('.', '', mb_strtolower(trim((string) $entete)));
            foreach ($noms as $cle => $variantes) {
                if (! isset($index[$cle]) && in_array($entete, $variantes, true)) {
                    $index[$cle] = $i;
                }
            }
        }

        return $index;
    }

    /** Ligne de l'export du portail → ordre du modèle classique. */
    public static function depuisPortail(array $p, array $index): array
    {
        $v = fn (string $cle) => isset($index[$cle]) ? trim((string) ($p[$index[$cle]] ?? '')) : '';
        $sexe = match (mb_strtolower($v('sexe'))) {
            'masculin', 'm' => 'M',
            'féminin', 'feminin', 'f' => 'F',
            default => $v('sexe'),
        };

        return [
            $v('nom'), $v('prenoms'), $sexe, $v('ddn'), $v('lieu'), $v('nationalite') ?: 'Nigérienne',
            $v('telephone'), $v('email'), $v('adresse'), $v('pere'), $v('mere'), $v('urgence'),
            $v('bac_serie'), $v('bac_annee'), $v('bac_mention'), $v('etablissement'),
            mb_strtolower($v('statut')), // statut du dossier (« accepte »…) → actif
            $v('dossier'), $v('depot'),
        ];
    }

    /** Colonnes du modèle classique → valeurs de la table etudiants. */
    private function etudiant(array $cols): array
    {
        $c = fn (int $i) => trim((string) ($cols[$i] ?? ''));
        $sexe = mb_strtoupper($c(2));
        $statut = mb_strtolower($c(16));
        $email = mb_strtolower($c(7));

        return [
            'nom' => mb_strtoupper($c(0)),
            'prenoms' => mb_convert_case(mb_strtolower($c(1)), MB_CASE_TITLE),
            'date_naissance' => Format::dateImport($c(3)),
            'lieu_naissance' => $c(4),
            'numero_dossier' => mb_strtoupper($c(17)) ?: null,
            'numero_depot' => mb_strtolower($c(18)) ?: null,
            'sexe' => in_array($sexe, ['M', 'F'], true) ? $sexe : null,
            'nationalite' => $c(5) ?: 'Nigérienne',
            'telephone' => $c(6),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'adresse' => $c(8),
            'nom_pere' => $c(9),
            'nom_mere' => $c(10),
            'tel_urgence' => $c(11),
            'bac_serie' => $c(12),
            'bac_annee' => preg_match('/^(19|20)\d\d$/', $c(13)) ? $c(13) : null,
            'bac_mention' => $c(14),
            'etablissement_origine' => $c(15),
            'statut_etudiant' => in_array($statut, self::STATUTS, true) ? $statut : 'actif',
        ];
    }
}
