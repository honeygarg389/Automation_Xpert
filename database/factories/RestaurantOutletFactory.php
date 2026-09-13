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
            // Phase 1C: both outlet-creation paths (standalone "Add Outlet"
            // and the inline create-during-connection flow) create outlets
            // as ACTIVE — STATUS_PENDING predates that rule and is no
            // longer what a real outlet normally starts as.
            'status' => RestaurantOutlet::STATUS_ACTIVE,
        ];
    }
}
