<?php

use App\Modules\Entitlements\Support\MessageMetrics;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires the duplicate `whatsapp_messages` meter.
 *
 * ─── ⚠️ DISCARDS. DOES NOT MERGE. ───────────────────────────────────────────
 *
 * The first version of this migration FOLDED the retired rows into
 * `messages_whatsapp` — summed, so no usage was "lost". That was the wrong
 * instinct, and the reason is worth stating precisely because it generalises far
 * beyond this table:
 *
 *     Merging two counters can push a workspace OVER ITS LIMIT without the
 *     customer having sent a single additional message.
 *
 * `whatsapp_messages` and `messages_whatsapp` were both written for the same
 * campaign sends, so their sum double-counts that period. A workspace at 120 on
 * one and 30 on the other has not sent 150 messages — and a limit of 140 that
 * neither figure breached is breached the instant the migration runs. The
 * customer's first symptom is a 402 they did nothing to earn, produced by a
 * refactor.
 *
 * A discard cannot do that. It can only ever move usage DOWN, and usage moving
 * down is a customer being under-charged for one period — recoverable, invisible,
 * and vastly preferable to a lockout nobody can explain.
 *
 * ─── The general rule, for whoever hits this with real data ─────────────────
 *
 * This is free today because the affected rows are test data and the working
 * database holds zero rows. It will not always be free. When a meter migration
 * has to reconcile two counters on a LIVE database, the options are, in order of
 * preference:
 *
 *   1. WAIT FOR THE PERIOD TO ROLL. `usage_meters` is keyed by `Ym`, so the
 *      problem expires on its own. Ship the code change, let the current period
 *      finish on the old metric, and let the new one start clean next period.
 *      This is almost always the right answer and it costs nothing but patience.
 *
 *   2. EXPLICIT RECONCILIATION WITH AN ANNOUNCEMENT. If the numbers genuinely
 *      must be combined, compute the merged figure, tell the affected customers
 *      what their usage will read and why, and give anyone pushed over the limit
 *      a grace period. Reconciliation is a billing event.
 *
 *   3. NEVER A SILENT MERGE INSIDE A REFACTOR. Which is what the first version
 *      of this file was, and it looked entirely reasonable.
 *
 * ─── Irreversible, deliberately ─────────────────────────────────────────────
 *
 * `down()` is empty. The discarded rows cannot be reconstructed, and inventing
 * them would be inventing usage. The recovery path is the database backup taken
 * before this ran — `backups/whatsmine_2026_08_09_114551_736c72.sql.gz` on the
 * machine where it was authored — not a `down()` that pretends to reverse it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Deleted, not folded. See the note above: summing two counters that
        // both recorded the same sends double-counts the period and can push a
        // workspace over a limit it never actually reached.
        DB::table('usage_meters')
            ->where('metric', MessageMetrics::RETIRED_METRIC)
            ->delete();
    }

    /**
     * Intentionally empty — see the class docblock. A `down()` that recreated
     * these rows would be fabricating usage data, and a `down()` that silently
     * did nothing while looking reversible is worse than one that says so.
     */
    public function down(): void {}
};
