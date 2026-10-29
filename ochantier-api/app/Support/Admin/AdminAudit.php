<?php

namespace App\Support\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Inscrit les actions des administrateurs au journal d’audit (audit_logs), avec l’adresse IP et le navigateur.
 */
class AdminAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(Admin $admin, string $module, string $action, Request $request, ?Model $subject = null, ?array $before = null, ?array $after = null): AuditLog
    {
        $subject ??= $admin;

        return AuditLog::query()->create([
            'actor_type' => $admin->getMorphClass(),
            'actor_id' => $admin->getKey(),
            'action' => $action,
            'module' => $module,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'before_values' => $before,
            'after_values' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
