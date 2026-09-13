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
 */
class RestaurantMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANT_MIGRATIONS_PATH = 'app/Modules/Restaurant/database/migrations';

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
     * `--step=2` rolls back both this migration and the one after it
     * (normalize_pending_outlet_status), leaving pos_connections exactly as
     * it was the moment this bug was hit: no active_slot column, one
     * genuine pre-existing row. The raw insert below reproduces that row.
     */
    #[Test]
    public function the_active_slot_migration_backfills_before_adding_the_check_so_it_does_not_choke_on_a_pre_existing_row(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::RESTAURANT_MIGRATIONS_PATH, '--step' => 2]);

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
     * `--step=1` rolls back ONLY the single newest migration under the
     * Restaurant path (this one), leaving every earlier table — including
     * restaurant_outlets itself, sans the CHECK constraint — in place. That
     * lets a raw insert simulate exactly the pre-fix legacy row: one
     * created by any path that bypassed RestaurantOutletService and fell
     * through to the (at that point) still-'pending' column default.
     */
    #[Test]
    public function the_normalization_migration_fixes_legacy_pending_outlets_and_the_status_check_constraint_holds(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::RESTAURANT_MIGRATIONS_PATH, '--step' => 1]);

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
}
