<?php

namespace App\Http\Controllers\Structure;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Salles — portage de pages/structure/salles.php. */
class SallesController extends Controller
{
    public const TYPES = [
        'amphitheatre' => ['🎭', 'Amphithéâtre', '#7c3aed'],
        'salle_td' => ['📘', 'Salle TD', '#1d4ed8'],
        'laboratoire' => ['🧪', 'Laboratoire', '#0d9488'],
        'autre' => ['🚪', 'Autre', '#6b7280'],
    ];

    public function __construct(private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        // Comme l'ancienne page : la permission est créée si absente, pour
        // apparaître dans « Rôles & Permissions ».
        DB::table('permissions')->insertOrIgnore([
            'code' => 'salles_gerer', 'libelle' => 'Gérer les salles',
            'description' => 'Créer, modifier et supprimer les salles', 'categorie' => 'Infrastructure',
        ]);

        $type = (string) $request->query('type', '');
        $batiment = (string) $request->query('batiment', '');
        $q = trim((string) $request->query('q', ''));

        $salles = DB::table('salles')
            ->when(array_key_exists($type, self::TYPES), fn ($r) => $r->where('type', $type))
            ->when($batiment !== '', fn ($r) => $r->where('batiment', $batiment))
            ->when($q !== '', fn ($r) => $r->where(fn ($w) => $w->where('code', 'like', "%$q%")->orWhere('nom', 'like', "%$q%")))
            ->orderBy('batiment')->orderBy('code')
            ->get();

        return view('structure.salles', [
            'salles' => $salles,
            'types' => self::TYPES,
            'batiments' => DB::table('salles')->whereNotNull('batiment')->distinct()->orderBy('batiment')->pluck('batiment'),
            'filtres' => ['type' => $type, 'batiment' => $batiment, 'q' => $q],
            'filtre' => $type !== '' || $batiment !== '' || $q !== '',
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');
        $code = trim((string) $request->input('code'));
        $nom = trim((string) $request->input('nom'));
        $type = (string) $request->input('type', 'salle_td');
        $valeurs = [
            'code' => $code,
            'nom' => $nom,
            'capacite' => max(0, (int) $request->input('capacite')),
            'batiment' => trim((string) $request->input('batiment')) ?: null,
            'type' => array_key_exists($type, self::TYPES) ? $type : 'autre',
            'equipements' => trim((string) $request->input('equipements')) ?: null,
        ];

        if ($code === '' || $nom === '') {
            return $this->retour($request, 'erreur', 'Le code et le nom sont obligatoires.');
        }

        try {
            if ($id) {
                DB::table('salles')->where('id', $id)->update($valeurs);
                $this->journal->audit('MODIFIER_SALLE', 'salles', $id);

                return $this->retour($request, 'succes', "✅ Salle « $nom » mise à jour.");
            }
            $nouveau = DB::table('salles')->insertGetId($valeurs);
            $this->journal->audit('CREER_SALLE', 'salles', $nouveau);

            return $this->retour($request, 'succes', "✅ Salle « $nom » créée.");
        } catch (QueryException $e) {
            return $this->retour($request, 'erreur', str_contains($e->getMessage(), 'uniq_code')
                ? "Le code « $code » est déjà utilisé par une autre salle."
                : "Erreur lors de l'enregistrement.");
        }
    }

    public function basculer(Request $request): RedirectResponse
    {
        DB::table('salles')->where('id', (int) $request->input('id'))->update(['actif' => DB::raw('1 - actif')]);

        return $this->retour($request, 'succes', 'Statut de la salle mis à jour.');
    }

    public function supprimer(Request $request): RedirectResponse
    {
        // Bouton réservé à l'admin dans l'ancienne page, mais le serveur
        // acceptait toute personne ayant salles_gerer : vérifié ici aussi.
        if (! $request->user()->aRole('admin')) {
            return $this->retour($request, 'erreur', 'Seul un administrateur peut supprimer une salle.');
        }
        $id = (int) $request->input('id');
        try {
            DB::table('salles')->where('id', $id)->delete();
        } catch (QueryException) {
            return $this->retour($request, 'erreur', 'Impossible de supprimer cette salle — elle est probablement déjà utilisée dans un planning.');
        }
        $this->journal->audit('SUPPRIMER_SALLE', 'salles', $id);

        return $this->retour($request, 'succes', 'Salle supprimée.');
    }

    /** Retour à la liste en gardant le filtre de type, comme l'ancienne page. */
    private function retour(Request $request, string $type, string $message): RedirectResponse
    {
        $filtre = (string) $request->query('type', '');

        return redirect()->route('salles', $filtre !== '' ? ['type' => $filtre] : [])->with($type, $message);
    }
}
