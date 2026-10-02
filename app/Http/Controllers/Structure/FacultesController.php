<?php

namespace App\Http\Controllers\Structure;

use App\Http\Controllers\Controller;
use App\Services\Fichiers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Facultés — portage de pages/structure/facultes.php. */
class FacultesController extends Controller
{
    private const COULEUR_DEFAUT = '#1A3A5C';

    public function index(Fichiers $fichiers): View
    {
        $facultes = DB::select('
            SELECT f.*,
                (SELECT COUNT(*) FROM utilisateurs u WHERE u.faculte_id=f.id) AS nb_users,
                (SELECT COUNT(*) FROM filieres fl WHERE fl.faculte_id=f.id) AS nb_filieres
            FROM facultes f ORDER BY f.libelle
        ');
        foreach ($facultes as $f) {
            $f->logo_present = $f->logo && is_file($fichiers->dossier('logos').DIRECTORY_SEPARATOR.basename($f->logo));
            $f->couleur = $this->couleur($f->couleur);
        }

        return view('structure.facultes', ['facultes' => $facultes]);
    }

    public function enregistrer(Request $request, Fichiers $fichiers): RedirectResponse
    {
        $id = (int) $request->input('id');
        $code = mb_strtoupper(trim((string) $request->input('code')));
        $libelle = trim((string) $request->input('libelle'));
        $ville = trim((string) $request->input('ville', 'Tahoua'));
        // La couleur est insérée dans des styles : seul un code #RRGGBB est accepté.
        $couleur = $this->couleur(trim((string) $request->input('couleur')));

        if ($code === '' || $libelle === '') {
            return $this->retour('erreur', 'Code et libellé requis.');
        }
        if (DB::table('facultes')->where('code', $code)->where('id', '!=', $id)->exists()) {
            return $this->retour('erreur', "Le code «{$code}» est déjà utilisé par une autre faculté.");
        }

        // Logo actuel : relu en base (l'ancien formulaire le renvoyait dans
        // un champ caché, modifiable par l'utilisateur).
        $logo = $id ? DB::table('facultes')->where('id', $id)->value('logo') : null;
        if ($request->hasFile('logo')) {
            $nouveau = $fichiers->enregistrerImage($request->file('logo'), 'logos', 'logo_'.$code);
            if ($nouveau === null) {
                return $this->retour('erreur', 'Logo refusé : image PNG ou JPG de 2 Mo maximum.');
            }
            $logo = $nouveau;
        }

        $valeurs = ['code' => $code, 'libelle' => $libelle, 'logo' => $logo, 'couleur' => $couleur, 'ville' => $ville];
        if ($id) {
            DB::table('facultes')->where('id', $id)->update($valeurs);

            return $this->retour('succes', 'Faculté mise à jour.');
        }
        DB::table('facultes')->insert($valeurs);

        return $this->retour('succes', "Faculté «{$code}» créée.");
    }

    public function supprimer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');

        $nb = DB::table('utilisateurs')->where('faculte_id', $id)->count();
        if ($nb > 0) {
            return $this->retour('erreur', "Impossible — $nb utilisateur(s) lié(s) à cette faculté.");
        }
        // En plus de l'ancien contrôle : la suppression effaçait en cascade
        // les départements et laissait les filières sans faculté.
        $liens = array_filter([
            'filière(s)' => DB::table('filieres')->where('faculte_id', $id)->count(),
            'département(s)' => DB::table('departements')->where('faculte_id', $id)->count(),
        ]);
        if ($liens) {
            $detail = implode(', ', array_map(fn ($n, $quoi) => "$n $quoi", $liens, array_keys($liens)));

            return $this->retour('erreur', "Impossible — cette faculté a encore $detail.");
        }

        DB::table('facultes')->where('id', $id)->delete();

        return $this->retour('succes', 'Faculté supprimée.');
    }

    public function basculer(Request $request): RedirectResponse
    {
        DB::table('facultes')->where('id', (int) $request->input('id'))->update(['actif' => DB::raw('1-actif')]);

        return redirect()->route('facultes');
    }

    private function couleur(?string $valeur): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $valeur) ? $valeur : self::COULEUR_DEFAUT;
    }

    private function retour(string $type, string $message): RedirectResponse
    {
        return redirect()->route('facultes')->with($type, $message);
    }
}
