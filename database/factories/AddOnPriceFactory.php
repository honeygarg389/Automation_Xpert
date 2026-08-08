<?php

namespace Database\Factories;

use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Models\AddOnPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AddOnPrice> */
class AddOnPriceFactory extends Factory
{
    protected $model = AddOnPrice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'add_on_id' => AddOn::factory(),
            'currency_code' => 'USD',
            'interval' => AddOnPrice::INTERVAL_MONTH,
            'price_cents' => $this->faker->numberBetween(500, 50000),
            'is_active' => true,
        ];
    }

    public function oneTime(): static
    {
        return $this->state(fn () => ['interval' => AddOnPrice::INTERVAL_ONE_TIME]);
    }
}
