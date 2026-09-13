<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosConnection>
 */
class PosConnectionFactory extends Factory
{
    protected $model = PosConnection::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'provider' => PosConnection::PROVIDER_PETPOOJA,
            'external_ref' => 'ext-'.fake()->unique()->numberBetween(1, 1000000),
            'status' => PosConnection::STATUS_PENDING,
            'environment' => PosConnection::ENVIRONMENT_SANDBOX,
            'last_test_status' => 'untested',
        ];
    }
}
