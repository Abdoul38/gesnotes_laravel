<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Journal d'activité (table audit_log) — portage de pages/admin/logs.php. */
class JournauxController extends Controller
{
    private const PAR_PAGE = 50;

    public const STYLES = [
        'connexion' => ['🔑', '#1e40af', '#dbeafe'], 'deconnexion' => ['🚪', '#6b7280', '#f3f4f6'],
        'creer' => ['➕', '#166534', '#dcfce7'], 'modifier' => ['✏️', '#854d0e', '#fef9c3'],
        'supprimer' => ['🗑️', '#991b1b', '#fee2e2'], 'consulter' => ['👁️', '#374151', '#f3f4f6'],
        'exporter' => ['📤', '#5b21b6', '#ede9fe'], 'importer' => ['📥', '#0369a1', '#e0f2fe'],
        'sauvegarder' => ['💾', '#166534', '#dcfce7'], 'inscrire' => ['🎓', '#0369a1', '#e0f2fe'],
        'publier' => ['📋', '#166534', '#dcfce7'], 'envoyer' => ['✉️', '#0369a1', '#e0f2fe'],
    ];

    public function __invoke(Request $request): View
    {
        $filtres = [
            'action' => mb_substr((string) $request->query('action'), 0, 50),
            'module' => mb_substr((string) $request->query('module'), 0, 50),
            'user' => mb_substr(trim((string) $request->query('user')), 0, 100),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? (string) $request->query('date') : '',
        ];
        $q = DB::table('audit_log')
            ->when($filtres['action'] !== '', fn ($q) => $q->where('action', $filtres['action']))
            ->when($filtres['module'] !== '', fn ($q) => $q->where('module', $filtres['module']))
            ->when($filtres['user'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('user_nom', 'like', '%'.addcslashes($filtres['user'], '%_\\').'%')
                ->when(ctype_digit($filtres['user']), fn ($w) => $w->orWhere('user_id', (int) $filtres['user']))))
            ->when($filtres['date'] !== '', fn ($q) => $q->whereDate('created_at', $filtres['date']));

        $pagination = Pagination::calculer((clone $q)->count(), self::PAR_PAGE, (int) $request->query('p', 1));
        $semaine = now()->subDays(7);

        return view('administration.journaux', [
            'filtres' => $filtres,
            'journaux' => $q->orderByDesc('created_at')->orderByDesc('id')->offset($pagination['offset'])->limit(self::PAR_PAGE)->get(),
            'pagination' => $pagination,
            'actions' => DB::table('audit_log')->distinct()->orderBy('action')->pluck('action'),
            'modules' => DB::table('audit_log')->distinct()->orderBy('module')->pluck('module'),
            'stats' => [
                ["Aujourd'hui", '📅', DB::table('audit_log')->whereDate('created_at', today())->count(), '#1e40af', '#dbeafe'],
                ['Cette semaine', '📊', DB::table('audit_log')->where('created_at', '>=', $semaine)->count(), '#166534', '#dcfce7'],
                ['Utilisateurs actifs', '👥', DB::table('audit_log')->where('created_at', '>=', $semaine)->distinct()->count('user_id'), '#5b21b6', '#ede9fe'],
                ["Connexions aujourd'hui", '🔑', DB::table('audit_log')->where('action', 'connexion')->whereDate('created_at', today())->count(), '#854d0e', '#fef9c3'],
            ],
        ]);
    }
}
