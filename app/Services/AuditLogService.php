<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?int $workspaceId = null,
    ): AuditLog {
        $request = $request ?? request();
        $user = $request->user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'client_id' => $user?->client_id,
            'workspace_id' => $workspaceId,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable->getMorphClass() : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
        ]);
    }

    /**
     * Log an action performed by an admin (platform admin user).
     * Uses actor_admin_id and optional meta (plan_id, billing_cycle, reason, etc.).
     *
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function logAdmin(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $meta = null,
        ?AdminUser $admin = null,
        ?Request $request = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $workspaceId = null,
    ): AuditLog {
        $request = $request ?? request();
        $admin = $admin ?? $request->user('admin');

        return AuditLog::create([
            'actor_admin_id' => $admin?->id,
            'workspace_id' => $workspaceId,
            'action' => $action,
            'auditable_type' => $targetType,
            'auditable_id' => $targetId,
            'meta' => $meta,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
        ]);
    }

    /**
     * Log an action with no HTTP request behind it — a queued job or console
     * command, where there is no `$request->user()`/admin-guard user to pull
     * an actor from.
     *
     * `log()`/`logAdmin()` both unconditionally call `request()->user()` (or
     * the admin guard), which is meaningless in a queue worker; calling
     * either from a job would either record a bogus/empty actor or throw.
     * This writes `actor_admin_id`/`user_id` as null (there is no actor — the
     * platform itself performed the action) and `ip`/`user_agent`/`url` as
     * null (there is no request), attributing the entry to `$workspaceId`
     * instead, which a worker always has once it has established tenant
     * context.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function logSystem(
        string $action,
        ?Model $auditable,
        int $workspaceId,
        ?array $meta = null
    ): AuditLog {
        return AuditLog::create([
            'workspace_id' => $workspaceId,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable->getMorphClass() : null,
            'auditable_id' => $auditable?->getKey(),
            'meta' => $meta,
        ]);
    }
}
