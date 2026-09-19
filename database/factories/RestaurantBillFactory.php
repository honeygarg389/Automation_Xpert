<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantBill>
 */
class RestaurantBillFactory extends Factory
{
    protected $model = RestaurantBill::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'connection_id' => PosConnection::factory(),
            'provider' => PosConnection::PROVIDER_PETPOOJA,
            'external_order_id' => (string) fake()->unique()->numberBetween(1, 1000000),
            'source_order_status' => 'Success',
            'source_created_on_raw' => null,
            'total' => fake()->randomFloat(2, 50, 5000),
            'core_total' => fake()->randomFloat(2, 50, 5000),
            'discount_total' => 0,
            'tax_total' => 0,
            'order_items' => [],
            'taxes' => [],
            'discounts' => [],
            'placed_at' => now(),
            'received_at' => now(),
        ];
    }
}
