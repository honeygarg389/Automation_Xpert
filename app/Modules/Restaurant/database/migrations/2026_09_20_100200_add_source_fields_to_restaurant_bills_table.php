<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, slice 2 — preserve Petpooja's own business fields faithfully.
 *
 *   source_order_status    `properties.Order.status` exactly as Petpooja sent it
 *                          (documented values: Success, Cancelled). Without it a
 *                          Cancelled push is indistinguishable from a sale.
 *   source_created_on_raw  `properties.Order.created_on` byte-for-byte. The
 *                          documented samples carry NO timezone
 *                          ("2025-04-04 11:45:35"), so the raw string is the only
 *                          lossless record of what Petpooja said.
 *   received_at            when the webhook carrying this bill was received —
 *                          the operational ordering key. Copied from the FIRST
 *                          event that created the row and never rewritten by a
 *                          correction, so a corrected bill keeps its place.
 *
 * `placed_at` (pre-existing) changes meaning: it is now the order time in UTC
 * ONLY when it can be derived honestly (a valid outlet timezone + a strictly
 * parseable `created_on`); otherwise NULL. It previously interpreted the
 * timezone-less string as UTC, and fell back to the receive time — both were
 * guesses presented as fact.
 *
 * The two UPDATEs below repair rows written under that old behaviour. This
 * table has never shipped, so in practice they only touch a developer's local
 * database; they are cheap, safe on an empty table, and leave no row claiming
 * a certainty it never had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_bills', function (Blueprint $table) {
            $table->string('source_order_status', 32)->nullable()->after('external_order_id');
            $table->text('source_created_on_raw')->nullable()->after('source_order_status');
            $table->timestamp('received_at')->nullable()->after('placed_at');

            $table->index(['workspace_id', 'received_at'], 'restaurant_bills_ws_received_idx');
        });

        DB::table('restaurant_bills')->whereNull('received_at')->update(['received_at' => DB::raw('created_at')]);
        DB::table('restaurant_bills')->update(['placed_at' => null]);
    }

    public function down(): void
    {
        Schema::table('restaurant_bills', function (Blueprint $table) {
            $table->dropIndex('restaurant_bills_ws_received_idx');
            $table->dropColumn(['source_order_status', 'source_created_on_raw', 'received_at']);
        });
    }
};
