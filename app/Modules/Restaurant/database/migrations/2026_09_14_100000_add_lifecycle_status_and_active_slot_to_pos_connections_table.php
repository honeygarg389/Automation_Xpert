<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C correction — the missing DB-level guarantee behind "a physical
 * outlet may have only one non-archived Petpooja connection at a time".
 *
 * ⚠️ THIS WAS A REAL, LIVE DEFECT, NOT A HYPOTHETICAL. Before this migration,
 * nothing below the UI enforced it: outlet_id=1 ("Food Court") accumulated
 * THREE connections (two 'connected', one 'pending') because the create flow
 * had no server-side concept of "already connected" at all. Confirmed by
 * querying the working database directly before writing this migration, and
 * the three accidental rows were deleted (verified zero webhook events/
 * rejections first) before this migration runs, precisely so this new
 * constraint has no pre-existing violation to choke on.
 *
 * `active_slot` follows the EXACT pattern already proven in this module for
 * "at most one X at a time" (legal_document_versions.published_slot,
 * Phase 1A): NULL for every archived connection (so MySQL's NULL-
 * distinctness lets any number of archived/historical connections coexist
 * per outlet), and exactly 1 for a connection that is pending, connected or
 * paused. UNIQUE(outlet_id, active_slot) then makes a second non-archived
 * connection on the same outlet physically impossible at the DB layer,
 * independent of whether the application code that got there was correct —
 * concurrency-safe by construction, not by convention.
 *
 * The CHECK constraint keeps `status` and `active_slot` from drifting apart.
 * ⚠️ Uses `<=>` (NULL-safe equal), not `=`, on the non-archived branch —
 * `active_slot = 1` evaluates to SQL NULL (not FALSE) when active_slot is
 * NULL, which is exactly the silent-pass bug found and fixed in
 * legal_document_versions' first CHECK constraint (Phase 1B). Applying that
 * lesson here from the start rather than re-discovering it.
 *
 * ⚠️ A SECOND REAL BUG, FOUND THE SAME WAY (running it against the actual
 * working database, not just the test suite): the CHECK constraint was
 * originally added BEFORE the backfill UPDATE. On a table with zero rows —
 * which is every test, since RefreshDatabase migrates the schema before any
 * factory ever inserts a row — that ordering never fails, because there is
 * nothing yet to violate it. It failed immediately (MySQL error 3819) the
 * first time this ran against the real `whatsmine` database, which had one
 * pre-existing `status = 'connected'` row: at the moment the CHECK was
 * added, that row's `active_slot` was still NULL (just-added, nullable,
 * no default), which satisfies neither branch of the CHECK. The backfill
 * must run BEFORE the CHECK is added, not merely before the UNIQUE index —
 * "empty table in every test" is not evidence an ordering is correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_connections', function (Blueprint $table) {
            $table->unsignedTinyInteger('active_slot')->nullable();
        });

        // Backfill BEFORE the CHECK constraint exists — status is not yet
        // 'archived' for anything at this point in the schema's history, so
        // every existing row gets active_slot = 1. Adding the CHECK against
        // a column that still holds its just-added NULL default is exactly
        // the ordering bug documented above.
        DB::table('pos_connections')->update(['active_slot' => 1]);

        DB::statement(<<<'SQL'
            ALTER TABLE pos_connections
            ADD CONSTRAINT pos_connections_status_active_slot_consistency
            CHECK (
                (status = 'archived' AND active_slot IS NULL)
                OR (status != 'archived' AND active_slot <=> 1)
            )
        SQL);

        Schema::table('pos_connections', function (Blueprint $table) {
            $table->unique(['outlet_id', 'active_slot']);
        });
    }

    public function down(): void
    {
        // ⚠️ The outlet_id -> restaurant_outlets FOREIGN KEY had no index of
        // its own before this migration (confirmed: SHOW INDEX on a fresh
        // pos_connections showed nothing on outlet_id alone) — MySQL silently
        // adopted the new composite UNIQUE(outlet_id, active_slot) as the
        // FK's supporting index. Dropping that index directly fails with
        // "needed in a foreign key constraint" (1553). A plain index on
        // outlet_id must exist FIRST so the FK has something else to attach
        // to before the composite unique goes away.
        Schema::table('pos_connections', function (Blueprint $table) {
            $table->index('outlet_id', 'pos_connections_outlet_id_index');
        });

        Schema::table('pos_connections', function (Blueprint $table) {
            $table->dropUnique(['outlet_id', 'active_slot']);
        });

        DB::statement('ALTER TABLE pos_connections DROP CHECK pos_connections_status_active_slot_consistency');

        Schema::table('pos_connections', function (Blueprint $table) {
            $table->dropColumn('active_slot');
        });
    }
};
