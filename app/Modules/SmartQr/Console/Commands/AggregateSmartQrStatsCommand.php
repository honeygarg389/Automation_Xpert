<?php

namespace App\Modules\SmartQr\Console\Commands;

use App\Modules\SmartQr\Services\SmartQrAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Builds §10's daily aggregates. Scheduled daily; safe to re-run.
 *
 * ⚠️ Defaults to YESTERDAY, not today. Today is still accumulating, and the
 * dashboard computes the current day live from raw rows precisely so it does not
 * depend on this job having run.
 *
 * ⚠️ See HAZARD H-4 in SmartQrAggregator: this command REFUSES any date older
 * than the retention window, and no flag overrides it.
 */
class AggregateSmartQrStatsCommand extends Command
{
    protected $signature = 'smartqr:aggregate
        {--date= : A single YYYY-MM-DD day to build. Defaults to yesterday.}
        {--days= : Build this many days back from yesterday, inclusive.}';

    protected $description = 'Build Smart QR daily aggregate statistics';

    public function handle(SmartQrAggregator $aggregator): int
    {
        $dates = $this->targetDates();

        if ($dates === []) {
            $this->error('Nothing to do: --days must be at least 1.');

            return self::FAILURE;
        }

        $written = 0;

        foreach ($dates as $date) {
            try {
                $rows = $aggregator->aggregate($date);
                $written += $rows;
                $this->line(sprintf('  %s — %d assignment(s)', $date->toDateString(), $rows));
            } catch (RuntimeException $e) {
                // ⚠️ HAZARD H-4. Reported per day and the run CONTINUES for the
                // days that are still computable — refusing one old day must not
                // abandon the recent ones a backfill was actually for.
                $this->warn('  '.$e->getMessage());
            }
        }

        $this->info(sprintf('Wrote %d aggregate row(s) across %d day(s).', $written, count($dates)));

        return self::SUCCESS;
    }

    /** @return list<Carbon> */
    private function targetDates(): array
    {
        if ($this->option('date')) {
            return [Carbon::parse((string) $this->option('date'))->startOfDay()];
        }

        $days = (int) ($this->option('days') ?? 1);

        if ($days < 1) {
            return [];
        }

        $out = [];

        for ($i = 1; $i <= $days; $i++) {
            $out[] = now()->startOfDay()->subDays($i);
        }

        return $out;
    }
}
