<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Broadcasting\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class LaunchScheduledCampaignsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * CROSS-TENANT BY DESIGN — a decision, not an omission.
     *
     * This job's entire function is to scan every workspace for due work.
     * Giving it a tenant context would silently reduce it to one tenant's.
     * Declared explicitly so it is counted rather than looking like a job
     * somebody forgot to scope.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::crossTenant(
            'reason: the scheduler scans EVERY workspace for campaigns whose send time has arrived; a per-tenant context would find only one tenant of them',
        )];
    }

    public function handle(): void
    {
        Campaign::where('status', 'queued')
            ->whereNotNull('schedule_at')
            ->where('schedule_at', '<=', now())
            ->get()
            ->each(fn (Campaign $c) => LaunchCampaignJob::dispatch($c->id)->onQueue('broadcast'));
    }
}
