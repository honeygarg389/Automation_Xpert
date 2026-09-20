<?php

namespace Tests\Feature\Restaurant;

use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 8, #42 (Phase 1A) + Phase 1B addendum — migrate → migrate:rollback →
 * migrate again, confirm a clean state. Targets the Restaurant module's
 * migrations by PATH, not by counting files.
 *
 * ⚠️ WHY --path, NOT A HARDCODED COUNT. The original version rolled back a
 * fixed `--step` count (8 in Phase 1A). Phase 1B added 2 more migrations
 * (raw_body, pos_webhook_rejections) and that count silently went stale:
 * rolling back only 8 of the (by then) 10 undid the 8 MOST RECENT
 * migrations, which left the two OLDEST Phase 1A tables
 * (legal_document_versions, legal_acceptances) still applied — caught by
 * this test itself failing, not predicted in advance.
 *
 * A dynamic `glob()`-based count would fix THIS instance but keeps the same
 * shape of bug alive: it assumes the Restaurant module's migrations are
 * exactly "the N most recently run migrations", which is only true because
 * no other module happens to have a later timestamp right now. `--path`
 * removes that assumption entirely — Laravel filters rollback/migrate to
 * migrations physically located under the given directory, independent of
 * how many there are or where they sort against any other module's. A
 * future Restaurant migration is included automatically; nothing here needs
 * to change when one is added.
 *
 * ⚠️ CORRECTION (fix/restaurant-migration-rollback-check-constraints): the
 * paragraph above is true for an UNSTEPPED rollback (no `--step`, used by
 * the two "full round trip" tests below) but was WRONG about `--step`.
 * `migrate:rollback --step=N` is NOT "roll back the N most recent
 * migrations within --path". Laravel's `Migrator::getMigrationsForRollback()`
 * calls `MigrationRepository::getMigrations($steps)`, which queries the
 * `migrations` table GLOBALLY — `ORDER BY batch DESC, migration DESC LIMIT
 * $steps` — with NO knowledge of `--path` at all. `--path` is only applied
 * afterwards, in `rollbackMigrations()`, which silently SKIPS (does not
 * substitute) any of those N global rows whose file isn't under the given
 * path. So a `--step` this test needs to correctly target N *Restaurant*
 * migrations must be large enough to also "spend" a step on every
 * non-Restaurant migration that sorts (by batch, then filename) ahead of
 * or between them — and this codebase already has two: `app/Modules/Flows/
 * .../2026_09_16_100000_create_whatsapp_flows_table.php` (sorts after every
 * Restaurant migration) and `app/Modules/Shared/.../2026_09_13_100000_
 * add_profile_fields_to_contacts_table.php` (sorts between two Restaurant
 * migrations dated the same day). A literal `--step=2` for "the 2 most
 * recent Restaurant migrations" silently became `--step=1`'s worth of real
 * work once `create_whatsapp_flows_table` was merged on top — reproduced,
 * confirmed via `Migrator`'s source, and fixed below by computing the
 * correct GLOBAL step count instead of guessing a literal one. See
 * `rollbackRestaurantMigrations()`.
 *
 * ⚠️ THIRD CORRECTION (Petpooja Phase 2A gate-hardening pass): the fix above
 * computed the correct global step count dynamically, but still took an
 * `int $count` of "how many Restaurant migrations" as input — which meant
 * every call site had to know, and keep updating, how many Restaurant
 * migrations currently exist above the one it actually cares about. That
 * count went stale the moment `add_default_phone_country_to_pos_connections_table`
 * (Phase 2A Slice 1) landed as the new newest Restaurant migration: both
 * call sites below needed a manual "+1" for no reason connected to what
 * they were actually testing. `rollbackRestaurantMigrations()` now takes
 * the target migration's own name instead of a count — see its docblock
 * and `calculateGlobalStepsToReach()`.
 */
class RestaurantMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANT_MIGRATIONS_PATH = 'app/Modules/Restaurant/database/migrations';

    /**
     * SECOND CORRECTION (Petpooja Phase 2A gate-hardening pass): this
     * helper used to take an `int $count` — "roll back the N most recent
     * Restaurant migrations". That count had to be manually bumped every
     * time a new Restaurant migration was added on top of the ones it was
     * written against, which is the exact same staleness the FIRST
     * correction above already fixed once for the un-stepped rollback, just
     * moved one layer up into this helper instead of removed.
     *
     * The helper now takes the TARGET migration's own name and walks the
     * applied list to find it, computing however many global steps that
     * turns out to require. Adding any number of newer Restaurant
     * migrations above a named target changes nothing at any call site.
     *
     * Pure arithmetic, deliberately factored out of `rollbackRestaurantMigrations()`
     * so it can be exercised directly against a fabricated applied-migrations
     * list — see
     * `the_global_step_calculation_accounts_for_a_newer_restaurant_migration_and_interleaved_module_migrations`
     * — proving the counting logic itself stays correct when a newer
     * Restaurant migration (or an unrelated module's migration sorting
     * between two Restaurant ones) exists, without depending on the
     * repository's CURRENT migration set to happen to contain one.
     *
     * $appliedInRollbackOrder must already be in the exact order Laravel's
     * own rollback command uses (`ORDER BY batch DESC, migration DESC`,
     * replicated from `DatabaseMigrationRepository::getMigrations()`).
     * Counts every entry — Restaurant or not — until $throughMigration is
     * reached; that running total is the GLOBAL `--step` value that makes
     * `migrate:rollback --path=<restaurant> --step=<n>` reach it.
     *
     * @param  list<string>  $appliedInRollbackOrder
     * @param  list<string>  $restaurantMigrationNames
     */
    private function calculateGlobalStepsToReach(
        array $appliedInRollbackOrder,
        array $restaurantMigrationNames,
        string $throughMigration,
    ): int {
        $this->assertContains(
            $throughMigration,
            $restaurantMigrationNames,
            "'{$throughMigration}' is not a migration file under ".self::RESTAURANT_MIGRATIONS_PATH.'.'
        );

        $globalSteps = 0;

        foreach ($appliedInRollbackOrder as $migration) {
            $globalSteps++;

            if ($migration === $throughMigration) {
                return $globalSteps;
            }
        }

        $this->fail("Expected to find applied migration '{$throughMigration}' to roll back through; it was not found in the applied migrations table.");
    }

    /**
     * Roll back every Restaurant migration from the top down through, and
     * including, $throughMigration — named by its migration filename
     * (without `.php`) — immune to any number of other modules' migrations
     * sorting nearby in time, AND immune to any number of newer Restaurant
     * migrations added above it later.
     */
    private function rollbackRestaurantMigrations(string $throughMigration): void
    {
        $restaurantMigrationNames = collect(glob(base_path(self::RESTAURANT_MIGRATIONS_PATH).'/*.php'))
            ->map(fn (string $path) => basename($path, '.php'))
            ->all();

        $appliedInRollbackOrder = DB::table('migrations')
            ->where('batch', '>=', 1)
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->pluck('migration')
            ->all();

        $globalSteps = $this->calculateGlobalStepsToReach($appliedInRollbackOrder, $restaurantMigrationNames, $throughMigration);

        Artisan::call('migrate:rollback', [
            '--path' => self::RESTAURANT_MIGRATIONS_PATH,
            '--step' => $globalSteps,
        ]);
    }

    #[Test]
    public function messaging_settings_migration_backfills_existing_outlets_to_off_via_its_database_defaults(): void
    {
        $this->rollbackRestaurantMigrations('2026_09_20_100300_add_messaging_settings_to_restaurant_outlets_table');

        $workspace = Workspace::factory()->create();
        $outletId = DB::table('restaurant_outlets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Pre-existing outlet',
            'status' => RestaurantOutlet::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('migrate', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);

        $this->assertSame(0, (int) DB::table('restaurant_outlets')->where('id', $outletId)->value('digital_bill_enabled'));
        $this->assertSame(0, (int) DB::table('restaurant_outlets')->where('id', $outletId)->value('feedback_request_enabled'));
    }

    #[Test]
    public function the_global_step_calculation_accounts_for_a_newer_restaurant_migration_and_interleaved_module_migrations(): void
    {
        // Fabricated, independent of whatever migrations actually exist on
        // disk right now — this is what makes the test a REGRESSION guard
        // rather than an accident of today's migration set. Mirrors the
        // exact shape the class docblock's corrections describe: a
        // Restaurant migration newer than the target, and an unrelated
        // module's migration sorting between two Restaurant ones.
        $appliedInRollbackOrder = [
            '2026_09_20_100000_some_future_restaurant_migration',   // newer Restaurant migration
            '2026_09_19_100000_unrelated_flows_migration',          // interleaved non-Restaurant migration
            '2026_09_18_100000_add_default_phone_country_to_pos_connections_table',
            '2026_09_15_100000_normalize_pending_outlet_status_to_active', // target
            '2026_09_14_100000_add_lifecycle_status_and_active_slot_to_pos_connections_table',
            '2026_09_11_100200_create_restaurant_outlets_table',
        ];

        $restaurantMigrationNames = [
            '2026_09_20_100000_some_future_restaurant_migration',
            '2026_09_18_100000_add_default_phone_country_to_pos_connections_table',
            '2026_09_15_100000_normalize_pending_outlet_status_to_active',
            '2026_09_14_100000_add_lifecycle_status_and_active_slot_to_pos_connections_table',
            '2026_09_11_100200_create_restaurant_outlets_table',
        ];

        $steps = $this->calculateGlobalStepsToReach(
            $appliedInRollbackOrder,
            $restaurantMigrationNames,
            '2026_09_15_100000_normalize_pending_outlet_status_to_active',
        );

        // Position 4 in the fabricated list: 1 newer Restaurant migration +
        // 1 interleaved non-Restaurant migration + 1 older Restaurant
        // migration (2026_09_18) sit above the target, and the target
        // itself is the 4th entry. A count-based helper asked for "2
        // Restaurant migrations" would have stopped after only 3 global
        // steps (matching 2026_09_18 as Restaurant match #1 and the target
        // as #2, without ever spending a step on the interleaved flows
        // migration) — silently 1 step short of what
        // `migrate:rollback --path=... --step=3` actually needs to reach
        // the target, exactly the defect this helper exists to prevent.
        $this->assertSame(4, $steps,
            'Must count every applied migration — Restaurant or not — from the top through the named target, inclusive.');
    }

    #[Test]
    public function the_restaurant_foundation_migrations_roll_back_and_reapply_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('legal_document_versions'));
        $this->assertTrue(Schema::hasTable('legal_acceptances'));
        $this->assertTrue(Schema::hasTable('restaurant_outlets'));
        $this->assertTrue(Schema::hasTable('pos_connections'));
        $this->assertTrue(Schema::hasTable('pos_webhook_events'));
        $this->assertTrue(Schema::hasColumn('pos_webhook_events', 'raw_body'));
        $this->assertTrue(Schema::hasTable('pos_webhook_rejections'));
        $this->assertTrue(Schema::hasColumn('contacts', 'whatsapp_consent_at'));
        $this->assertTrue(Schema::hasColumn('workspaces', 'client_mode'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'workspace_id'));
        $this->assertTrue(Schema::hasColumn('pos_connections', 'environment'));
        $this->assertTrue(Schema::hasColumn('pos_connections', 'active_slot'));

        Artisan::call('migrate:rollback', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);

        $this->assertFalse(Schema::hasTable('legal_document_versions'));
        $this->assertFalse(Schema::hasTable('legal_acceptances'));
        $this->assertFalse(Schema::hasTable('restaurant_outlets'));
        $this->assertFalse(Schema::hasTable('pos_connections'));
        $this->assertFalse(Schema::hasTable('pos_webhook_events'));
        $this->assertFalse(Schema::hasTable('pos_webhook_rejections'));
        $this->assertFalse(Schema::hasColumn('contacts', 'whatsapp_consent_at'));
        $this->assertFalse(Schema::hasColumn('workspaces', 'client_mode'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'workspace_id'));

        // The rest of the schema (from before this branch) must be untouched.
        $this->assertTrue(Schema::hasTable('contacts'));
        $this->assertTrue(Schema::hasTable('workspaces'));

        Artisan::call('migrate', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);

        $this->assertTrue(Schema::hasTable('legal_document_versions'));
        $this->assertTrue(Schema::hasTable('legal_acceptances'));
        $this->assertTrue(Schema::hasTable('restaurant_outlets'));
        $this->assertTrue(Schema::hasTable('pos_connections'));
        $this->assertTrue(Schema::hasTable('pos_webhook_events'));
        $this->assertTrue(Schema::hasColumn('pos_webhook_events', 'raw_body'));
        $this->assertTrue(Schema::hasTable('pos_webhook_rejections'));
        $this->assertTrue(Schema::hasColumn('contacts', 'whatsapp_consent_at'));
        $this->assertTrue(Schema::hasColumn('workspaces', 'client_mode'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'workspace_id'));
        $this->assertTrue(Schema::hasColumn('pos_connections', 'environment'));
        $this->assertTrue(Schema::hasColumn('pos_connections', 'active_slot'));

        // A second non-archived connection on the same outlet must still be
        // rejected after the reapplied migration — prove the UNIQUE(outlet_id,
        // active_slot) constraint survived the round-trip, not just the column.
        $outlet = RestaurantOutlet::factory()->create();
        PosConnection::factory()->create(['outlet_id' => $outlet->id]);
        $this->expectException(QueryException::class);
        PosConnection::factory()->create(['outlet_id' => $outlet->id]);
    }

    #[Test]
    public function the_check_constraint_survives_the_round_trip(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);
        Artisan::call('migrate', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);

        // The CHECK constraint must have been recreated by the reapplied
        // migration, not silently dropped — prove it still rejects.
        $this->expectException(QueryException::class);
        DB::table('legal_document_versions')->insert([
            'document_type' => 'terms',
            'version' => 'rollback-check',
            'status' => 'published',
            'published_slot' => null,
            'content_body' => 'x',
            'content_sha256' => hash('sha256', 'x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ⚠️ THE SECOND REAL DEFECT, FOUND THE SAME WAY (running the migration
     * against the actual working database, not just the test suite): the
     * active_slot CHECK constraint was originally added BEFORE the backfill
     * UPDATE populated it. Every test runs this migration against an EMPTY
     * pos_connections table (RefreshDatabase migrates before any factory
     * inserts a row), so that ordering never failed here — it failed
     * immediately (MySQL error 3819) the first time it ran against the real
     * `whatsmine` database, which had one pre-existing 'connected' row.
     *
     * Rolling back THROUGH this migration by name also undoes every
     * Restaurant migration newer than it — currently just
     * normalize_pending_outlet_status and Phase 2A Slice 1's
     * add_default_phone_country_to_pos_connections_table (a plain nullable
     * column add with nothing to reproduce here, along for the ride only
     * because it currently happens to be the newest Restaurant migration)
     * — leaving pos_connections exactly as it was the moment this bug was
     * hit: no active_slot column, one genuine pre-existing row. The raw
     * insert below reproduces that row. (`rollbackRestaurantMigrations()`
     * now takes this migration's own name, not a count — see its docblock.
     * Any future Restaurant migration added above this one changes nothing
     * here: the target is still found by name, at whatever depth it now
     * sits.)
     */
    #[Test]
    public function the_active_slot_migration_backfills_before_adding_the_check_so_it_does_not_choke_on_a_pre_existing_row(): void
    {
        $this->rollbackRestaurantMigrations('2026_09_14_100000_add_lifecycle_status_and_active_slot_to_pos_connections_table');

        // Prove the rollback itself actually reached both intended
        // migrations — not just that the later insert/re-migrate happens
        // not to throw. This is the assertion that would have caught the
        // original defect directly: a `--step` that silently rolled back
        // fewer Restaurant migrations than asked left this column (and its
        // CHECK constraint) still in place.
        $this->assertFalse(Schema::hasColumn('pos_connections', 'active_slot'),
            'active_slot must not exist once the migration that adds it has been rolled back.');

        $outlet = RestaurantOutlet::factory()->create();
        DB::table('pos_connections')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'provider' => 'petpooja',
            'external_ref' => 'REST-PREEXISTING-1',
            'status' => 'connected',
            'environment' => 'sandbox',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Must not throw. This line alone reproduces the measured failure:
        // before the fix, adding the CHECK constraint against this
        // pre-existing row (active_slot still NULL at that point) raised
        // "Check constraint ... is violated" and the migration never
        // completed.
        Artisan::call('migrate', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);

        $connection = PosConnection::query()->where('external_ref', 'REST-PREEXISTING-1')->firstOrFail();
        $this->assertSame(1, $connection->active_slot,
            'The pre-existing row must be backfilled to active_slot=1, not left NULL.');
    }

    /**
     * Section H — the real defect: `restaurant_outlets.status` defaulted to
     * 'pending' at the COLUMN level (predating Phase 1C), so any outlet
     * created by a path that relied on that default rather than
     * RestaurantOutletService silently became ineligible forever — it read
     * as "Not connected" yet could never be selected to connect one.
     *
     * Rolling back THROUGH this migration by name also undoes every
     * Restaurant migration newer than it — currently just Phase 2A Slice
     * 1's add_default_phone_country_to_pos_connections_table (a plain
     * nullable column add, along for the ride only because it currently
     * happens to be the newest Restaurant migration) — leaving every
     * earlier table, including restaurant_outlets itself sans the CHECK
     * constraint, in place. That lets a raw insert simulate exactly the
     * pre-fix legacy row: one created by any path that bypassed
     * RestaurantOutletService and fell through to the (at that point)
     * still-'pending' column default. (Same "named target, not a fixed
     * count" note as the sibling test above — see
     * `rollbackRestaurantMigrations()`'s docblock.)
     */
    #[Test]
    public function the_normalization_migration_fixes_legacy_pending_outlets_and_the_status_check_constraint_holds(): void
    {
        $this->rollbackRestaurantMigrations('2026_09_15_100000_normalize_pending_outlet_status_to_active');

        // Prove the rollback actually reached this migration — not just
        // that the later insert/re-migrate happens not to throw. This
        // migration's down() resets the column default back to 'pending'
        // and drops the CHECK constraint; if the rollback silently reached
        // fewer Restaurant migrations than asked (the original defect),
        // this default would still read 'active' here.
        $statusColumn = collect(Schema::getColumns('restaurant_outlets'))->firstWhere('name', 'status');
        $this->assertNotNull($statusColumn, 'restaurant_outlets.status must still exist after rolling back only the newest Restaurant migration.');
        $this->assertSame('pending', $statusColumn['default'],
            "The restaurant_outlets.status column's DEFAULT must read 'pending' immediately after rollback — proof the normalization migration was actually undone, not skipped.");

        $workspace = Workspace::factory()->create();
        DB::table('restaurant_outlets')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Legacy Food Court',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('migrate', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);

        // withoutWorkspaceScope(): this test runs with no admin/tenant
        // context at all (no acting-as, no job/command context), so the
        // scope fails CLOSED here exactly as documented on WorkspaceScope
        // itself — a plain query would silently return nothing.
        $outlet = RestaurantOutlet::withoutWorkspaceScope('reason: test asserts on a raw-inserted row with no ambient admin/tenant context')
            ->where('name', 'Legacy Food Court')->firstOrFail();
        $this->assertSame(RestaurantOutlet::STATUS_ACTIVE, $outlet->status,
            'The migration must normalize a pre-existing pending outlet to active.');

        // The actual defect: a normalized, unconnected legacy outlet must
        // now appear in its own workspace's Existing Outlet dropdown.
        $this->assertTrue(
            RestaurantOutlet::eligibleForNewConnection($workspace->id)->where('id', $outlet->id)->exists(),
            'A normalized legacy outlet with no connection must be selectable in its workspace\'s Existing Outlet dropdown.'
        );

        // And the CHECK constraint is live going forward — the same defect
        // (a row silently inheriting 'pending') can no longer recur.
        $this->expectException(QueryException::class);
        DB::table('restaurant_outlets')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Should Never Persist',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ══ Invariant coverage: invalid status/active_slot combinations stay
    //    rejected on the fully-migrated schema, independent of the rollback
    //    tests above. `PosConnection::saving()` derives active_slot from
    //    status automatically, so the Eloquent path can never construct
    //    these — these tests bypass it with a raw insert specifically to
    //    exercise the DB-level CHECK constraint itself, the backstop for
    //    anything that isn't the model (a seeder, a direct SQL client, a
    //    bug in the derivation logic). Each negative case is paired with a
    //    positive control on the SAME route/mechanism per CLAUDE.md's
    //    "every is-blocked test needs a positive control" convention. ══

    #[Test]
    public function an_archived_pos_connection_must_have_a_null_active_slot(): void
    {
        $outlet = RestaurantOutlet::factory()->create();

        // Positive control: archived with active_slot NULL is the valid
        // shape and must be accepted.
        DB::table('pos_connections')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'provider' => 'petpooja',
            'external_ref' => 'REST-ARCHIVED-VALID',
            'status' => PosConnection::STATUS_ARCHIVED,
            'environment' => 'sandbox',
            'active_slot' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('pos_connections', ['external_ref' => 'REST-ARCHIVED-VALID', 'active_slot' => null]);

        // Negative case: the same outlet, archived, but with active_slot
        // wrongly occupying the slot — must be rejected. An archived
        // connection sitting in the active slot is exactly the state that
        // would make outlet eligibility computations (eligibleForNewConnection)
        // see the outlet as "still connected" when it is not.
        $this->expectException(QueryException::class);
        DB::table('pos_connections')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'provider' => 'petpooja',
            'external_ref' => 'REST-ARCHIVED-INVALID',
            'status' => PosConnection::STATUS_ARCHIVED,
            'environment' => 'sandbox',
            'active_slot' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_non_archived_pos_connection_must_have_active_slot_one(): void
    {
        $outlet = RestaurantOutlet::factory()->create();

        // Positive control: connected with active_slot = 1 is the valid
        // shape and must be accepted.
        DB::table('pos_connections')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'provider' => 'petpooja',
            'external_ref' => 'REST-CONNECTED-VALID',
            'status' => PosConnection::STATUS_CONNECTED,
            'environment' => 'sandbox',
            'active_slot' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('pos_connections', ['external_ref' => 'REST-CONNECTED-VALID', 'active_slot' => 1]);

        // Negative case: connected (non-archived) but with active_slot left
        // NULL — must be rejected. This is precisely the ordering defect
        // this branch fixed: a non-archived row must never be allowed to
        // sit with an unpopulated active_slot.
        $this->expectException(QueryException::class);
        DB::table('pos_connections')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'provider' => 'petpooja',
            'external_ref' => 'REST-CONNECTED-INVALID',
            'status' => PosConnection::STATUS_CONNECTED,
            'environment' => 'sandbox',
            'active_slot' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function an_outlet_status_outside_active_or_archived_is_rejected(): void
    {
        $workspace = Workspace::factory()->create();

        // Positive control: 'archived' — the other half of the two
        // production-valid outlet states (§Required invariants: "only
        // active / archived") — must be accepted on the current schema.
        DB::table('restaurant_outlets')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Closed Location',
            'status' => RestaurantOutlet::STATUS_ARCHIVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('restaurant_outlets', ['name' => 'Closed Location', 'status' => RestaurantOutlet::STATUS_ARCHIVED]);

        // Negative case: a status that is neither 'active' nor 'archived'
        // (the legacy 'pending' default this whole migration exists to
        // eliminate, or any other arbitrary value) must still be rejected.
        $this->expectException(QueryException::class);
        DB::table('restaurant_outlets')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Invalid Status Outlet',
            'status' => 'suspended',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * `add_source_fields_to_restaurant_bills_table` changes what `placed_at`
     * MEANS (it used to be the timezone-less `created_on` read as UTC, falling
     * back to the receive time — a guess). Rows written under the old behaviour
     * must not keep claiming a certainty they never had, and must get the new
     * operational ordering key. Rolls back to just before that migration by
     * NAME (see rollbackRestaurantMigrations()), seeds a legacy row, re-applies.
     */
    #[Test]
    public function the_source_fields_migration_repairs_legacy_bill_rows_and_rolls_back_cleanly(): void
    {
        $this->rollbackRestaurantMigrations('2026_09_20_100200_add_source_fields_to_restaurant_bills_table');

        $this->assertFalse(Schema::hasColumn('restaurant_bills', 'source_order_status'));
        $this->assertFalse(Schema::hasColumn('restaurant_bills', 'source_created_on_raw'));
        $this->assertFalse(Schema::hasColumn('restaurant_bills', 'received_at'));
        $this->assertTrue(Schema::hasTable('restaurant_bills'), 'Only the source-fields migration should have been undone.');

        $connection = PosConnection::factory()->create();
        DB::table('restaurant_bills')->insert([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'provider' => 'petpooja',
            'external_order_id' => 'LEGACY-1',
            // The old behaviour: timezone-less created_on stored as if UTC.
            'placed_at' => '2025-04-04 11:45:35',
            'created_at' => '2025-04-04 12:00:00',
            'updated_at' => '2025-04-04 12:00:00',
        ]);

        Artisan::call('migrate', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);

        $this->assertTrue(Schema::hasColumn('restaurant_bills', 'source_order_status'));
        $this->assertTrue(Schema::hasColumn('restaurant_bills', 'source_created_on_raw'));
        $this->assertTrue(Schema::hasColumn('restaurant_bills', 'received_at'));

        $legacy = DB::table('restaurant_bills')->where('external_order_id', 'LEGACY-1')->first();
        $this->assertNull($legacy->placed_at, 'A UTC guess must not survive as if it were an order time.');
        $this->assertSame('2025-04-04 12:00:00', $legacy->received_at, 'The legacy row gets its arrival time as the ordering key.');
        $this->assertNull($legacy->source_order_status);
        $this->assertNull($legacy->source_created_on_raw);
    }
}
