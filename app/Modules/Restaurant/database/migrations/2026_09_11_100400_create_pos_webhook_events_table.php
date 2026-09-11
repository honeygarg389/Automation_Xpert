<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant foundation — raw POS webhook event capture.
 *
 * `UNIQUE (connection_id, payload_hash)` — three things this does and does
 * NOT do, spelled out because each is a real trap for Phase 1B:
 *
 *  (a) MySQL treats every NULL as distinct in a unique index, so rows with
 *      connection_id = NULL (an event that could not be matched to any
 *      connection — quarantined/unresolvable) are NOT deduplicated by this
 *      constraint. Accepted as low-risk: an unresolvable event has no tenant
 *      to double-charge or double-notify.
 *  (b) An exact-retry INSERT (the provider resending the identical payload
 *      to the identical connection) throws a duplicate-key exception at the
 *      DB level. Phase 1B's webhook controller MUST catch that specifically
 *      (or use insertOrIgnore()-style logic) and respond with a clean
 *      200/"already processed" — a 500 here would make the provider retry
 *      forever.
 *  (c) This is exact-payload-retry dedup ONLY. It says nothing about
 *      business-level idempotency (the same orderID arriving in two
 *      different payloads, e.g. a bill and its correction) — that needs its
 *      own UNIQUE constraint on restaurant_bills, a future Phase 2 task, not
 *      built here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->nullable()->constrained('pos_connections')->nullOnDelete();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('payload_hash', 64);
            $table->string('event_type', 64)->nullable();
            $table->timestamp('received_at');
            $table->string('processing_status', 20)->default('pending');
            $table->json('raw_payload');
            $table->string('failure_reason', 512)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['connection_id', 'payload_hash']);
            // Explicit short names: MySQL's default auto-generated name for the
            // first index exceeds the 64-char identifier limit.
            $table->index(['workspace_id', 'processing_status', 'received_at'], 'pos_webhook_events_ws_status_received_idx');
            $table->index(['processing_status', 'received_at'], 'pos_webhook_events_status_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_webhook_events');
    }
};
