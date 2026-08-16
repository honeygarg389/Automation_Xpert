<?php

namespace App\Modules\SmartQr\Jobs;

use App\Modules\SmartQr\Actions\GenerateQrBatchAction;
use App\Modules\SmartQr\Models\SmartQrBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued generation, for the spec's 500-code batches.
 *
 * ⚠️ NO workspace context, and none is needed: a batch is platform inventory and
 * the codes it creates carry no `workspace_id`. Classified NO_TENANT_DATA in the
 * Phase 0 job guard for that reason.
 *
 * Idempotent by construction: the action counts what already exists and
 * generates only the remainder, so a retried job tops up rather than duplicating.
 */
class GenerateQrBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $batchId) {}

    public function handle(GenerateQrBatchAction $action): void
    {
        $batch = SmartQrBatch::find($this->batchId);

        if ($batch === null) {
            return;
        }

        $action->execute($batch);
    }
}
