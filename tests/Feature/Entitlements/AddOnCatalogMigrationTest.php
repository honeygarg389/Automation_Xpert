<?php

namespace Tests\Feature\Entitlements;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ The migration must roll BACK, not only forward.
 *
 * This exists because Phase 0's `dropIndex(['name'])` bug — the array form means
 * "drop the index on these columns", the string form means "drop the index with
 * this name" — was invisible in every forward run and surfaced only on a
 * rollback. Here the equivalent hazard is drop ORDER: `entitlement_grants` and
 * `plan_add_on` both hold foreign keys into `add_ons`, so dropping `add_ons`
 * first fails on MySQL, and no forward run would ever reveal it.
 *
 * ─── ⚠️ THE FIRST VERSION OF THIS TEST PASSED FOR THE WRONG REASON ──────────
 *
 * It used `RefreshDatabase` and called:
 *
 *     Artisan::call('migrate:rollback', ['--step' => 1]);
 *
 * `--step` counts BATCHES, not migrations. `RefreshDatabase` runs `migrate:fresh`,
 * which puts every migration in the application into batch 1 — so "roll back one
 * step" dropped the ENTIRE SCHEMA. The assertion "the five catalog tables are
 * gone" was then true because *all* tables were gone, and it would have stayed
 * green no matter what `down()` contained. It never tested `down()` at all.
 *
 * It also broke every test that ran after it. MySQL implicitly commits on DDL,
 * so the transaction `RefreshDatabase` wraps each test in was destroyed —
 * producing three failures in `CannedReplyCrudTest`, a file this one has nothing
 * to do with, which passed in isolation and failed only in the full suite.
 *
 * So this version:
 *
 *   1. calls `down()` and `up()` on THIS MIGRATION OBJECT, which cannot touch
 *      any other migration regardless of batching;
 *   2. carries a positive control — an unrelated table that must SURVIVE the
 *      rollback. That is the assertion whose absence let the original pass
 *      while dropping the world;
 *   3. does not use `RefreshDatabase`, because there is no honest way to run DDL
 *      inside a transaction that DDL implicitly commits.
 *
 * ─── No cleanup, deliberately ───────────────────────────────────────────────
 *
 * An earlier attempt at the rewrite added `migrate:fresh` to tearDown to "restore
 * the database for everything after". That reintroduced the identical failure —
 * because migrate:fresh DROPS AND RECREATES every table, discarding data and
 * resetting AUTO_INCREMENT mid-run, which is precisely the damage it was added
 * to repair.
 *
 * `up()` on the line below already restores the schema to exactly what `down()`
 * removed, and this class writes no rows. There is nothing left to clean up, and
 * the cleanup was the bug. Measured both ways: with the teardown, 3 failures in
 * CannedReplyCrudTest; without it, 0.
 */
class AddOnCatalogMigrationTest extends TestCase
{
    private const TABLES = ['add_ons', 'add_on_grants', 'add_on_prices', 'plan_add_on', 'entitlement_grants'];

    /**
     * Tables that must be untouched by this migration's `down()`. If a rollback
     * takes any of these with it, it is dropping more than it created.
     */
    private const MUST_SURVIVE = ['plans', 'clients', 'partners', 'users', 'workspaces'];

    private const PATH = 'app/Modules/Entitlements/database/migrations/2026_08_10_100000_create_add_on_catalog_tables.php';

    private function migration(): Migration
    {
        $migration = require base_path(self::PATH);

        $this->assertInstanceOf(Migration::class, $migration,
            'The migration file did not return a Migration instance.');

        return $migration;
    }

    #[Test]
    public function the_catalog_migration_rolls_back_and_forward_again(): void
    {
        $migration = $this->migration();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Precondition: {$table} should exist.");
        }
        $this->assertTrue(Schema::hasColumn('partners', 'entitlement_mode'));

        // ── down() ──────────────────────────────────────────────────────────
        $migration->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table),
                "{$table} survived the rollback. Check the drop ORDER — entitlement_grants and "
                .'plan_add_on hold foreign keys into add_ons and must be dropped first.');
        }

        $this->assertFalse(Schema::hasColumn('partners', 'entitlement_mode'),
            'entitlement_mode survived the rollback, leaving partners carrying a column no code '
            .'understands.');

        // ⚠️ THE POSITIVE CONTROL. Without it, a rollback that drops everything
        // in the database satisfies every assertion above — which is precisely
        // what the first version of this test did, undetected.
        foreach (self::MUST_SURVIVE as $table) {
            $this->assertTrue(Schema::hasTable($table),
                "Rolling back the catalog migration dropped {$table}, which it did not create. "
                .'The assertions above would pass just as happily on an empty database, so this '
                .'is the one that tells them apart.');
        }

        // ── up() again, because a rollback that cannot be re-applied is only
        //    half a rollback ─────────────────────────────────────────────────
        $migration->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} did not come back.");
        }
        $this->assertTrue(Schema::hasColumn('partners', 'entitlement_mode'));
    }
}
