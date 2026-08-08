<?php

namespace App\Modules\Leads\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Leads\Models\LeadScrapeJob;
use App\Modules\Leads\Services\GooglePlacesScraper;
use App\Support\Retry\Jitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScrapeLeadsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /** Base retry schedule in seconds, before jitter. Previously none: both attempts fired back-to-back. */
    private const BACKOFF_SECONDS = [30, 120];

    /** Ceiling on any single jittered delay: 120 + 30% jitter. */
    public const BACKOFF_CAP_SECONDS = 156;

    /** @return list<int> */
    public function backoff(): array
    {
        return Jitter::jittered(self::BACKOFF_SECONDS);
    }

    public function __construct(public readonly int $scrapeJobId) {}

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
        return [EstablishesWorkspaceContext::from(LeadScrapeJob::class, $this->scrapeJobId)];
    }

    public function handle(GooglePlacesScraper $scraper): void
    {
        $job = LeadScrapeJob::find($this->scrapeJobId);
        if (! $job || $job->status === 'done') {
            return;
        }
        $scraper->run($job);
    }
}
