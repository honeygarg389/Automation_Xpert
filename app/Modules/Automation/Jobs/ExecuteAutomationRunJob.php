<?php

namespace App\Modules\Automation\Jobs;

use App\Events\AutomationFailed;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Support\Retry\Jitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** Base retry schedule in seconds, before jitter. Previously none: all 3 attempts fired back-to-back. */
    private const BACKOFF_SECONDS = [30, 120, 300];

    /** Ceiling on any single jittered delay: 300 + 30% jitter. */
    public const BACKOFF_CAP_SECONDS = 390;

    /** @return list<int> */
    public function backoff(): array
    {
        return Jitter::jittered(self::BACKOFF_SECONDS);
    }

    public function __construct(public readonly int $runId) {}

    public function handle(AutomationEngine $engine): void
    {
        $run = AutomationRun::with('automation')->find($this->runId);
        if (! $run || in_array($run->status, ['cancelled', 'failed'], true)) {
            return;
        }

        try {
            $engine->executeRun($run);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            AutomationFailed::dispatch($run, $e->getMessage());
            throw $e;
        }
    }
}
