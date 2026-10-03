<?php

namespace App\Http\Controllers\Documents;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Parcours;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Diplômes & attestations : étudiants ayant validé au moins 4 semestres du
 * premier cycle ou le M1 — portage de pages/documents/diplomes.php.
 *
 * Différences voulues : semestres et moyennes choisis par le service
 * Parcours, comme dans les documents eux-mêmes (la liste prenait la
 * meilleure moyenne, les documents la session normale) ; étudiants de la
 * faculté de l'agent selon la règle de la liste des étudiants (filière ou
 * inscription) ; liens « Diplôme » et « Attestation » réservés aux rôles qui
 * peuvent les produire.
 */
class DiplomesController extends Controller
{
    public function __invoke(Request $request, Parcours $parcours, Perimetre $perimetre): View
    {
        $u = $request->user();
        $faculte = $perimetre->faculte($u);
        $filiere = (int) $request->query('filiere_id');
        $annee = (int) $request->query('annee_id');
        $q = trim((string) $request->query('q', ''));

        $etudiants = DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->whereExists(fn ($s) => $s->from('resultats_semestre as rs')->whereColumn('rs.etudiant_id', 'e.id')->where('rs.valide', 1))
            ->when($faculte, fn ($r) => $r->where(fn ($w) => $w->where('f.faculte_id', $faculte)
                ->orWhereExists(fn ($s) => $s->from('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                    ->join('filieres as fi', 'fi.id', '=', 'p.filiere_id')->whereColumn('i.etudiant_id', 'e.id')->where('fi.faculte_id', $faculte))))
            ->when($filiere, fn ($r) => $r->where('e.filiere_id', $filiere))
            ->when($q !== '', fn ($r) => $r->where(fn ($w) => $w->where('e.nom', 'like', "%$q%")->orWhere('e.prenoms', 'like', "%$q%")->orWhere('e.numero', 'like', "%$q%")))
            ->orderBy('e.nom')->orderBy('e.prenoms')
            ->get(['e.id', 'e.nom', 'e.prenoms', 'e.numero', 'f.libelle as filiere']);

        $tous = $etudiants->isEmpty() ? [] : $parcours->semestresDe($etudiants->pluck('id'));
        $eligibles = [];
        foreach ($etudiants as $et) {
            $p = $tous[$et->id] ?? null;
            if (! $p) {
                continue;
            }
            $bilans = [];
            foreach (array_keys(Parcours::DOCUMENTS) as $doc) {
                $bilans[$doc] = $parcours->bilan($p, $doc);
            }
            if (! $bilans['validation_l2'] && ! $bilans['master1']) {
                continue;
            }
            // Année de référence : S6 si la licence est complète, sinon S4 ; à défaut, celle du Master
            $ref = ($bilans['licence'] ?? $bilans['validation_l2'] ?? $bilans['master_complet'] ?? $bilans['master1'])['dernier'];
            if ($annee && (int) $ref->annee_id !== $annee) {
                continue;
            }
            $eligibles[] = ['et' => $et, 'p' => $p, 'b' => $bilans];
        }

        return view('documents.diplomes', [
            'eligibles' => $eligibles,
            'filieres' => DB::table('filieres')->when($faculte, fn ($r) => $r->where('faculte_id', $faculte))->orderBy('libelle')->get(['id', 'libelle']),
            'annees' => DB::table('annees_academiques')->orderByDesc('id')->get(['id', 'libelle']),
            'filtres' => ['q' => $q, 'filiere_id' => $filiere, 'annee_id' => $annee],
            'peutProduire' => in_array($u->role, ['admin', 'secretaire', 'sc_centrale', 'sc_entite'], true),
        ]);
    }
}
