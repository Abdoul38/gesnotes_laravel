<?php

namespace App\Http\Controllers\Inscriptions;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Identite;
use App\Services\ListesInscrits;
use App\Services\Parametres;
use App\Support\Classeur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Listes des inscrits — portage de liste_inscrits.php, liste_inscrits_pdf.php
 * (page A4 imprimable) et liste_inscrits_excel.php (une feuille par groupe
 * et un récapitulatif).
 *
 * Différences voulues :
 *  - PDF et Excel limités à la faculté de l'agent, comme la page ;
 *  - Excel : un matricule « 00012 » garde ses zéros (il était écrit comme
 *    le nombre 12) ; fichier toujours lisible (noms de feuilles uniques) ;
 *  - PDF : logo de la faculté choisie aussi pour une liste « par faculté »
 *    (il n'était trouvé que pour une filière) ;
 *  - Excel : nom de l'université tiré des paramètres (il était écrit en
 *    dur « Université Djibo Hamani de Tahoua » pour toutes les universités).
 */
class ListesInscritsController extends Controller
{
    public function __construct(private readonly ListesInscrits $listes, private readonly Perimetre $perimetre) {}

    public function index(Request $request): View
    {
        $c = $this->listes->criteres($request);
        $faculte = $this->perimetre->faculte($request->user());
        $groupes = $this->listes->groupes($c, $faculte);
        $tous = collect($groupes)->flatten(1);
        $filieres = DB::table('filieres')->when($faculte, fn ($q) => $q->where('faculte_id', $faculte));

        return view('inscriptions.listes', [
            'c' => $c,
            'groupes' => $groupes,
            'colonnes' => ListesInscrits::colonnes($c),
            'total' => $tous->count(),
            'hommes' => $tous->where('sexe', 'M')->count(),
            'femmes' => $tous->where('sexe', 'F')->count(),
            'parametres' => ListesInscrits::parametres($c),
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'facultes' => (clone $filieres)->whereNotNull('faculte')->where('faculte', '!=', '')->distinct()->orderBy('faculte')->pluck('faculte'),
            'departements' => (clone $filieres)->whereNotNull('departement')->where('departement', '!=', '')->distinct()->orderBy('departement')->pluck('departement'),
            'filieres' => (clone $filieres)->orderBy('faculte')->orderBy('departement')->orderBy('libelle')->get(['id', 'libelle']),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(),
            'semestres' => DB::table('semestres')->orderBy('code')->get(),
        ]);
    }

    public function pdf(Request $request, Identite $identite, Parametres $parametres): View|RedirectResponse
    {
        $c = $this->listes->criteres($request);
        if (! $request->query('annee_id')) {
            return redirect()->route('liste_inscrits');
        }
        $faculte = $this->perimetre->faculte($request->user());
        $groupes = $this->listes->groupes($c, $faculte);
        $tous = collect($groupes)->flatten(1);

        return view('inscriptions.listes_pdf', [
            'c' => $c,
            'groupes' => $groupes,
            'colonnes' => ListesInscrits::colonnes($c),
            'titre' => $this->listes->titre($c),
            'annee' => DB::table('annees_academiques')->where('id', $c['annee_id'])->value('libelle'),
            'total' => $tous->count(),
            'hommes' => $tous->where('sexe', 'M')->count(),
            'femmes' => $tous->where('sexe', 'F')->count(),
            'logos' => $identite->logos($this->listes->faculteDesCriteres($c, $faculte)),
            'institution' => $parametres->valeur('institution_nom', 'Université Djibo Hamani de Tahoua'),
            'service' => $parametres->valeur('institution_service', 'Service de la Scolarité'),
            'bp' => $parametres->valeur('institution_bp', 'BP 255 Tahoua, Niger'),
            'retour' => route('liste_inscrits', ListesInscrits::parametres($c)),
        ]);
    }

