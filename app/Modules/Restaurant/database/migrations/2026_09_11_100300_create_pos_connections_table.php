<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant foundation — POS connections.
 *
 * `workspace_id` is NOT NULL: this assumes a tenant always exists before a
 * connection is created. A future bulk pre-provisioning flow (Phase 5) may
 * need to create connections ahead of the workspace that will claim them —
 * not a concern for current scope, and not designed against here.
 *
 * `webhook_secret_hash` replicates whatsapp_business_accounts's exact
 * approach: a plain `hash('sha256', $token)` digest stored in a VARCHAR(64),
 * looked up/verified rather than the raw secret ever being persisted. See
 * PosConnection::verifyToken().
 *
 * `UNIQUE (webhook_secret_hash)`: a SHA-256 digest is 256 bits; the
 * probability of two distinct secrets colliding is on the order of 2^-128
 * by the birthday bound at any realistic row count, so treating a collision
 * here as "this token already belongs to another connection" rather than a
 * genuine accidental collision is the correct call — a collision is
 * astronomically more likely to indicate a token-generation bug than luck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('restaurant_outlets')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('external_ref', 64);
            $table->text('credentials')->nullable();
            $table->string('webhook_secret_hash', 64)->nullable();
            $table->timestamp('webhook_secret_rotated_at')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('meta_json')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->default('untested');
            $table->string('last_test_message', 512)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_ref']);
            $table->unique('webhook_secret_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_connections');
    }
};
