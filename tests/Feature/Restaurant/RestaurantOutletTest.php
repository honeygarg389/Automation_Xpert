<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 3 — RestaurantOutlet::posConnections() must be hasMany, not hasOne,
 * so an outlet can retain prior/inactive connection history.
 */
class RestaurantOutletTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pos_connections_is_a_has_many_relation(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);

        $this->assertInstanceOf(HasMany::class, $outlet->posConnections());
    }

    #[Test]
    public function an_outlet_can_retain_two_pos_connections_and_both_are_retrievable(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);

        $old = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'outlet_id' => $outlet->id,
            'status' => PosConnection::STATUS_DISCONNECTED,
            'external_ref' => 'rest-id-old',
        ]);
        $current = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'outlet_id' => $outlet->id,
            'status' => PosConnection::STATUS_CONNECTED,
            'external_ref' => 'rest-id-new',
        ]);

        $connections = $outlet->posConnections()->get();

        $this->assertCount(2, $connections);
        $this->assertTrue($connections->contains('id', $old->id));
        $this->assertTrue($connections->contains('id', $current->id));
    }

    #[Test]
    public function restaurant_outlet_uses_uuid_as_the_route_key(): void
    {
        $outlet = new RestaurantOutlet;

        $this->assertSame('uuid', $outlet->getRouteKeyName());
    }
}
