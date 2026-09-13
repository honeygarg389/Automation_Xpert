<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ THE REAL DEFECT this migration fixes: `restaurant_outlets.status`
 * defaulted to 'pending' at the COLUMN level (see the original
 * create_restaurant_outlets_table migration), which predates Phase 1C's
 * "an outlet's lifecycle is active/archived only" rule. Every place the
 * application creates an outlet was updated to write 'active' explicitly
 * (RestaurantOutletService::createOutlet(), the factory), but any row that
 * was created before that fix — or by any path that ever relied on the
 * column default rather than the application default — is stuck showing
 * 'pending' forever, because nothing in this module ever transitions an
 * outlet OUT of pending.
 *
 * Measured in the real `whatsmine` database: two outlets ("Food Court",
 * "McD") both carry status='pending' from before this fix, dated
 * 2026-09-12 — a full day before the ACTIVE-default code landed. 'pending'
 * is not one of the two outlet lifecycle states admins actually manage
 * (Active/Archived per the Outlets directory), so these outlets were
 * silently excluded from `RestaurantOutlet::eligibleForNewConnection()`,
 * which requires `status = active` — an outlet with zero Petpooja
 * connections read as "Not connected" in the UI yet could not be selected
 * to connect one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Data fix first: legacy 'pending' outlets are reclassified as
        // 'active' — they are ordinary, usable physical outlets, exactly
        // like every outlet this module creates today. 'archived' rows are
        // untouched by construction (the WHERE clause never matches them).
        DB::table('restaurant_outlets')
            ->where('status', 'pending')
            ->update(['status' => 'active']);

        // Schema fix second, so the SAME defect (a row silently inheriting
        // 'pending' from the column rather than the application) cannot
        // recur through any future raw insert that omits `status` — a
        // seeder, a direct DB call, anything bypassing
        // RestaurantOutletService. No doctrine/dbal in this project (see
        // CLAUDE.md), so this is a raw ALTER rather than
        // Blueprint::change().
        DB::statement("ALTER TABLE restaurant_outlets ALTER COLUMN status SET DEFAULT 'active'");

        // Enforced at the database layer, not just by application
        // discipline — the same reasoning as PosConnection's active_slot
        // CHECK constraint (Phase 1C, Task D): a rule that depends on every
        // future write path remembering to set the right value is not a
        // rule. Safe to add now because the UPDATE above already
        // eliminated every non-conforming row, and STATUS_INACTIVE
        // (removed from the model in this same change) was never written
        // by any code path.
        DB::statement("ALTER TABLE restaurant_outlets ADD CONSTRAINT restaurant_outlets_status_check CHECK (status IN ('active', 'archived'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE restaurant_outlets DROP CHECK restaurant_outlets_status_check');
        DB::statement("ALTER TABLE restaurant_outlets ALTER COLUMN status SET DEFAULT 'pending'");

        // ⚠️ Deliberately NOT reverting 'active' rows back to 'pending'.
        // The UPDATE above is a one-way data correction, not a schema
        // change — there is no way to tell which 'active' rows were
        // legitimately active already versus normalized by this migration,
        // and reintroducing 'pending' is the exact defect this migration
        // exists to remove. A rollback restores the SCHEMA to its prior
        // shape; it does not un-fix the data.
    }
};
