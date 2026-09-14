<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds contacts created by IMPORT (`source = 'import'`) with no identifying
 * data at all — no name, no phone, no email — the "blank contact" hazard
 * fixed in ContactService::bulkImport()/importGridRows(): before that fix, a
 * CSV row whose recognized columns were all empty (or unrecognized) could
 * reach upsert() with an empty lookup, taking its "create a fresh row"
 * branch with NO uniqueness check at all.
 *
 * ⚠️ `source = 'import'` IS PART OF THE MATCH CRITERIA, not a filter applied
 * on top of it — see query()'s own docblock for why: this command targets
 * ONE specific, known hazard, not "any blank contact anywhere," and must
 * never report or delete a contact that merely happens to be blank for some
 * other, unrelated reason (a manual add, an inbound message, an API call, an
 * e-commerce sync).
 *
 * ⚠️ REPORT ONLY BY DEFAULT, same convention as `channels:audit-routing`
 * (ChannelRoutingAuditCommand) — this tool exists to let an operator SEE the
 * damage and decide, not to guess on their behalf. `--delete` is a second,
 * explicit step, and even then only ever removes rows matching the STRICT
 * criteria (source = 'import' AND no name AND no phone AND no email) — a row
 * with ANY identifying data, or from ANY other source, is never touched by
 * this command, no matter how it looks otherwise.
 *
 * Every query uses the QUERY BUILDER (`DB::table`), not Eloquent — this must
 * see every workspace's contacts to be useful as a cleanup tool, and a raw
 * query never has Contact's BelongsToWorkspace scope applied in the first
 * place, so there is nothing to bypass.
 *
 * Measured against the real `whatsmine` database on 2026-09-14, before this
 * command existed (ad hoc, read-only): ZERO contacts matched the blank
 * criteria. 13 contacts carried `source = 'import'`; none were blank. This
 * command exists so that check is a repeatable, documented tool rather than
 * a one-off query — not because damage was found.
 */
class ContactsFindBlankImportsCommand extends Command
{
    protected $signature = 'contacts:find-blank-imports
        {--delete : Delete the matched rows, after an interactive confirmation listing them}
        {--workspace= : Restrict to one workspace ID (default: every workspace)}';

    protected $description = "Report (and optionally delete) IMPORTED contacts (source = 'import') with no name, phone, or email at all — report-only by default";

    public function handle(): int
    {
        $blank = $this->query()->get();

        if ($blank->isEmpty()) {
            $this->info("No blank contacts found (source = 'import', no name, no phone, no email).");

            return self::SUCCESS;
        }

        $this->warn("{$blank->count()} blank contact(s) found:");
        $this->table(
            ['ID', 'UUID', 'Workspace', 'Source', 'Created At'],
            $blank->map(fn ($c) => [$c->id, $c->uuid, $c->workspace_id, $c->source ?? '(none)', $c->created_at])->all()
        );

        if (! $this->option('delete')) {
            $this->line('Re-run with --delete to remove these rows (asks for confirmation first).');

            return self::SUCCESS;
        }

        // ⚠️ Interactive confirmation is not optional here. `--no-interaction`
        // makes confirm() return the default (false) without prompting, so a
        // scripted/CI invocation of this command can NEVER delete anything by
        // accident — deletion requires a human reading the table above and
        // answering yes.
        if (! $this->confirm("Permanently delete these {$blank->count()} row(s)? This cannot be undone.")) {
            $this->line('Cancelled — nothing deleted.');

            return self::SUCCESS;
        }

        $ids = $blank->pluck('id')->all();

        // Soft-delete (Contact uses SoftDeletes), matching the model's own
        // normal delete path — recoverable, not a hard DELETE, on top of the
        // interactive confirmation above.
        $deleted = DB::table('contacts')->whereIn('id', $ids)->update(['deleted_at' => now()]);

        $this->info("Soft-deleted {$deleted} row(s).");

        return self::SUCCESS;
    }

    /**
     * ⚠️ THE `source = 'import'` CLAUSE IS MANDATORY, NOT OPTIONAL. Its
     * absence was the exact unsafe-scope gap this command was fixed for:
     * without it, this query matches ANY blank contact regardless of how it
     * was created — the dashboard "Add Contact" form (source: 'manual'), an
     * inbound WhatsApp message (source: 'whatsapp_inbound'), an e-commerce
     * sync (source: the store's platform name), a campaign CSV upload
     * (source: 'campaign_csv', a DIFFERENT string from this command's own
     * 'import'), or the public API (source: an arbitrary caller-supplied
     * string, or none at all).
     *
     * This command exists to clean up ONE specific, known hazard —
     * ContactService::bulkImport()/importGridRows() reaching upsert() with
     * an empty identity lookup — and nothing else. A blank contact from any
     * OTHER source is a DIFFERENT situation (a different bug, or legitimate
     * partial data mid-capture) that this tool has no business reporting on,
     * let alone deleting. `rg -n "source.*import" ...` against the previous
     * version of this file found nothing but a comment — the constraint was
     * described, never enforced.
     *
     * Both handle() call sites (report and --delete) share this ONE method,
     * so there is no way for delete mode to end up less strict than report
     * mode, or vice versa.
     */
    private function query(): Builder
    {
        return DB::table('contacts')
            ->whereNull('deleted_at')
            ->where('source', 'import')
            ->where(fn ($q) => $q->whereNull('first_name')->orWhere('first_name', ''))
            ->where(fn ($q) => $q->whereNull('last_name')->orWhere('last_name', ''))
            ->where(fn ($q) => $q->whereNull('phone_e164')->orWhere('phone_e164', ''))
            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
            ->when($this->option('workspace'), fn ($q, $ws) => $q->where('workspace_id', (int) $ws))
            ->orderBy('id');
    }
}
