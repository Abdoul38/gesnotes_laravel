<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Reclamations;
use App\Support\Classeur;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Réclamations : liste (portage de pages/reclamations/reclamations.php) et
 * export Excel par matière (reclamations_export.php).
 *
 * Différences voulues :
 *  - l'export respecte la faculté / le département de l'agent (l'ancien
 *    exportait les réclamations de toute l'université) ;
 *  - export groupé par matière réelle (l'ancien regroupait par « code -
 *    libellé » : deux matières homonymes de promotions différentes se
 *    retrouvaient sur la même feuille) ;
 *  - le bouton d'export n'est affiché qu'aux rôles qui y ont droit ;
 *  - enseignant : seulement ses matières (voir Reclamations::requete).
 */
class ReclamationsController extends Controller
{
    private const PAR_PAGE = 25;

    public function __construct(private readonly Reclamations $reclamations) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $filtres = $this->filtres($request);
        $requete = $this->filtrer($this->reclamations->requete($u), $filtres);
        $total = (clone $requete)->count();
        $pagination = Pagination::calculer($total, self::PAR_PAGE, (int) $request->query('p', 1));

        return view('notes.reclamations.liste', [
            'filtres' => $filtres,
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'pvs' => DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                ->when($u->faculteId(), fn ($q, $f) => $q->where('f.faculte_id', $f))
                ->when($u->departementId(), fn ($q, $d) => $q->where('f.departement_id', $d))
                ->orderByDesc('ps.created_at')->limit(100)->get(['ps.id', 'ps.libelle']),
            'reclamations' => $requete->orderByDesc('r.created_at')->orderByDesc('r.id')->offset($pagination['offset'])->limit(self::PAR_PAGE)
                ->get(['r.*', 'e.nom', 'e.prenoms', 'e.numero as matricule', 'ps.libelle as pv_libelle', 'm.libelle as matiere',
                    'f.code as filiere_code', 'n.code as niveau', 'a.libelle as annee']),
            'total' => $total,
            'pagination' => $pagination,
            'compteurs' => $this->reclamations->requete($u)->groupBy('r.statut')->selectRaw('r.statut, COUNT(*) AS nb')->pluck('nb', 'statut'),
            'faculte' => $u->faculteId() ? DB::table('facultes')->find($u->faculteId()) : null,
            'departement' => $u->departementId() ? DB::table('departements')->where('id', $u->departementId())->value('libelle') : null,
            'peutExporter' => $this->reclamations->peutExporter($u),
        ]);
    }

    public function exporter(Request $request): BinaryFileResponse|RedirectResponse
    {
        $u = $request->user();
        if (! $this->reclamations->peutExporter($u)) {
            return redirect()->route('accueil')->with('erreur', 'Accès non autorisé.');
        }
        $filtres = $this->filtres($request);
        $lignes = $this->filtrer($this->reclamations->requete($u), $filtres)
            ->orderBy('m.libelle')->orderBy('m.id')->orderBy('e.nom')->orderBy('e.prenoms')
            ->get(['r.id', 'r.matiere_id', 'e.nom', 'e.prenoms', 'e.numero as matricule', 'm.libelle as matiere', 'm.code as matiere_code',
                'ps.libelle as pv_libelle', 'f.libelle as filiere', 'n.code as niveau', 'a.libelle as annee',
                'r.note_originale', 'r.note_corrigee', 'r.statut', 'r.motif', 'r.created_at', 'u.nom as traitee_par_nom']);
        if ($lignes->isEmpty()) {
            return redirect()->route('reclamations', array_filter($filtres))->with('erreur', 'Aucune réclamation à exporter avec ces filtres.');
        }

        $classeur = new Classeur(self::STYLES);
        $parMatiere = $lignes->groupBy('matiere_id');
        $recap = [
            ['height' => 26, 'merges' => [[1, 5]], 'cells' => [['v' => 'RECAPITULATIF DES RECLAMATIONS PAR MATIERE', 'style' => 2]]],
            ['height' => 14, 'merges' => [[1, 5]], 'cells' => [['v' => 'Genere le '.date('d/m/Y à H:i')]]],
            ['height' => 6, 'merges' => [[1, 5]], 'cells' => [['v' => '']]],
            ['height' => 20, 'cells' => array_map(fn ($t) => ['v' => $t, 'style' => 1], ['Matiere', 'En attente', 'Fondees', 'Non fondees', 'TOTAL'])],
        ];
        foreach ($parMatiere as $groupe) {
            $recap[] = ['cells' => [
                ['v' => $this->libelleMatiere($groupe->first()), 'style' => 11],
                ['v' => $groupe->where('statut', 'en_attente')->count()],
                ['v' => $groupe->where('statut', 'fondee')->count()],
                ['v' => $groupe->whereIn('statut', ['non_fondee', 'rejetee'])->count()],
                ['v' => $groupe->count(), 'style' => 4],
            ]];
        }
        $recap[] = ['height' => 18, 'merges' => [[1, 4]], 'cells' => [
            ['v' => 'TOTAL GENERAL : '.$lignes->count().' reclamation(s)', 'style' => 9], ['style' => 9], ['style' => 9], ['style' => 9],
            ['v' => $lignes->count(), 'style' => 9],
        ]];
        $classeur->feuille('Recapitulatif', $recap, [42, 13, 13, 14, 10]);

        $libelles = ['en_attente' => 'En attente', 'fondee' => 'Fonde', 'non_fondee' => 'Non fondee', 'rejetee' => 'Rejetee'];
        $styles = ['fondee' => 5, 'non_fondee' => 6, 'rejetee' => 7];
        foreach ($parMatiere as $groupe) {
            $premier = $groupe->first();
            $feuille = [
                ['height' => 22, 'merges' => [[1, 9]], 'cells' => [['v' => mb_strtoupper($this->libelleMatiere($premier)), 'style' => 2]]],
                ['height' => 14, 'merges' => [[1, 9]], 'cells' => [['v' => "{$premier->filiere} - {$premier->niveau} | {$premier->annee} | PV : {$premier->pv_libelle}"]]],
                ['height' => 6, 'merges' => [[1, 9]], 'cells' => [['v' => '']]],
                ['height' => 20, 'cells' => array_map(fn ($t) => ['v' => $t, 'style' => 1],
                    ['N°', 'Matricule', 'Nom & Prenoms', 'Note originale', 'Note corrigee', 'Statut', 'Motif', 'Date depot', 'Traite par'])],
            ];
            foreach ($groupe->values() as $i => $r) {
                $feuille[] = ['cells' => [
                    ['v' => $i + 1],
                    ['v' => $r->matricule ?? '-'],
                    ['v' => $r->nom.' '.$r->prenoms, 'style' => 3],
                    ['v' => $r->note_originale === null ? '' : (float) $r->note_originale, 'style' => 4],
                    ['v' => $r->note_corrigee === null ? '' : (float) $r->note_corrigee, 'style' => 4],
                    ['v' => $libelles[$r->statut] ?? $r->statut, 'style' => $styles[$r->statut] ?? 8],
                    ['v' => $r->motif, 'style' => 12],
                    ['v' => $r->created_at ? date('d/m/Y', strtotime($r->created_at)) : '-'],
                    ['v' => $r->traitee_par_nom ?? '-'],
                ]];
            }
            $feuille[] = ['height' => 18, 'merges' => [[1, 9]], 'cells' => array_merge([['v' => 'Total : '.$groupe->count().' reclamation(s)', 'style' => 9]], array_fill(0, 8, ['style' => 9]))];
            $classeur->feuille($this->libelleMatiere($premier), $feuille, [5, 13, 28, 13, 13, 13, 42, 11, 16]);
        }

        return response()->download($classeur->enregistrer(), 'reclamations_par_matiere_'.date('Ymd_Hi').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-cache'])
            ->deleteFileAfterSend();
    }

    private function filtres(Request $request): array
    {
        $statut = (string) $request->query('statut', '');

        return [
            'annee_id' => (int) $request->query('annee_id'),
            'statut' => array_key_exists($statut, Reclamations::STATUTS) ? $statut : '',
            'pv_id' => (int) $request->query('pv_id'),
        ];
    }

    private function filtrer($requete, array $filtres)
    {
        return $requete
            ->when($filtres['annee_id'], fn ($q, $v) => $q->where('p.annee_id', $v))
            ->when($filtres['statut'], fn ($q, $v) => $q->where('r.statut', $v))
            ->when($filtres['pv_id'], fn ($q, $v) => $q->where('r.pv_id', $v));
    }

    private function libelleMatiere(object $r): string
    {
        return trim($r->matiere_code.' - '.$r->matiere, ' -');
    }

    /** Les 13 styles de l'ancien export (mêmes couleurs). */
    private const STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<fonts count="9"><font><sz val="10"/><name val="Arial"/></font><font><sz val="11"/><b/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
        .'<font><sz val="12"/><b/><color rgb="FF1A3A5C"/><name val="Arial"/></font><font><sz val="10"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><b/><name val="Arial"/></font><font><sz val="10"/><b/><color rgb="FF1B5E20"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><b/><color rgb="FFE65100"/><name val="Arial"/></font><font><sz val="10"/><color rgb="FF555555"/><name val="Arial"/></font>'
        .'<font><sz val="10"/><b/><color rgb="FF7B4F00"/><name val="Arial"/></font></fonts>'
        .'<fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF1A3A5C"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8F5E9"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF3E0"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF5F5F5"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF8E1"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FE"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFDCE9F7"/></patternFill></fill></fills>'
        .'<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border>'
        .'<border><left style="thin"><color rgb="FFCCCCCC"/></left><right style="thin"><color rgb="FFCCCCCC"/></right><top style="thin"><color rgb="FFCCCCCC"/></top><bottom style="thin"><color rgb="FFCCCCCC"/></bottom><diagonal/></border>'
        .'<border><left/><right/><top/><bottom style="medium"><color rgb="FF1A3A5C"/></bottom><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="13">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="2" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="6" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="7" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="8" fillId="6" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="4" fillId="7" borderId="2" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="4" fillId="8" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
        .'</cellXfs></styleSheet>';
}
