<?php

namespace Database\Factories;

use App\Modules\Restaurant\Models\LegalAcceptance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalAcceptance>
 */
class LegalAcceptanceFactory extends Factory
{
    protected $model = LegalAcceptance::class;

    public function definition(): array
    {
        return [
            'content_sha256' => hash('sha256', fake()->uuid()),
            'accepted_at' => now(),
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
