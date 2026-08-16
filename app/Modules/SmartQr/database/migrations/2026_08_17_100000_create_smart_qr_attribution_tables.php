<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 5 — §9's attribution loop.
 *
 * ⚠️ NEITHER TABLE CARRIES workspace_id, AND THAT IS RULED (R-4).
 *
 * A session outlives the certainty of its tenant. The assignment it belongs to
 * can be ended and the code reassigned while a 30-minute session is still open —
 * so a denormalised `workspace_id` would keep asserting the OLD tenant for
 * exactly the window that matters, and someone would eventually "fix" whichever
 * copy they found first.
 *
 * The tenant is reached through the assignment, which is where R-4 put it. The
 * cross-tenant check compares the CONVERSATION's workspace against the
 * ASSIGNMENT's — one join, on a path that already performs one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The token issued at scan time ───────────────────────────────────
        Schema::create('smart_qr_attribution_sessions', function (Blueprint $table) {
            $table->id();

            // ⚠️ SHORT, and deliberately far shorter than public_token.
            //
            // A different trade, not a weaker one. `public_token` addresses a
            // code from an unauthenticated URL and must resist offline
            // enumeration, so it is 128 bits. This one is single-use, expires in
            // 30 minutes, and is only reachable by sending a WhatsApp message
            // through a deduplicated webhook — and a human has to read it in
            // their own message, so length is a usability cost. ~40 bits is
            // proportionate to that threat model.
            $table->string('token', 32)->unique();

            $table->foreignId('smart_qr_assignment_id')
                ->constrained('smart_qr_assignments')->cascadeOnDelete();

            // ⚠️ NULLABLE, and forced by slice 4's shape.
            //
            // The scan row is written by a QUEUED job; the token must exist
            // BEFORE the redirect is issued. They cannot be created together, so
            // the job back-fills this. A dropped job leaves a session with a
            // null scan link — which is the correct trade, because the
            // alternative is delaying the customer's redirect on a queue.
            $table->foreignId('smart_qr_scan_event_id')->nullable()
                ->constrained('smart_qr_scan_events')->nullOnDelete();

            $table->timestamp('issued_at');
            $table->timestamp('expires_at');

            // ⚠️ THE IDEMPOTENCY CLAIM. Set by a conditional update
            // (`where consumed_at IS NULL`), so two concurrent messages carrying
            // one token race at the database and exactly one wins.
            $table->timestamp('consumed_at')->nullable();

            // Filled on consumption. Nullable because a session that is never
            // used — the ordinary case, a customer who scans and walks away —
            // has no contact, and inventing one would be the faked attribution
            // §9 forbids.
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();

            $table->timestamps();

            // The lookup the inbound listener makes on every message that
            // contains something token-shaped.
            // ⚠️ Index names given EXPLICITLY. Laravel's generated name
            // (table + columns + '_index') exceeds MySQL's 64-character
            // identifier limit for this table, and the migration fails halfway —
            // creating the table, then aborting, leaving nothing recorded in
            // `migrations` and a retry that collides with its own first attempt.
            $table->index(['token', 'consumed_at'], 'smart_qr_attr_token_idx');
            $table->index(['smart_qr_assignment_id', 'issued_at'], 'smart_qr_attr_assignment_idx');
        });

        // ── What actually happened, typed ───────────────────────────────────
        //
        // ⚠️ TYPED ROWS, NOT A WIDE ROW OF BOOLEAN FLAGS.
        //
        // §15 implies one conversion record. A wide row needs a migration every
        // time §10 grows a metric, and it cannot express that ONE arriving
        // message is simultaneously "customer messaged", "new contact" and
        // "conversation started" — three facts about one event, not one fact
        // with three flags.
        Schema::create('smart_qr_conversion_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('smart_qr_assignment_id')
                ->constrained('smart_qr_assignments')->cascadeOnDelete();
            $table->foreignId('attribution_session_id')
                ->constrained('smart_qr_attribution_sessions')->cascadeOnDelete();

            // customer_messaged | new_contact | conversation_started
            $table->string('type', 32);

            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            // ⚠️ THE GUARANTEE, not the optimisation.
            //
            // `consumed_at` is a check the application makes and could get
            // wrong; this index is what the database enforces. One session can
            // produce at most one conversion of each type, whatever the code
            // above it does — the same lesson slice 2 learned when the
            // application-level serial check turned out to be TOCTOU and the
            // unique index was what actually held.
            $table->unique(['attribution_session_id', 'type'], 'smart_qr_conv_session_type_uniq');
            $table->index(['smart_qr_assignment_id', 'type', 'occurred_at'], 'smart_qr_conv_assignment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_qr_conversion_events');
        Schema::dropIfExists('smart_qr_attribution_sessions');
    }
};
