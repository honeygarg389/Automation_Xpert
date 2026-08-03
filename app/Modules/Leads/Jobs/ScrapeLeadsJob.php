<?php

namespace App\Modules\Leads\Jobs;

use App\Modules\Leads\Models\LeadScrapeJob;
use App\Modules\Leads\Services\GooglePlacesScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScrapeLeadsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $scrapeJobId) {}

    public function handle(GooglePlacesScraper $scraper): void
    {
        $job = LeadScrapeJob::find($this->scrapeJobId);
        if (! $job || $job->status === 'done') {
            return;
        }
        $scraper->run($job);
    }
}
