<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicRestaurantBillTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_valid_opaque_token_renders_only_the_whitelisted_public_bill_contract(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'North Street',
            'address' => '4 North Street',
            'public_phone' => '+91 90000 00000',
        ]);
        $bill = RestaurantBill::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => PosConnection::factory()->create(['workspace_id' => $workspace->id, 'outlet_id' => $outlet->id]),
            'outlet_id' => $outlet->id,
            'external_order_id' => 'PUBLIC-100',
            'customer_name' => 'Never public',
            'customer_phone_raw' => '9999999999',
            'order_items' => [['name' => 'Paneer wrap', 'quantity' => 2, 'price' => 120, 'total' => 240, 'private_note' => 'nope']],
            'taxes' => [['name' => 'GST', 'amount' => 12]],
            'discounts' => [['name' => 'Welcome', 'amount' => 10]],
        ]);

        $response = $this->get(route('public.restaurant.bills.show', $bill->public_token));

        $response->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet')
            ->assertSee('North Street')
            ->assertSee('PUBLIC-100')
            ->assertSee('Paneer wrap')
            ->assertSee('This is a system-generated digital copy.')
            ->assertDontSee('Never public')
            ->assertDontSee('9999999999')
            ->assertDontSee('private_note')
            ->assertDontSee($bill->public_token)
            ->assertSee('name="robots"', false);
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function unknown_malformed_and_revoked_tokens_are_indistinguishable_not_found_responses(): void
    {
        $bill = RestaurantBill::factory()->create(['public_access_revoked_at' => now()]);

        $unknown = $this->get('/b/'.str_repeat('a', 64));
        $malformed = $this->get('/b/not-a-token');
        $revoked = $this->get(route('public.restaurant.bills.show', $bill->public_token));

        $unknown->assertNotFound();
        $malformed->assertNotFound();
        $revoked->assertNotFound();
        $this->assertSame($unknown->getContent(), $revoked->getContent());
    }

    #[Test]
    public function bill_tokens_are_server_generated_lowercase_hex_and_not_mass_assignable(): void
    {
        $first = RestaurantBill::factory()->create(['public_token' => 'attacker-controlled']);
        $second = RestaurantBill::factory()->create();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->public_token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $second->public_token);
        $this->assertNotSame($first->public_token, $second->public_token);
    }
}
