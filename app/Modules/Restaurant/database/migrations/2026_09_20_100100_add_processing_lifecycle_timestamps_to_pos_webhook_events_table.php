<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, slice 2 — `pending -> processing -> processed/failed` lifecycle
 * timestamps for `ProcessPosWebhookEventJob`. `attempts` already exists
 * (slice-1 schema) and is reused as-is for the atomic per-claim counter;
 * these three columns are the only gap.
 *
 * All three are set via single atomic `UPDATE ... WHERE id = ? [AND
 * processing_status = ?]` statements in the job — never read-modify-write
 * — so a retry racing a still-running attempt cannot corrupt the row. See
 * `ProcessPosWebhookEventJob` for the claim/complete/fail queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_webhook_events', function (Blueprint $table) {
            $table->timestamp('processing_started_at')->nullable()->after('attempts');
            $table->timestamp('processed_at')->nullable()->after('processing_started_at');
            $table->timestamp('failed_at')->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('pos_webhook_events', function (Blueprint $table) {
            $table->dropColumn(['processing_started_at', 'processed_at', 'failed_at']);
        });
    }
};
