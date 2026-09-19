<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, slice 2 — the business-level idempotency table the
 * `pos_webhook_events` migration explicitly deferred: "the same orderID
 * arriving in two different payloads (e.g. a bill and its correction) ...
 * needs its own UNIQUE constraint on restaurant_bills."
 *
 * `UNIQUE (connection_id, external_order_id)` is that constraint. A
 * corrected/resent bill for the same order UPDATES this row in place
 * (via the ingestion service's atomic upsert) rather than duplicating —
 * this is deliberately a SEPARATE guarantee from `pos_webhook_events`'
 * `UNIQUE (connection_id, payload_hash)`, which only catches an
 * exact-byte retry.
 *
 * `workspace_id` is NOT NULL and carries the `BelongsToWorkspace` trait
 * on the model — unlike `pos_connections`/`pos_webhook_events`, a row
 * here is only ever created from an ALREADY-authenticated, already
 * workspace-resolved `PosWebhookEvent` (never from unauthenticated
 * webhook input), so there is no pre-tenant-context lookup to protect
 * and no reason to leave it unscoped.
 *
 * Field selection is deliberately narrow: only the fields Phase 2 slice 2
 * was told to use from the documented Petpooja Global API payload
 * (`properties.Customer.name/phone`, `properties.Order.orderID/total/
 * core_total/discount_total/tax_total/created_on`, `properties.OrderItem`,
 * `properties.Tax`, `properties.Discount`). The full raw payload already
 * lives on `pos_webhook_events.raw_payload`/`raw_body` (linked via
 * `webhook_event_id`) for anything not promoted to a column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('pos_connections')->cascadeOnDelete();
            $table->foreignId('webhook_event_id')->nullable()->constrained('pos_webhook_events')->nullOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('restaurant_outlets')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('external_order_id', 64);
            $table->string('customer_name', 255)->nullable();
            $table->string('customer_phone_raw', 32)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->decimal('core_total', 12, 2)->nullable();
            $table->decimal('discount_total', 12, 2)->nullable();
            $table->decimal('tax_total', 12, 2)->nullable();
            $table->json('order_items')->nullable();
            $table->json('taxes')->nullable();
            $table->json('discounts')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_order_id'], 'restaurant_bills_connection_order_unique');
            $table->index(['workspace_id', 'placed_at'], 'restaurant_bills_ws_placed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_bills');
    }
};
