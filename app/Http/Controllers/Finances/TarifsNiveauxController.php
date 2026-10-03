<?php

namespace App\Http\Controllers\Finances;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Paiements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Tarifs de l'inscription académique en ligne par niveau — portage de
 * pages/finances/tarifs_niveaux.php (modification : sc_entite ou plus,
 * ancien hasRole).
 *
 * Différences voulues :
 *  - ces tarifs valent pour TOUTE l'université (portail candidats) : un
 *    compte limité à une faculté ne peut plus les modifier ;
 *  - montants contrôlés (nombre, 0 … plafond), niveaux inconnus ignorés,
 *    modification journalisée (ancien → nouveau).
 */
class TarifsNiveauxController extends Controller
{
    /** Montant affiché tant qu'aucun tarif n'est enregistré (ancienne valeur par défaut). */
    public const MONTANT_DEFAUT = 10000;

    public function __construct(private readonly Perimetre $perimetre) {}

    public function index(Request $request): View
    {
        return view('finances.tarifs_niveaux', [
            'niveaux' => DB::table('niveaux as n')->leftJoin('tarifs_niveaux as t', 't.niveau_id', '=', 'n.id')->orderBy('n.code')
                ->get(['n.id', 'n.code', 'n.libelle', DB::raw('COALESCE(t.montant, '.self::MONTANT_DEFAUT.') AS montant')]),
            'peutModifier' => $this->peutModifier($request),
        ]);
    }

    public function enregistrer(Request $request, Journal $journal): RedirectResponse
    {
        if (! $this->peutModifier($request)) {
            return redirect()->route('tarifs_niveaux')->with('erreur', "Ces tarifs valent pour toute l'université : modification réservée à un compte sans restriction de faculté.");
        }
        $niveaux = DB::table('niveaux')->pluck('code', 'id');
        $actuels = DB::table('tarifs_niveaux')->pluck('montant', 'niveau_id');
        $changements = [];
        foreach ((array) $request->input('montant', []) as $niveauId => $saisie) {
            $montant = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) $saisie);
            if (! isset($niveaux[(int) $niveauId]) || ! is_numeric($montant) || $montant < 0 || $montant > Paiements::MONTANT_MAX) {
                if (isset($niveaux[(int) $niveauId])) {
                    return redirect()->route('tarifs_niveaux')->with('erreur', "Montant invalide pour {$niveaux[(int) $niveauId]} : rien n'a été enregistré.");
                }

                continue;
            }
            $avant = (float) ($actuels[(int) $niveauId] ?? self::MONTANT_DEFAUT);
            if ((float) $montant !== $avant || ! isset($actuels[(int) $niveauId])) {
                $changements[(int) $niveauId] = ['avant' => $avant, 'apres' => (float) $montant];
            }
        }
        DB::transaction(function () use ($changements) {
            foreach ($changements as $niveauId => $c) {
                DB::table('tarifs_niveaux')->upsert([['niveau_id' => $niveauId, 'montant' => $c['apres']]], ['niveau_id'], ['montant']);
            }
        });
        if ($changements) {
            $journal->action('modifier', 'tarif', 'Tarifs d\'inscription en ligne : '.implode(', ', array_map(
                fn ($id, $c) => "{$niveaux[$id]} {$c['avant']} → {$c['apres']}", array_keys($changements), $changements)));
        }

        return redirect()->route('tarifs_niveaux')->with('succes', '✅ Tarifs mis à jour avec succès.');
    }

    private function peutModifier(Request $request): bool
    {
        return $request->user()->aRole('sc_entite') && $this->perimetre->faculte($request->user()) === 0;
    }
}
