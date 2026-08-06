<?php

namespace App\Modules\Broadcasting\Jobs;

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
