<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Broadcasting\Models\Campaign;
use App\Support\Retry\Jitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCampaignChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** Base retry schedule in seconds, before jitter. Previously none: both attempts fired back-to-back. */
    private const BACKOFF_SECONDS = [30, 120];

    /** Ceiling on any single jittered delay: 120 + 30% jitter. */
    public const BACKOFF_CAP_SECONDS = 156;

    /** @return list<int> */
    public function backoff(): array
    {
        return Jitter::jittered(self::BACKOFF_SECONDS);
    }

    public function __construct(
        public readonly int $campaignId,
        public readonly array $contactIds,
    ) {}

    /**
     * Phase 0: establish this job's tenant BEFORE handle() runs.
     *
     * handle()'s first statement loads a scoped model. Without context that
     * lookup returns null and the early return below turns a tenant-blind job
     * into a silent success. The middleware resolves the workspace with one
     * deliberately unscoped column read, and throws if it cannot.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::from(Campaign::class, $this->campaignId)];
    }

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || $campaign->status === 'failed') {
            return;
        }

        foreach ($this->contactIds as $i => $contactId) {
            SendCampaignMessageJob::dispatch($campaign->id, $contactId)
                ->onQueue('broadcast')
                ->delay(now()->addMilliseconds($i * 100)); // 10 msgs/second rate limit
        }
    }
}
