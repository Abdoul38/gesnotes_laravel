<?php

namespace App\Http\Controllers\Notes;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\PresidentsJury;
use App\Services\Pv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * « Mes jurys » — espace du président du jury : PV des semestres qu'il
 * préside, verrouillage et déverrouillage après la délibération, impression
 * du PV, du PV de délibération et de la liste des admis. Aucune saisie de
 * notes ici.
 */
class JuryController extends Controller
{
    private const DOCUMENTS = ['pv' => PvImpressionController::class, 'deliberation' => DeliberationController::class, 'admis' => ListeValidesController::class];

    public function __construct(private readonly PresidentsJury $presidents, private readonly Pv $pvs, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        return view('notes.jury', ['pvs' => $this->presidents->pvPresides($request->user())]);
    }

    public function verrou(Request $request, int $id): RedirectResponse
    {
        $pv = $this->pvs->trouver($id);
        $verrouiller = $request->input('action') === 'verrouiller';
        if (! $pv || ! $this->presidents->preside($request->user(), $pv)) {
            return redirect()->route('jury')->with('erreur', 'Vous ne présidez pas le jury de ce PV.');
        }
        DB::table('pv_sessions')->where('id', $id)->update(['verrouille' => $verrouiller ? 1 : 0]);
        $this->journal->action($verrouiller ? 'verrouiller' : 'deverrouiller', 'pv', "PV « {$pv->libelle} » : ".($verrouiller ? 'verrouillé' : 'déverrouillé').' par le président du jury', $id);

        return redirect()->route('jury')->with('succes', "PV « {$pv->libelle} » ".($verrouiller ? 'verrouillé : plus aucune note ne peut y être modifiée.' : 'déverrouillé.'));
    }

    /** PV imprimable, PV de délibération, liste des admis — réservés au président de ce semestre. */
    public function document(Request $request, int $id, string $document): mixed
    {
        $pv = $this->pvs->trouver($id);
        if (! $pv || ! isset(self::DOCUMENTS[$document]) || ! $this->presidents->preside($request->user(), $pv)) {
            return redirect()->route('jury')->with('erreur', 'Vous ne présidez pas le jury de ce PV.');
        }
        $request->query->add(['id' => $id, 'pv_id' => $id]);

        return app()->call([app(self::DOCUMENTS[$document]), '__invoke'], ['request' => $request]);
    }
}
