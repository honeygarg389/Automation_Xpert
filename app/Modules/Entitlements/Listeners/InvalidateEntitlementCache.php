<?php

namespace App\Modules\Entitlements\Listeners;

use App\Modules\Entitlements\Jobs\ReconcileWorkspaceEntitlements;

/**
 * The write-through HINT.
 *
 * ⚠️ Correctness does not depend on this firing. The cache is read-through and
 * boundary-aware, so a missed event costs freshness for at most one fallback TTL
 * — it can never produce a permanently wrong answer. That is deliberate: an
 * invalidation scheme whose correctness depends on every writer remembering is
 * the same disease as BUG-027 (two key lists) and BUG-028 (two metrics).
 *
 * These listeners exist so the common case is warm, not so the answer is right.
 */
class InvalidateEntitlementCache
{
    public function handle(object $event): void
    {
        $clientId = $this->clientIdFrom($event);

        if ($clientId !== null) {
            ReconcileWorkspaceEntitlements::dispatch($clientId);
        }
    }

    /** Best-effort: these events carry different shapes. */
    private function clientIdFrom(object $event): ?int
    {
        foreach (['client_id', 'clientId'] as $prop) {
            if (isset($event->{$prop})) {
                return (int) $event->{$prop};
            }
        }

        foreach (['subscription', 'client', 'user'] as $prop) {
            $model = $event->{$prop} ?? null;
            if ($model === null) {
                continue;
            }
            if (isset($model->client_id)) {
                return (int) $model->client_id;
            }
            if (isset($model->user) && isset($model->user->client_id)) {
                return (int) $model->user->client_id;
            }
        }

        return null;
    }
}
