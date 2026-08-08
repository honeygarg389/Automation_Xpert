<?php

namespace Database\Factories;

use App\Modules\Entitlements\Models\AddOn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AddOn> */
class AddOnFactory extends Factory
{
    protected $model = AddOn::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'uuid' => (string) Str::uuid(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'description' => $this->faker->sentence(),
            'type' => AddOn::TYPE_PACK,
            'rank' => 0,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** Dominant: highest rank wins outright, never summed. */
    public function package(int $rank = 10): static
    {
        return $this->state(fn () => ['type' => AddOn::TYPE_PACKAGE, 'rank' => $rank]);
    }

    /** Additive: value * quantity, summed onto the package. */
    public function pack(): static
    {
        return $this->state(fn () => ['type' => AddOn::TYPE_PACK, 'rank' => 0]);
    }

    public function feature(): static
    {
        return $this->state(fn () => ['type' => AddOn::TYPE_FEATURE, 'rank' => 0]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
