<?php

use App\Modules\Entitlements\Support\MessageMetrics;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Folds the retired `whatsapp_messages` meter into `messages_whatsapp`.
 *
 * `SendCampaignMessageJob` used to write both for every WhatsApp campaign
 * message, so historically the two carry the SAME counts for the same periods.
 * Dropping the retired rows without folding them would be harmless for those —
 * but not for any period where only one was written, and the inbox path is about
 * to start feeding the surviving metric. Adding rather than replacing is the
 * only version that is right in both cases.
 *
 * ⚠️ SUM, not overwrite. `(workspace_id, metric, period)` is unique, so a
 * straight UPDATE of the metric name would collide with an existing
 * `messages_whatsapp` row for the same period and fail — and an INSERT … ON
 * DUPLICATE KEY UPDATE that SET the value would silently discard whichever side
 * arrived second. A customer's usage must never go DOWN because of a migration.
 *
 * ─── Measured before writing this ───────────────────────────────────────────
 *
 * `usage_meters` holds 0 rows on the working database, so this is a no-op today.
 * It is written for correctness rather than for effect: the shape has to be
 * right for the first deployment where it is not zero, and that is not a thing
 * to work out under pressure later.
 *
 * Irreversible by design — see down().
 */
return new class extends Migration
{
    public function up(): void
    {
        $retired = MessageMetrics::RETIRED_METRIC;          // whatsapp_messages
        $survivor = MessageMetrics::forChannel('whatsapp'); // messages_whatsapp

        DB::table('usage_meters')
            ->where('metric', $retired)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($survivor) {
                foreach ($rows as $row) {
                    // Add into the survivor for the same workspace and period,
                    // creating it if absent. `value + VALUES(value)` is what
                    // makes this a fold rather than a replacement.
                    DB::table('usage_meters')->upsert(
                        [[
                            'workspace_id' => $row->workspace_id,
                            'metric' => $survivor,
                            'period' => $row->period,
                            'value' => $row->value,
                            'created_at' => $row->created_at ?? now(),
                            'updated_at' => now(),
                        ]],
                        ['workspace_id', 'metric', 'period'],
                        ['value' => DB::raw('value + VALUES(value)'), 'updated_at' => now()]
                    );
                }
            });

        DB::table('usage_meters')->where('metric', $retired)->delete();
    }

    /**
     * ⚠️ NOT REVERSIBLE, deliberately.
     *
     * Once folded, the two contributions are one number. Splitting them back out
     * would require guessing which part came from which metric, and a guess
     * about a usage counter is a guess about what a customer is allowed to do.
     *
     * The recovery path is the database backup taken before this ran, not a
     * down() that invents data. Saying so here is better than a down() that
     * looks reversible and is not.
     */
    public function down(): void
    {
        // Intentionally empty. See the note above.
    }
};
