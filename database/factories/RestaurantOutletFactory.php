<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantOutlet>
 */
class RestaurantOutletFactory extends Factory
{
    protected $model = RestaurantOutlet::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->company().' Outlet',
            'address' => fake()->address(),
            'timezone' => 'Asia/Kolkata',
            'status' => RestaurantOutlet::STATUS_PENDING,
        ];
    }
}
