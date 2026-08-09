<?php

namespace App\Modules\Entitlements\Console\Commands;

use App\Models\Workspace;
use App\Modules\Entitlements\Services\GaugeReader;
use App\Modules\Entitlements\Support\Entitlements;
use App\Modules\Entitlements\Support\GaugeSources;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * REPORT-ONLY. Answers the one question no code can answer before enforcement:
 * WHO IS ALREADY OVER, per gauge, and by how much.
 *
 * ─── Why a sweep rather than a request-time log ─────────────────────────────
 *
 * A counter and a gauge fail differently, and that difference decides the shape
 * of the measurement.
 *
 * A counter resets each period. Every customer starts at 0 and must SPEND their
 * way to a limit, so enforcement can only refuse a customer's next action — one
 * they took. Logging at request time is the right instrument there, because the
 * cohort only comes into existence as requests arrive.
 *
 * A gauge is measured against STATE THAT ALREADY EXISTS, and the limit can drop
 * beneath data that is already there. A customer holding 12 chatbots on a plan
 * allowing 5 is over the instant enforcement is switched on, having done
 * nothing. That cohort exists NOW, in the database, whether or not anyone sends
 * a request — so waiting for traffic would under-report it, and the customers
 * who never log in are exactly the ones it would miss.
 *
 * That is the reasoning to reuse for any future limit measured against
 * pre-existing state: a measurement pass before an enforcement pass.
 *
 * Refuses nothing. Writes nothing but logs.
 */
class ReportGaugeBreachesCommand extends Command
{
    protected $signature = 'entitlements:gauge-report {--workspace= : limit the sweep to one workspace id}';

    protected $description = 'REPORT ONLY: list workspaces already over a gauge limit. Refuses nothing.';

    public function handle(GaugeReader $reader, Entitlements $entitlements): int
    {
        $query = Workspace::query()->with('client');

        if ($id = $this->option('workspace')) {
            $query->whereKey((int) $id);
        }

        $rows = [];
        $breaches = 0;
        $workspaces = 0;

        $query->orderBy('id')->chunkById(200, function ($chunk) use (&$rows, &$breaches, &$workspaces, $reader, $entitlements) {
            foreach ($chunk as $workspace) {
                $workspaces++;
                $entitlement = $entitlements->forClient($workspace->client);

                foreach (GaugeSources::enforceableKeys() as $key) {
                    $limit = $entitlement->limit($key);

                    // null is unlimited OR ungranted — both mean "no ceiling to
                    // breach". Only a declared number can be exceeded.
                    if ($limit === null) {
                        continue;
                    }

                    $held = (int) $reader->count($key, $workspace);

                    if ($held < $limit) {
                        continue;
                    }

                    $breaches++;
                    $rows[] = [$workspace->id, $key, $held, $limit, $held - $limit];

                    Log::channel('entitlements')->warning(
                        'entitlements.gauge_report: workspace already at or over a gauge limit',
                        [
                            'workspace_id' => $workspace->id,
                            'client_id' => $workspace->client_id,
                            'limit_key' => $key,
                            'held' => $held,
                            'limit' => $limit,
                            'over_by' => $held - $limit,
                            'scope' => GaugeSources::for($key)['scope'] ?? null,
                        ]
                    );
                }
            }
        });

        $this->info("Swept {$workspaces} workspace(s) across ".count(GaugeSources::enforceableKeys()).' gauge(s).');

        if ($rows === []) {
            $this->info('No workspace is at or over any gauge limit. Nothing would be refused.');

            return self::SUCCESS;
        }

        $this->table(['workspace', 'gauge', 'held', 'limit', 'over by'], $rows);
        $this->warn("{$breaches} breach(es). REPORT ONLY — nothing was refused.");

        return self::SUCCESS;
    }
}
