<?php

namespace App\Http\Controllers\Etudiants;

use App\Http\Controllers\Controller;
use App\Services\CartesEtudiants;
use App\Services\Fichiers;
use App\Services\Identite;
use App\Services\Parametres;
use App\Support\CodeBarres;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cartes d'étudiant — portage de pages/etudiants/carte_etudiant.php.
 * Trois modèles (paramètre carte_etudiant_modele) : administratif (défaut),
 * moderne, badge. Impression en planche A4 avec repères de coupe.
 */
class CartesController extends Controller
{
    public const MODELES = ['moderne', 'badge', 'administratif'];

    public function selection(): View
    {
        return view('etudiants.cartes.selection', [
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'anneeActive' => (int) DB::table('annees_academiques')->where('active', 1)->value('id'),
            'facultes' => DB::table('filieres')->whereNotNull('faculte')->where('faculte', '!=', '')->distinct()->orderBy('faculte')->pluck('faculte'),
            'etudiants' => DB::table('etudiants')->orderBy('nom')->orderBy('prenoms')->limit(500)->get(['id', 'nom', 'prenoms', 'numero']),
        ]);
    }

    public function imprimer(Request $request, Parametres $parametres, Identite $identite, CartesEtudiants $registre, Fichiers $fichiers): View|RedirectResponse
    {
        if ($request->has('masse')) {
            $anneeId = (int) $request->query('annee_id') ?: (int) DB::table('annees_academiques')->where('active', 1)->value('id');
            $faculte = trim((string) $request->query('faculte'));
            $etudiants = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('etudiants as e', 'e.id', '=', 'i.etudiant_id')
                ->where('p.annee_id', $anneeId)->where('i.statut', 'inscrit')
                ->when($faculte !== '', fn ($q) => $q->where('f.faculte', $faculte))
                ->distinct()->orderBy('e.nom')->orderBy('e.prenoms')->get(['e.*']);
        } elseif ($id = (int) $request->query('id')) {
            $etudiants = DB::table('etudiants')->where('id', $id)->get();
        } else {
            return redirect()->route('carte_etudiant');
        }

        $modele = $parametres->brut('carte_etudiant_modele', 'administratif');
        $modele = in_array($modele, self::MODELES, true) ? $modele : 'administratif';
        $institution = $parametres->brut('institution_nom', 'Université Djibo Hamani de Tahoua');
        $contact = implode(' · ', array_filter(array_map(fn ($k) => trim($parametres->brut($k)), ['institution_adresse', 'institution_tel', 'institution_email'])));
        $dossierPhotos = $fichiers->dossier('photos');

        $cartes = [];
        foreach ($etudiants as $et) {
            $ins = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->leftJoin('facultes as fac', 'fac.id', '=', 'f.faculte_id')
                ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                ->where('i.etudiant_id', $et->id)->where('i.statut', 'inscrit')->orderByDesc('a.libelle')
                ->first(['f.libelle as filiere', DB::raw('COALESCE(fac.libelle, f.faculte) AS faculte'), 'n.code as niveau', 'a.libelle as annee']);
            $annee = $ins->annee ?? date('Y').'-'.(date('Y') + 1);
            $filiere = $ins->filiere ?? '—';
            $carte = $registre->enregistrer($et, $annee, $filiere);

            $cartes[] = [
                'et' => $et,
                'photo' => ($et->photo && is_file($dossierPhotos.DIRECTORY_SEPARATOR.basename($et->photo))) ? route('fichiers.photo', basename($et->photo)) : null,
                'annee' => $annee,
                'filiere' => $filiere,
                'faculte' => $ins->faculte ?? '—',
                'niveau' => $ins->niveau ?? '—',
                'nie' => $et->numero ?: '—',
                'ddn' => Format::dateFR($et->date_naissance),
                'lieu' => $et->lieu_naissance ?? '',
                // L'original affichait toujours « Nigérienne »
                'nationalite' => $et->nationalite ?: 'Nigérienne',
                'numero' => $carte['numero'],
                'emission' => $carte['date_emission'] ? date('d/m/Y', strtotime($carte['date_emission'])) : date('d/m/Y'),
                'urlVerification' => app(\App\Services\CleVerification::class)->url($carte['numero']),
                'codeBarres' => CodeBarres::code128b($carte['numero']),
            ];
        }

        $couleur = collect([$parametres->brut('couleur_marque'), app('App\Universites\UniversiteCourante')->get()->couleur])
            ->first(fn ($c) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $c)) ?? '#12345a';

        return view('etudiants.cartes.impression', [
            'cartes' => $cartes,
            'masse' => $request->has('masse'),
            'modele' => $modele,
            'institution' => $institution,
            'contact' => $contact,
            'logo' => $identite->logos()['gauche'],
            'filigrane' => $identite->logos()['gauche'],
            'cachet' => $identite->urlImageOfficielle('cachet'),
            'signature' => $identite->urlImageOfficielle('signature'),
            'c' => [
                'marque' => $couleur,
                'plusFonce' => self::melanger($couleur, 0.50, true),
                'fonce' => self::melanger($couleur, 0.65, true),
                'clair' => self::melanger($couleur, 0.65, false),
            ],
        ]);
    }

    /** Mélange vers le noir ou le blanc — même calcul que l'original (color-mix sRGB). */
    public static function melanger(string $hex, float $part, bool $versNoir): string
    {
        $cible = $versNoir ? 0 : 255;
        $canaux = array_map(fn ($i) => (int) round(hexdec(substr($hex, $i, 2)) * $part + $cible * (1 - $part)), [1, 3, 5]);

        return vsprintf('#%02x%02x%02x', $canaux);
    }
}
