<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Petpooja Phase 2A gate-hardening pass — Gate 5 of the six-gate live
 * activation invariant ("outlet-specific authorization").
 *
 * Exhaustive grep across app/Modules/Restaurant found NO existing persisted
 * representation of this concept anywhere: RestaurantOutlet carries only
 * id/uuid/workspace_id/name/address/timezone/status. Per the standing
 * instruction not to invent a fake boolean or silently bypass a required
 * gate with no trustworthy source, this is the smallest durable, auditable
 * operational record for it — an explicit admin action
 * (RestaurantOutletService::authorizeForLivePos(), reachable only through
 * its own permission-gated admin route), not a raw column nobody writes to
 * outside a test.
 *
 * Both columns are nullable and both are set/cleared together (see the
 * service method) — "authorized" is exactly "the timestamp is non-null",
 * the admin FK is provenance for the audit trail, not an independent
 * condition. nullOnDelete(): losing the authorizing admin's account must
 * never retroactively revoke a live outlet's authorization or corrupt the
 * outlet row; it only loses the "who" on an already-satisfied gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_outlets', function (Blueprint $table) {
            $table->timestamp('pos_live_authorized_at')->nullable()->after('status');
            $table->foreignId('pos_live_authorized_by_admin_id')->nullable()->after('pos_live_authorized_at')
                ->constrained('admin_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_outlets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_live_authorized_by_admin_id');
            $table->dropColumn('pos_live_authorized_at');
        });
    }
};
