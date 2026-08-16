<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 7 — §10's daily aggregates, and the OWED column finally dropped.
 *
 * ─── ⚠️ THE GRAIN IS THE ASSIGNMENT, NOT THE CODE OR THE TENANT ─────────────
 *
 * One row per assignment per day. A reassignment creates a NEW assignment
 * (R-4), so a row's tenant is fixed for the life of the row — the previous
 * tenant's aggregates stay attached to their own period and the new tenant's
 * start from zero, with no date arithmetic anywhere.
 *
 * ─── ⚠️ §10's `tenant_id` AND `qr_code_id` ARE BOTH REFUSED ─────────────────
 *
 * R-4 does NOT settle this on its own, and pretending it does would be lazy:
 * R-4's objection is to denormalising a tenant onto a row whose tenant CHANGES,
 * and an aggregate keyed by assignment has a tenant that never changes.
 *
 * The real argument is that neither column buys anything. Dashboard queries
 * resolve a workspace's assignment ids and use `whereIn` — and R-13 bounds a
 * workspace at 50 assignments, so that IN clause is at most fifty integers
 * against an indexed column. There is no join to avoid and no scan to prevent.
 * A denormalised copy would be a second source of truth added for a performance
 * win that does not exist at the ruled scale.
 *
 * ⚠️ THE TRIGGER FOR REVISITING: if R-13's ceiling of 50 is ever lifted into
 * the thousands, the IN clause stops being trivial and `workspace_id` becomes
 * worth reconsidering. Recorded so this decision has a condition rather than
 * being permanent by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_qr_daily_stats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('smart_qr_assignment_id')
                ->constrained('smart_qr_assignments')->cascadeOnDelete();

            $table->date('stat_date');

            // ── Scans. Bots counted SEPARATELY, never silently folded in ────
            //
            // Slice 4 flags bots rather than dropping them, because a preview
            // crawler is evidence a link was shared. Keeping the count here
            // means the customer's figures exclude them while an operator can
            // still see they happened.
            $table->unsignedInteger('scans')->default(0);
            $table->unsignedInteger('unique_scans')->default(0);
            $table->unsignedInteger('bot_scans')->default(0);

            // ── ⚠️ R-19 LIVES IN THESE COLUMN NAMES ─────────────────────────
            //
            // Not `customers_messaged`. Attributed counts UNDER-REPORT, because
            // a customer can delete the reference from their own message before
            // sending it and there is deliberately no fallback that guesses
            // (R-20).
            //
            // The prop names were slice 6's defence; these are the stronger one,
            // because a future query writer reads the SCHEMA, not the
            // controller. A column called `customers_messaged` would be quoted
            // as a total in a report nobody re-derived.
            $table->unsignedInteger('attributed_messages')->default(0);

            // ⚠️ DISTINCT contacts, and it exists because §12's rate needs it:
            // "Unique Customers Messaged / Unique Valid Scans × 100". No other
            // table can answer it — conversion events count events, not people.
            $table->unsignedInteger('attributed_unique_contacts')->default(0);

            $table->unsignedInteger('attributed_new_contacts')->default(0);
            $table->unsignedInteger('attributed_conversations_started')->default(0);

            $table->timestamps();

            // ⚠️ THE IDEMPOTENCY GUARANTEE, not merely an index. The aggregator
            // uses updateOrCreate against this pair, so a re-run recomputes and
            // overwrites rather than duplicating — and a late-arriving scan is
            // corrected by re-running rather than double-counted.
            $table->unique(['smart_qr_assignment_id', 'stat_date'], 'smart_qr_daily_grain_uniq');

            // The dashboard's range query: assignment(s) over a date window.
            $table->index(['stat_date', 'smart_qr_assignment_id'], 'smart_qr_daily_date_idx');
        });

        // ── ⚠️ THE OWED COLUMN, DROPPED ─────────────────────────────────────
        //
        // `smart_qr_attribution_sessions.smart_qr_scan_event_id` was added in
        // slice 5 to be back-filled by the scan job, and the back-fill was never
        // written — every row is NULL.
        //
        // Dropped rather than wired, as ruled: no metric reads it, wiring it
        // would couple the scan queue to the attribution path for a link nothing
        // consumes, and a queue-based back-fill could never guarantee non-null
        // anyway. A column that is SOMETIMES populated is worse than one that is
        // absent — it invites a query that silently omits rows.
        Schema::table('smart_qr_attribution_sessions', function (Blueprint $table) {
            $table->dropForeign(['smart_qr_scan_event_id']);
            $table->dropColumn('smart_qr_scan_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('smart_qr_attribution_sessions', function (Blueprint $table) {
            $table->foreignId('smart_qr_scan_event_id')->nullable()
                ->constrained('smart_qr_scan_events')->nullOnDelete();
        });

        Schema::dropIfExists('smart_qr_daily_stats');
    }
};
