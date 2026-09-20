<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

/**
 * @extends Factory<RestaurantOutlet>
 */
class RestaurantOutletFactory extends Factory
{
    protected $model = RestaurantOutlet::class;

    public function definition(): array
    {
        $definition = [
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

        // RestaurantMigrationRollbackTest deliberately constructs outlets
        // after rolling this additive migration back. In every migrated
        // schema the factory mirrors the database's explicit opt-out defaults;
        // in that historical schema it must not name columns which do not yet
        // exist, or the rollback test could no longer test the older migration.
        if (Schema::hasColumn('restaurant_outlets', 'digital_bill_enabled')) {
            $definition['digital_bill_enabled'] = false;
            $definition['feedback_request_enabled'] = false;
        }

        return $definition;
    }
}
