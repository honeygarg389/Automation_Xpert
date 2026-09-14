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
 */
class RestaurantMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANT_MIGRATIONS_PATH = 'app/Modules/Restaurant/database/migrations';

    /**
     * Roll back exactly $count *Restaurant* migrations, immune to any
     * number of other modules' migrations sorting nearby in time — see the
     * class docblock's CORRECTION for why a literal `--step` cannot do this
     * safely on its own.
     *
     * Walks the applied-migrations list in the EXACT order Laravel's own
     * rollback command uses (`ORDER BY batch DESC, migration DESC`,
     * replicated from `DatabaseMigrationRepository::getMigrations()`),
     * counting every row — Restaurant or not — until $count Restaurant-path
     * matches have been seen. That running total IS the GLOBAL `--step`
     * value that makes `migrate:rollback --path=<restaurant> --step=<n>`
     * actually undo $count Restaurant migrations, whatever else happens to
     * be interleaved with them now or in the future.
     */
    private function rollbackRestaurantMigrations(int $count): void
    {
        $restaurantMigrationNames = collect(glob(base_path(self::RESTAURANT_MIGRATIONS_PATH).'/*.php'))
            ->map(fn (string $path) => basename($path, '.php'))
            ->all();

        $appliedInRollbackOrder = DB::table('migrations')
            ->where('batch', '>=', 1)
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->pluck('migration');

        $globalSteps = 0;
        $restaurantMatches = 0;

        foreach ($appliedInRollbackOrder as $migration) {
            $globalSteps++;

            if (in_array($migration, $restaurantMigrationNames, true)) {
                $restaurantMatches++;

                if ($restaurantMatches === $count) {
                    break;
                }
            }
        }

        $this->assertSame(
            $count,
            $restaurantMatches,
            "Expected to find {$count} applied Restaurant migration(s) to roll back; found {$restaurantMatches}."
        );

        Artisan::call('migrate:rollback', [
            '--path' => self::RESTAURANT_MIGRATIONS_PATH,
            '--step' => $globalSteps,
        ]);
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
     * Rolling back 2 Restaurant migrations undoes both this migration and
     * the one after it (normalize_pending_outlet_status), leaving
     * pos_connections exactly as it was the moment this bug was hit: no
     * active_slot column, one genuine pre-existing row. The raw insert
     * below reproduces that row. (Rolling back "2 Restaurant migrations" —
     * not a literal `--step=2` — is what `rollbackRestaurantMigrations()`
     * exists to do correctly; see its docblock.)
     */
    #[Test]
    public function the_active_slot_migration_backfills_before_adding_the_check_so_it_does_not_choke_on_a_pre_existing_row(): void
    {
        $this->rollbackRestaurantMigrations(2);

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
     * Rolling back 1 Restaurant migration undoes ONLY the single newest
     * migration under the Restaurant path (this one), leaving every earlier
     * table — including restaurant_outlets itself, sans the CHECK
     * constraint — in place. That lets a raw insert simulate exactly the
     * pre-fix legacy row: one created by any path that bypassed
     * RestaurantOutletService and fell through to the (at that point)
     * still-'pending' column default. (Rolling back "1 Restaurant
     * migration" — not a literal `--step=1` — is what
     * `rollbackRestaurantMigrations()` exists to do correctly; see its
     * docblock.)
     */
    #[Test]
    public function the_normalization_migration_fixes_legacy_pending_outlets_and_the_status_check_constraint_holds(): void
    {
        $this->rollbackRestaurantMigrations(1);

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
}
