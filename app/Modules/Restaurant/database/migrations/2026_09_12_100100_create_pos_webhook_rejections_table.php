<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1B — minimal security/audit record for REJECTED inbound POS webhook
 * requests. Deliberately the narrowest possible table.
 *
 * HARD RULE: this table must NEVER contain raw_body, raw_payload, customer
 * fields, bill fields, workspace_id, or any other data from the rejected
 * payload. A request that failed authentication is, by definition, one this
 * system does not yet trust — persisting its content would mean storing
 * arbitrary attacker-controlled data under the excuse of "just logging it."
 * The only things worth keeping are enough to answer "how often, from
 * where, and why are we rejecting" without ever holding what was rejected.
 *
 * No workspace_id column at all: a rejected request has no authenticated
 * tenant, and this is a platform-level operational/security table, not
 * customer-owned data — see PosWebhookRejection's docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_webhook_rejections', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->foreignId('connection_id')->nullable()->constrained('pos_connections')->nullOnDelete();
            $table->string('payload_hash', 64);
            $table->string('source_ip', 45)->nullable();
            $table->string('failure_reason', 64);
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['provider', 'received_at']);
            $table->index(['connection_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_webhook_rejections');
    }
};