    public function excel(Request $request): BinaryFileResponse|RedirectResponse
    {
        $c = $this->listes->criteres($request);
        if (! $request->query('annee_id')) {
            return redirect()->route('liste_inscrits');
        }
        $groupes = $this->listes->groupes($c, $this->perimetre->faculte($request->user()));
        $annee = (string) DB::table('annees_academiques')->where('id', $c['annee_id'])->value('libelle');
        $titre = $this->listes->titre($c);
        $col = ListesInscrits::colonnes($c);

        $entetes = ['#', 'Matricule', 'Nom', 'Prénoms', 'Sexe'];
        $largeurs = [5, 13, 22, 22, 6];
        foreach (['filiere' => ['Filière', 28], 'niveau' => ['Niveau', 8], 'semestres' => ['Semestres', 28]] as $cle => [$libelle, $l]) {
            if ($col[$cle]) {
                $entetes[] = $libelle;
                $largeurs[] = $l;
            }
        }
        $nb = count($entetes);
        $pleine = fn (string $v, int $style, int $hauteur) => ['height' => $hauteur, 'merges' => [[1, $nb]],
            'cells' => [['v' => $v, 'style' => $style], ...array_fill(0, $nb - 1, ['v' => '', 'style' => $style])]];

        $classeur = new Classeur;
        $recap = [];
        foreach ($groupes as $libelle => $ets) {
            $h = collect($ets)->where('sexe', 'M')->count();
            $f = collect($ets)->where('sexe', 'F')->count();
            $recap[] = [$libelle, count($ets), $h, $f];
            $lignes = [
                $pleine(mb_strtoupper($this->institution()).' — SERVICE DE LA SCOLARITÉ', 1, 26),
                $pleine("Année : $annee  |  $titre  |  ".($c['pedagogique'] ? 'Inscription pédagogique' : 'Inscription scolarité'), 3, 14),
                $pleine("$libelle   —   ".count($ets)." étudiant(s)  |  $h Homme(s)  |  $f Femme(s)", 2, 18),
                ['height' => 4, 'cells' => array_fill(0, $nb, ['v' => '', 'style' => 0])],
                ['height' => 18, 'cells' => array_map(fn ($e) => ['v' => $e, 'style' => 5], $entetes)],
            ];
            foreach ($ets as $i => $et) {
                $fond = $i % 2 === 0 ? 0 : 10;
                $cellules = [
                    ['v' => $i + 1, 'style' => $fond], ['v' => (string) ($et->matricule ?? '—'), 'style' => 11],
                    ['v' => (string) $et->nom, 'style' => 11], ['v' => (string) $et->prenoms, 'style' => $fond],
                    ['v' => (string) $et->sexe, 'style' => $et->sexe === 'M' ? 6 : ($et->sexe === 'F' ? 7 : $fond)],
                ];
                if ($col['filiere']) {
                    $cellules[] = ['v' => (string) ($et->filiere ?? '—'), 'style' => $fond];
                }
                if ($col['niveau']) {
                    $cellules[] = ['v' => (string) ($et->niveau ?? '—'), 'style' => 8];
                }
                if ($col['semestres']) {
                    $cellules[] = ['v' => (string) ($et->semestres ?? '—'), 'style' => 9];
                }
                $lignes[] = ['height' => 16, 'cells' => $cellules];
            }
            $lignes[] = $pleine('TOTAL : '.count($ets)." étudiant(s)  —  $h Homme(s)  —  $f Femme(s)", 4, 16);
            $classeur->feuille($libelle, $lignes, $largeurs);
        }

        if (count($groupes) > 1) {
            $vide = fn (int $s) => ['v' => '', 'style' => $s];
            $lignes = [
                ['height' => 26, 'merges' => [[1, 4]], 'cells' => [['v' => "RÉCAPITULATIF — $annee", 'style' => 1], $vide(1), $vide(1), $vide(1)]],
                ['height' => 14, 'merges' => [[1, 4]], 'cells' => [['v' => $titre, 'style' => 3], $vide(3), $vide(3), $vide(3)]],
                ['height' => 4, 'cells' => [$vide(0), $vide(0), $vide(0), $vide(0)]],
                ['height' => 18, 'cells' => array_map(fn ($e) => ['v' => $e, 'style' => 5], ['Groupe', 'Total', 'Hommes', 'Femmes'])],
            ];
            foreach ($recap as $i => [$g, $t, $h, $f]) {
                $fond = $i % 2 === 0 ? 0 : 10;
                $lignes[] = ['height' => 15, 'cells' => [['v' => (string) $g, 'style' => $fond], ['v' => $t, 'style' => $fond], ['v' => $h, 'style' => 6], ['v' => $f, 'style' => 7]]];
            }
            $lignes[] = ['height' => 16, 'cells' => array_map(fn ($v) => ['v' => $v, 'style' => 4], ['TOTAL', array_sum(array_column($recap, 1)), array_sum(array_column($recap, 2)), array_sum(array_column($recap, 3))])];
            $classeur->feuille('Récapitulatif', $lignes, [40, 12, 12, 12]);
        }

        $nom = 'liste_inscrits_'.preg_replace('/[^a-z0-9]/', '_', strtolower($annee)).'_'.date('Ymd').'.xlsx';

        return response()->download($classeur->enregistrer(), $nom, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, must-revalidate',
        ])->deleteFileAfterSend();
    }

    private function institution(): string
    {
        return app(Parametres::class)->valeur('institution_nom', 'Université Djibo Hamani de Tahoua');
    }
}
