<?php

namespace Database\Factories;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'status' => Partner::STATUS_ACTIVE,
            'owner_admin_user_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Partner::STATUS_INACTIVE]);
    }
}
