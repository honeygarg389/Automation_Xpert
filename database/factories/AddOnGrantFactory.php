<?php

namespace Database\Factories;

use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Models\AddOnGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AddOnGrant> */
class AddOnGrantFactory extends Factory
{
    protected $model = AddOnGrant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'add_on_id' => AddOn::factory(),
            'key' => 'campaigns_per_month',
            'kind' => AddOnGrant::KIND_COUNTER,
            'value' => 100,
        ];
    }

    /** Cardinality — COUNT(*) at request time, never a meter. See BUG-024. */
    public function gauge(string $key = 'chatbots', ?int $value = 5): static
    {
        return $this->state(fn () => ['key' => $key, 'kind' => AddOnGrant::KIND_GAUGE, 'value' => $value]);
    }

    public function boolean(string $key = 'white_label'): static
    {
        return $this->state(fn () => ['key' => $key, 'kind' => AddOnGrant::KIND_BOOLEAN, 'value' => null]);
    }

    /** NULL value = unlimited, matching plans.limits' existing convention. */
    public function unlimited(): static
    {
        return $this->state(fn () => ['value' => null]);
    }
}
