<?php

namespace App\Http\Controllers\Etudiants;

use App\Http\Controllers\Controller;
use App\Services\Fichiers;
use App\Services\Journal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use ZipArchive;

/**
 * Import des photos en masse — portage de pages/etudiants/import_photos.php.
 * Images directes et/ou ZIP ; chaque photo est rattachée à l'étudiant par
 * son nom de fichier (matricule, avec ou sans zéros en tête, ou NOM_PRENOMS).
 *
 * Différences voulues :
 *  - le contenu est vérifié (vraie image) et l'extension enregistrée est
 *    celle du type réel : un fichier quelconque renommé .jpg est refusé ;
 *  - la taille d'une entrée de ZIP est contrôlée AVANT de la décompresser ;
 *  - la recherche partielle par nom ne choisit plus « le premier venu » :
 *    si plusieurs étudiants correspondent, la photo est ignorée (avant, elle
 *    pouvait atterrir sur la fiche d'un autre étudiant) ; un nom de fichier
 *    vide (« .jpg ») ne correspond plus à personne.
 */
class ImportPhotosController extends Controller
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private const MAX_OCTETS = 5 * 1024 * 1024;

    /** @var array<string, object> */
    private array $parMatricule = [];

    /** @var array<string, object> */
    private array $parNom = [];

    public function __construct(private readonly Fichiers $fichiers, private readonly Journal $journal) {}

    public function formulaire(): View
    {
        return $this->vue();
    }

    public function importer(Request $request): View
    {
        $televerses = array_values(array_filter((array) $request->file('photos', []), fn ($f) => $f && $f->isValid()));
        if (! $televerses) {
            return $this->vue(['erreurs' => ['Veuillez sélectionner au moins une image ou un fichier ZIP.']]);
        }

        foreach (DB::table('etudiants')->get(['id', 'numero', 'nom', 'prenoms', 'photo']) as $et) {
            if ($et->numero) {
                $this->parMatricule[strtolower($et->numero)] = $et;
            }
            $this->parNom[strtolower(preg_replace('/\s+/', '_', $et->nom.'_'.$et->prenoms))] = $et;
            $this->parNom[strtolower(preg_replace('/\s+/', '_', $et->nom))] = $et;
        }

        $erreurs = [];
        $resultats = [];
        foreach ($televerses as $fichier) {
            $nom = basename($fichier->getClientOriginalName());
            $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));

            if ($extension === 'zip') {
                $zip = new ZipArchive;
                if ($zip->open($fichier->getRealPath()) !== true) {
                    $erreurs[] = "Impossible d'ouvrir le fichier ZIP « $nom ».";

                    continue;
                }
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entree = $zip->statIndex($i);
                    if (! $entree || str_ends_with($entree['name'], '/')) {
                        continue;
                    }
                    $nomEntree = basename($entree['name']);
                    if (! in_array(strtolower(pathinfo($nomEntree, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                        continue;
                    }
                    $resultats[] = $this->traiter($nomEntree, $entree['size'], fn () => $zip->getFromIndex($i));
                }
                $zip->close();
            } elseif (in_array($extension, self::EXTENSIONS, true)) {
                $resultats[] = $this->traiter($nom, $fichier->getSize(), fn () => file_get_contents($fichier->getRealPath()));
            } else {
                $resultats[] = ['statut' => 'error', 'fichier' => $nom, 'msg' => 'Format non pris en charge', 'photo' => null];
            }
        }

        $importees = count(array_filter($resultats, fn ($r) => $r['statut'] === 'ok'));
        $ignorees = count($resultats) - $importees;
        if ($importees > 0) {
            $this->journal->action('importer', 'etudiant', "Import de $importees photo(s)");
            session()->now('succes', "$importees photo(s) importée(s) avec succès".($ignorees ? " — $ignorees ignorée(s)" : '').'.');
        } elseif (! $erreurs) {
            $erreurs[] = 'Aucune photo importée. Vérifiez le format des noms de fichiers.';
        }

        return $this->vue(compact('erreurs', 'resultats', 'importees', 'ignorees'));
    }

    /** @param  callable(): (string|false)  $lire */
    private function traiter(string $nomFichier, int $taille, callable $lire): array
    {
        $resultat = ['fichier' => $nomFichier, 'photo' => null];
        $et = $this->trouver($nomFichier);
        if (! $et) {
            return $resultat + ['statut' => 'skip', 'msg' => $et === false ? 'Plusieurs étudiants correspondent' : 'Aucun étudiant trouvé'];
        }
        if ($taille > self::MAX_OCTETS) {
            return $resultat + ['statut' => 'error', 'msg' => 'Fichier trop volumineux (max 5Mo)'];
        }
        $contenu = $lire();
        if ($contenu === false) {
            return $resultat + ['statut' => 'error', 'msg' => 'Erreur lecture'];
        }
        $nouveau = $this->fichiers->enregistrerPhotoContenu($contenu, 'photos', 'photo_'.$et->id, self::MAX_OCTETS);
        if (! $nouveau) {
            return $resultat + ['statut' => 'error', 'msg' => "Ce fichier n'est pas une image valide"];
        }

        $ancienne = $et->photo ? $this->fichiers->dossier('photos').DIRECTORY_SEPARATOR.basename($et->photo) : null;
        DB::table('etudiants')->where('id', $et->id)->update(['photo' => $nouveau]);
        if ($ancienne && is_file($ancienne)) {
            @unlink($ancienne);
        }
        $et->photo = $nouveau;

        return ['statut' => 'ok', 'fichier' => $nomFichier, 'msg' => $et->nom.' '.$et->prenoms.' — '.$et->numero, 'photo' => route('fichiers.photo', $nouveau)];
    }

    /** L'étudiant désigné par le nom de fichier, null si aucun, false si ambigu. */
    private function trouver(string $nomFichier): object|false|null
    {
        $cle = preg_replace('/\s+/', '_', trim(strtolower(pathinfo($nomFichier, PATHINFO_FILENAME))));
        if ($cle === '') {
            return null;
        }
        $trouve = $this->parMatricule[$cle] ?? $this->parMatricule[str_pad($cle, 5, '0', STR_PAD_LEFT)] ?? $this->parNom[$cle] ?? null;
        if ($trouve) {
            return $trouve;
        }
        $candidats = [];
        foreach ($this->parNom as $nom => $et) {
            if (str_starts_with($nom, $cle) || str_starts_with($cle, $nom)) {
                $candidats[$et->id] = $et;
            }
        }

        return match (count($candidats)) {
            0 => null,
            1 => reset($candidats),
            default => false,
        };
    }

    private function vue(array $donnees = []): View
    {
        $avec = DB::table('etudiants')->whereNotNull('photo')->where('photo', '!=', '')->count();
        $total = DB::table('etudiants')->count();

        return view('etudiants.import_photos', $donnees + [
            'erreurs' => [],
            'resultats' => [],
            'importees' => 0,
            'ignorees' => 0,
            'avecPhoto' => $avec,
            'sansPhoto' => $total - $avec,
            'sansPhotoListe' => DB::table('etudiants')->where(fn ($q) => $q->whereNull('photo')->orWhere('photo', ''))
                ->orderBy('nom')->orderBy('prenoms')->limit(10)->get(['id', 'numero', 'nom', 'prenoms']),
        ]);
    }
}
