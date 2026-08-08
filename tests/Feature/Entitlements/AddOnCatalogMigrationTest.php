<?php

namespace Tests\Feature\Entitlements;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ The migration must roll BACK, not only forward.
 *
 * This test exists because Phase 0's `dropIndex(['name'])` bug — the array form
 * means "drop the index on these columns", the string form means "drop the index
 * with this name" — was invisible in every forward run and surfaced only on a
 * rollback. A migration nobody has reversed is a migration nobody can reverse
 * during an incident, which is when it matters.
 *
 * The ordering here is the specific hazard: `entitlement_grants` and
 * `plan_add_on` both hold foreign keys into `add_ons`, so dropping `add_ons`
 * first fails on MySQL. Forward runs never exercise that.
 */
class AddOnCatalogMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['add_ons', 'add_on_grants', 'add_on_prices', 'plan_add_on', 'entitlement_grants'];

    #[Test]
    public function the_catalog_migration_rolls_back_and_forward_again(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Precondition: {$table} should exist.");
        }
        $this->assertTrue(Schema::hasColumn('partners', 'entitlement_mode'));

        Artisan::call('migrate:rollback', ['--step' => 1]);

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table),
                "{$table} survived the rollback. Check the drop ORDER — children hold foreign "
                .'keys into add_ons and must go first.');
        }
        $this->assertFalse(Schema::hasColumn('partners', 'entitlement_mode'),
            'entitlement_mode survived the rollback, so partners is left carrying a column no '
            .'code understands.');

        // ...and forward again, because a rollback that cannot be re-applied is
        // only half a rollback.
        Artisan::call('migrate');

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} did not come back.");
        }
        $this->assertTrue(Schema::hasColumn('partners', 'entitlement_mode'));
    }
}
