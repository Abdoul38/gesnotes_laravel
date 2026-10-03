<?php

namespace App\Http\Controllers\Documents;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Registre des documents émis (diplômes, attestations, relevés,
 * certificats, cartes), en lecture seule — portage de
 * pages/documents/registre_diplomes.php.
 *
 * Différences voulues : documents des étudiants de la faculté de l'agent
 * seulement (toute l'université avant) ; pagination (tout le registre était
 * chargé en une page) ; plus de création de tables à l'exécution.
 */
class RegistreDiplomesController extends Controller
{
    private const PAR_PAGE = 50;

    /** type => [table, colonne affichée en « Filière / Spécialité »] */
    private const REGISTRES = [
        'diplome' => ['diplomes_emis', 'specialite'],
        'attestation' => ['attestations_emises', 'filiere'],
        'releve' => ['releves_emis', 'semestre'],
        'certificat' => ['certificats_emis', 'filiere'],
        'carte' => ['cartes_emises', 'filiere'],
    ];

    public function __invoke(Request $request, Perimetre $perimetre): View
    {
        $faculte = $perimetre->faculte($request->user());
        $q = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type_doc', '');
        $type = isset(self::REGISTRES[$type]) ? $type : '';

        $requetes = [];
        foreach (self::REGISTRES as $t => [$table, $champ]) {
            if ($type !== '' && $type !== $t) {
                continue;
            }
            $requetes[] = DB::table("$table as r")
                ->select(['r.numero', 'r.etudiant_id', 'r.nom', 'r.prenoms', 'r.date_emission', DB::raw("'$t' AS type_doc"), DB::raw("r.$champ AS detail"),
                    DB::raw($t === 'diplome' ? 'r.niveau AS sous_type' : ($t === 'attestation' ? 'r.type AS sous_type' : 'NULL AS sous_type')),
                    DB::raw(in_array($t, ['diplome', 'attestation', 'releve'], true) ? 'r.mention_grade' : 'NULL AS mention_grade'),
                    DB::raw(in_array($t, ['diplome', 'attestation', 'releve'], true) ? 'r.moyenne' : 'NULL AS moyenne')])
                ->when($q !== '', fn ($r) => $r->where(fn ($w) => $w->where('r.nom', 'like', "%$q%")->orWhere('r.prenoms', 'like', "%$q%")
                    ->orWhere('r.numero', 'like', "%$q%")->orWhere("r.$champ", 'like', "%$q%")))
                ->when($faculte, fn ($r) => $r->where(fn ($w) => $w
                    ->whereExists(fn ($s) => $s->from('etudiants as e')->join('filieres as f', 'f.id', '=', 'e.filiere_id')
                        ->whereColumn('e.id', 'r.etudiant_id')->where('f.faculte_id', $faculte))
                    ->orWhereExists(fn ($s) => $s->from('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                        ->join('filieres as fi', 'fi.id', '=', 'p.filiere_id')->whereColumn('i.etudiant_id', 'r.etudiant_id')->where('fi.faculte_id', $faculte))));
        }
        $union = array_shift($requetes);
        foreach ($requetes as $r) {
            $union->unionAll($r);
        }
        $total = DB::query()->fromSub($union, 'u')->count();
        $pagination = Pagination::calculer($total, self::PAR_PAGE, (int) $request->query('p', 1));

        return view('documents.registre', [
            'registre' => DB::query()->fromSub($union, 'u')->orderByDesc('date_emission')->orderByDesc('numero')
                ->offset($pagination['offset'])->limit(self::PAR_PAGE)->get(),
            'total' => $total,
            'pagination' => $pagination,
            'q' => $q,
            'type' => $type,
        ]);
    }
}
