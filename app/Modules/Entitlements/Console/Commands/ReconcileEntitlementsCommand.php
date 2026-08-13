<?php

namespace App\Modules\Entitlements\Console\Commands;

use App\Models\Workspace;
use App\Modules\Entitlements\Services\EntitlementCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The SAFETY NET, never the primary path (CLAUDE.md rule 7).
 *
 * ⚠️ This finding anything is itself a signal. Every row it corrects is either a
 * boundary that passed (expected, and cheap) or an event path that failed to
 * fire (a defect). The command reports the two separately for that reason.
 */
class ReconcileEntitlementsCommand extends Command
{
    protected $signature = 'entitlements:reconcile {--stale-only : only rows past their boundary}';

    protected $description = 'Recompute cached entitlements. Safety net for the event-driven path.';

    public function handle(EntitlementCache $cache): int
    {
        $drifted = 0;
        $expired = 0;
        $total = 0;

        Workspace::with('client')->orderBy('id')->chunkById(200, function ($chunk) use ($cache, &$drifted, &$expired, &$total) {
            foreach ($chunk as $workspace) {
                $total++;

                $row = DB::table('workspace_entitlements')
                    ->where('workspace_id', $workspace->id)->first();

                $boundaryPassed = $row !== null && $row->valid_until !== null
                    && now()->greaterThanOrEqualTo($row->valid_until);

                $hashDrifted = $row !== null && $row->source_hash !== $cache->sourceHash($workspace->client);

                if ($row === null || $boundaryPassed || $hashDrifted) {
                    $cache->refresh((int) $workspace->id);
                    $boundaryPassed ? $expired++ : null;
                    $hashDrifted ? $drifted++ : null;
                }
            }
        });

        $this->info("Swept {$total} workspace(s). {$expired} past their boundary, {$drifted} with drifted inputs.");

        if ($drifted > 0) {
            $this->warn("{$drifted} row(s) had drifted INPUTS — an event-driven invalidator did not fire. "
                .'Boundary expiry is expected; drift is a defect.');
        }

        return self::SUCCESS;
    }
}
