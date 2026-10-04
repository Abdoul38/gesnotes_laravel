<?php

namespace App\Http\Controllers\Administration;

use App\Autorisations\Roles;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Permissions par rôle — portage de pages/comptes/roles.php.
 * Corrections : rôle vérifié contre la liste des rôles (l'ancienne page
 * acceptait n'importe quel nom), codes vérifiés contre la table
 * permissions, enregistrement atomique, journalisation.
 */
class RolesController extends Controller
{
    /** [icône, libellé, fond, couleur] dans l'ordre de l'ancienne page. */
    public const ROLES = [
        'admin' => ['🔴', 'Administrateur', '#fee2e2', '#dc2626'],
        'secretaire' => ['🔵', 'Secrétaire', '#dbeafe', '#1d4ed8'],
        'sc_entite' => ['🟣', 'Agent Scolarité Entité', '#f3e8ff', '#7c3aed'],
        'sc_centrale' => ['🩵', 'Agent Scolarité Centrale', '#ccfbf1', '#0d9488'],
        'enseignant' => ['🟢', 'Enseignant', '#dcfce7', '#16a34a'],
        'chef_departement' => ['📘', 'Chef de département', '#dbeafe', '#0369a1'],
        'doyen' => ['🎓', 'Doyen', '#fef3c7', '#7c2d12'],
        'recteur' => ['👑', 'Recteur', '#e0e7ff', '#1A3A5C'],
        'etudiant' => ['🟡', 'Étudiant', '#fef9c3', '#d97706'],
        'lecture' => ['⚪', 'Lecture seule', '#f3f4f6', '#6b7280'],
        'user' => ['⚫', 'Utilisateur', '#f3f4f6', '#374151'],
    ];

    public function __construct(private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $role = (string) $request->query('role', 'secretaire');
        if (! isset(self::ROLES[$role])) {
            $role = 'secretaire';
        }
        $permissions = DB::table('permissions')->orderBy('categorie')->orderBy('libelle')->get();
        $parRole = DB::table('role_permissions')->get()->groupBy('role')->map(fn ($l) => $l->pluck('permission_code')->all());

        return view('administration.roles', [
            'roleActif' => $role,
            'parCategorie' => $permissions->groupBy('categorie'),
            'nbPermissions' => $permissions->count(),
            'parRole' => $parRole,
            'actuelles' => $parRole[$role] ?? [],
            'nbParRole' => DB::table('utilisateurs')->groupBy('role')->get(['role', DB::raw('COUNT(*) AS nb')])->pluck('nb', 'role'),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $role = trim((string) $request->input('role'));
        if (! Roles::estUnRole($role) || $role === 'admin') {
            return redirect()->route('roles')->with('erreur', 'Rôle invalide ou admin non modifiable.');
        }
        $demandes = array_filter(array_map(fn ($c) => trim((string) $c), (array) $request->input('permissions', [])));
        $codes = DB::table('permissions')->whereIn('code', $demandes)->pluck('code')->all();

        $nbAvant = DB::transaction(function () use ($role, $codes) {
            $nb = DB::table('role_permissions')->where('role', $role)->delete();
            DB::table('role_permissions')->insert(array_map(fn ($c) => ['role' => $role, 'permission_code' => $c], $codes));

            return $nb;
        });
        $this->journal->action('modifier', 'roles', "Permissions du rôle $role : ".implode(', ', $codes));

        return redirect()->route('roles', ['role' => $role])
            ->with('succes', "Rôle $role : $nbAvant supprimée(s), ".count($codes).' permission(s) enregistrée(s).');
    }
}
