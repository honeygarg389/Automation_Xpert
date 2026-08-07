<?php

namespace App\Modules\Automation\Jobs;

use App\Events\AutomationFailed;
use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Automation\Models\Automation;
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

    /**
     * Phase 0, slice 4c. AutomationRun is NOT workspace-owned — it carries
     * automation_id and contact_id and no workspace_id — so its tenant comes
     * from its parent Automation, which is.
     *
     * The chicken-and-egg is sharper here than elsewhere: handle()'s first
     * statement is AutomationRun::with('automation'), and once Automation is
     * scoped that eager load returns null. The engine would then run against a
     * run with no automation — not an error, just nothing happening, while
     * touching seven scoped models on the way.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::through(
            AutomationRun::class,
            $this->runId,
            'automation_id',
            Automation::class,
        )];
    }

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
