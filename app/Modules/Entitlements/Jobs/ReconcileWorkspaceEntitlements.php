<?php

namespace App\Modules\Entitlements\Jobs;

use App\Modules\Entitlements\Services\EntitlementCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recomputes a client's cached entitlement. CLAUDE.md rule 7: dispatched
 * immediately on change, with the scheduled sweep as a net only.
 *
 * ⚠️ Idempotent by construction — a pure recompute keyed on the client. Running
 * it twice, or a hundred times, produces the same rows. No workspace context is
 * needed because the cache is written per workspace id explicitly.
 */
class ReconcileWorkspaceEntitlements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly ?int $clientId) {}

    public function handle(EntitlementCache $cache): void
    {
        $cache->refreshClient($this->clientId);
    }
}
