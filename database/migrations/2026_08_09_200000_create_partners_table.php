<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — the partner tier's DATA LAYER ONLY.
 *
 * `Platform Owner → Partner → Client → Workspace → Users`. Every link but the
 * first already existed; this adds it.
 *
 * ─── Deliberately minimal ───────────────────────────────────────────────────
 *
 * No branding, no custom domain, no billing, no commission rate, no entitlement
 * ceiling. Each belongs to a phase that has not been designed, and adding a
 * column later is cheaper than removing one that shipped with a guessed meaning.
 *
 * Two are excluded for specific reasons, not squeamishness:
 *
 *   custom_domain / branding — `clients` ALREADY carries logo_path, logo_disk,
 *   primary_color, tagline and custom_domain. Whether partner branding
 *   overrides, inherits, or retires the client's is an unanswered design
 *   question, and putting a second custom_domain in the schema before it is
 *   answered creates two sources of truth for one concept — the shape that has
 *   produced several bugs in this codebase already.
 *
 *   anything billing-shaped — CLAUDE.md still records
 *   `Subscription` vs `ClientSubscription` as unverified against the gateways
 *   and seeders. A commission_rate or partner_plan_id here would decide the
 *   billing model by accident. Same reason `subscriptions` was left alone.
 *
 * ─── No auth ────────────────────────────────────────────────────────────────
 *
 * No guard, no roles, no partner_users. How a partner authenticates is an open
 * ruling; this table does not presuppose the answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();

            // Route key. Every route-bound model in this codebase uses a uuid,
            // and a sequential partner id in a URL is an enumeration oracle
            // across resellers — the one thing the tier exists to prevent.
            $table->uuid('uuid')->unique();

            $table->string('name');

            // Human-stable identifier for support and logs. NOT a domain.
            $table->string('slug', 128)->unique();

            // Mirrors clients.status exactly, so suspension works the same way
            // at both levels rather than inventing a second vocabulary.
            $table->string('status', 32)->default('active');

            // Who at the platform owns the relationship. Operational nicety, so
            // nullable, and nullOnDelete: losing the account manager must never
            // take the partner with it.
            $table->foreignId('owner_admin_user_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });

        Schema::table('clients', function (Blueprint $table) {
            // NULLABLE, and it must stay that way: platform-owned (direct)
            // customers have partner_id = null, and that is not a migration
            // state to be tidied away later — it is a supported, permanent case.
            //
            // NO ->after(). MySQL's ALGORITHM=INSTANT requires an added column
            // be appended last; naming a position forces a full table rebuild.
            //
            // ON DELETE RESTRICT, deliberately, against the two alternatives:
            //
            //   nullOnDelete would silently convert a partner's customers into
            //   direct ones — changing who bills them, with no record.
            //   cascadeOnDelete would delete live customers.
            //
            // A partner leaving is a business event with money attached. It must
            // be refused at the database until a human has decided where the
            // customers go. Deactivate; do not delete.
            $table->foreignId('partner_id')->nullable()
                ->constrained('partners')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });

        Schema::dropIfExists('partners');
    }
};
