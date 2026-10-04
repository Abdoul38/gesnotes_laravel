<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Licence;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Statut et activation de la licence — portage de pages/admin/parametres_licence.php.
 * Accessible même licence invalide (c'est là qu'on la corrige), à l'administrateur seul.
 * Correction : une clé valide mais émise pour un autre établissement était
 * acceptée puis bloquait l'application ; elle est maintenant refusée d'emblée.
 */
class LicenceController extends Controller
{
    public function __construct(private readonly Licence $licence, private readonly UniversiteCourante $universite) {}

    public function index(): View
    {
        return view('administration.licence', [
            'statut' => $this->licence->verifierInstallation(),
            'nbEtudiants' => DB::table('etudiants')->count(),
        ]);
    }

    public function activer(Request $request, Journal $journal): RedirectResponse
    {
        $cle = mb_substr(trim((string) $request->input('cle_licence')), 0, 1000);
        $verif = $this->licence->verifier($cle);
        if (! $verif['valide']) {
            return redirect()->route('parametres_licence')->with('erreur', 'Clé refusée : '.$verif['erreur']);
        }
        $code = $this->universite->get()->code;
        if ($verif['institution'] !== '' && strcasecmp($verif['institution'], $code) !== 0) {
            return redirect()->route('parametres_licence')->with('erreur', "Clé refusée : elle appartient à l'établissement « {$verif['institution']} ».");
        }
        DB::table('parametres')->upsert([['cle' => 'licence_cle', 'valeur' => $cle, 'label' => 'Clé de licence', 'groupe' => 'licence']], ['cle'], ['valeur']);
        $journal->action('modifier', 'licence', "Licence activée pour {$verif['institution']}");

        return redirect()->route('parametres_licence')->with('succes', "Licence activée pour {$verif['institution']}.");
    }
}
