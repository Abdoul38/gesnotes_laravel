<?php

namespace App\Services;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Journaux d'audit de l'ancienne application, écrits à l'identique pour
 * que ses écrans d'historique restent complets pendant la migration.
 * (Anomalie A12 : deux tables coexistent — fusion à décider.)
 */
class Journal
{
    /** Ancien logAudit() → table `journal_audit`. */
    public function audit(string $action, ?string $table = null, ?int $enregistrementId = null, ?string $details = null): void
    {
        DB::table('journal_audit')->insert([
            'utilisateur_id' => Auth::id(),
            'action' => $action,
            'table_cible' => $table,
            'enregistrement_id' => $enregistrementId,
            'details' => $details,
            'ip_adresse' => request()->ip(),
        ]);
    }

    /** Ancien enregistrer_audit() → table `audit_log`. Ne bloque jamais l'application. */
    public function action(string $action, string $module, string $description = '', ?int $cibleId = null): void
    {
        try {
            /** @var Utilisateur|null $u */
            $u = Auth::user();
            DB::table('audit_log')->insert([
                'user_id' => $u?->id,
                'user_nom' => $u ? ($u->nomComplet() ?: null) : null,
                'user_role' => $u?->role,
                'action' => $action,
                'module' => $module,
                'description' => $description ?: null,
                'cible_id' => $cibleId,
                'ip_address' => request()->ip(),
            ]);
        } catch (Throwable $e) {
            Log::error('Journal::action : '.$e->getMessage());
        }
    }
}
