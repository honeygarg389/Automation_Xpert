<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BUG-019. Reports channel routing identifiers claimed by more than one
 * workspace.
 *
 * REPORT ONLY. There is no --fix, and there will not be one: which workspace
 * legitimately owns a phone number or a Facebook page is a business fact, not
 * something inferable from the rows. The newest row might be a genuine move
 * between agencies or a mis-onboarding, and choosing wrong routes one company's
 * conversations into another's inbox — the exact failure this whole branch
 * exists to prevent. Same reasoning as the SEC-004 storage inventory.
 *
 * Run this BEFORE deploying the unique-index migration: the migration aborts on
 * duplicates, and MySQL's own error names only one arbitrary offending value.
 */
class ChannelRoutingAuditCommand extends Command
{
    protected $signature = 'channels:audit-routing';

    protected $description = 'Report channel routing identifiers claimed by more than one workspace (report only)';

    /**
     * Every query below uses the QUERY BUILDER (`DB::table`), not Eloquent, so
     * no model global scope applies and this stays correct when Phase 0's
     * workspace scope lands on ChannelAccount. That is deliberate, not
     * incidental: finding identifiers that span workspaces is the entire point,
     * and a per-tenant view cannot see a collision by definition.
     */
    public function handle(): int
    {
        return $this->report();
    }

    private function report(): int
    {
        $found = 0;

        $found += $this->section(
            'WhatsApp — phone_number_id (protected by a UNIQUE index)',
            $this->duplicatesByColumn('phone_number_id'),
        );

        $found += $this->section(
            'Messenger — meta_json->page_id (NO schema protection)',
            $this->duplicatesByJsonPath('messenger', ['$.page_id']),
        );

        $found += $this->section(
            'Instagram — meta_json->instagram_page_id / instagram_account_id (NO schema protection)',
            $this->duplicatesByJsonPath('instagram', ['$.instagram_page_id', '$.instagram_account_id']),
        );

        $this->line('');

        if ($found === 0) {
            $this->info('✅  No routing identifier is claimed by more than one workspace.');

            return self::SUCCESS;
        }

        $this->error("{$found} routing identifier(s) are claimed by more than one workspace.");
        $this->line('');
        $this->line('Inbound messages for each are currently being DROPPED — refusing is deliberate:');
        $this->line('delivering them to a guessed workspace would put one company\'s conversation in');
        $this->line('another company\'s inbox.');
        $this->line('');
        $this->line('Resolve each by hand: decide which workspace owns it, then disconnect the channel');
        $this->line('in the other. This command will not choose for you.');

        return self::FAILURE;
    }

    /** @return array<int, array{identifier:string,workspaces:string,rows:int}> */
    private function duplicatesByColumn(string $column): array
    {
        return DB::table('channel_accounts')
            ->selectRaw("{$column} AS identifier, GROUP_CONCAT(DISTINCT workspace_id ORDER BY workspace_id) AS workspaces, COUNT(*) AS rows_count")
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->groupBy($column)
            ->havingRaw('COUNT(DISTINCT workspace_id) > 1')
            ->get()
            ->map(fn ($r) => ['identifier' => (string) $r->identifier, 'workspaces' => (string) $r->workspaces, 'rows' => (int) $r->rows_count])
            ->all();
    }

    /**
     * Instagram stores its id under either of two keys and the router matches
     * BOTH, so a collision can span the two — which is precisely what a single
     * unique index could not express. The values are unioned before grouping.
     *
     * @param  list<string>  $paths
     * @return array<int, array{identifier:string,workspaces:string,rows:int}>
     */
    private function duplicatesByJsonPath(string $channel, array $paths): array
    {
        $rows = DB::table('channel_accounts')
            ->where('channel', $channel)
            ->whereNotNull('meta_json')
            ->get(['workspace_id', 'meta_json']);

        $byIdentifier = [];

        foreach ($rows as $row) {
            $meta = json_decode((string) $row->meta_json, true) ?: [];

            foreach ($paths as $path) {
                $key = ltrim($path, '$.');
                $value = $meta[$key] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                $byIdentifier[(string) $value]['workspaces'][(int) $row->workspace_id] = true;
                $byIdentifier[(string) $value]['rows'] = ($byIdentifier[(string) $value]['rows'] ?? 0) + 1;
            }
        }

        $out = [];

        foreach ($byIdentifier as $identifier => $data) {
            if (count($data['workspaces']) > 1) {
                $ids = array_keys($data['workspaces']);
                sort($ids);
                $out[] = ['identifier' => $identifier, 'workspaces' => implode(',', $ids), 'rows' => $data['rows']];
            }
        }

        return $out;
    }

    /** @param  array<int, array{identifier:string,workspaces:string,rows:int}>  $duplicates */
    private function section(string $title, array $duplicates): int
    {
        $this->line('');
        $this->line('<fg=cyan>'.$title.'</>');

        if ($duplicates === []) {
            $this->line('  none');

            return 0;
        }

        $this->table(
            ['identifier', 'claimed by workspaces', 'rows'],
            array_map(fn ($d) => [$d['identifier'], $d['workspaces'], $d['rows']], $duplicates),
        );

        return count($duplicates);
    }
}
