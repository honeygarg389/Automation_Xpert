<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2, slice 2 — the idempotency guarantee lives in the DATABASE, not in
 * Eloquent. `PetpoojaOrderIngestionService` uses a single-statement upsert
 * (INSERT ... ON DUPLICATE KEY UPDATE); this proves the constraint that upsert
 * targets is real, so a code path that bypasses the service (a seeder, a
 * future importer, a bug) still cannot create a duplicate bill.
 */
class RestaurantBillConstraintTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_database_rejects_a_second_bill_for_the_same_connection_and_order(): void
    {
        $connection = PosConnection::factory()->create();

        RestaurantBill::factory()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'external_order_id' => '114',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/restaurant_bills_connection_order_unique/');

        // Raw insert: bypasses Eloquent entirely, exactly the point.
        DB::table('restaurant_bills')->insert([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'provider' => 'petpooja',
            'external_order_id' => '114',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Positive controls: the constraint is exactly (connection_id, external_order_id) — not broader. */
    #[Test]
    public function the_same_order_id_is_allowed_on_a_different_connection_and_a_different_order_on_the_same_one(): void
    {
        $a = PosConnection::factory()->create();
        $b = PosConnection::factory()->create();

        RestaurantBill::factory()->create(['workspace_id' => $a->workspace_id, 'connection_id' => $a->id, 'external_order_id' => '114']);

        // Petpooja order numbers are per-restaurant counters, so two outlets
        // legitimately both have an order "114".
        RestaurantBill::factory()->create(['workspace_id' => $b->workspace_id, 'connection_id' => $b->id, 'external_order_id' => '114']);
        RestaurantBill::factory()->create(['workspace_id' => $a->workspace_id, 'connection_id' => $a->id, 'external_order_id' => '115']);

        $this->assertSame(3, WorkspaceContext::crossTenant('reason: test counts every tenant\'s bills', fn () => RestaurantBill::query()->count()));
    }
}
