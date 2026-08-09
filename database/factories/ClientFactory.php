<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 *
 * Defaults to a PLATFORM-OWNED client — `partner_id = null`. That is not a
 * placeholder awaiting a partner: direct customers are a permanent, supported
 * case, and they are the ones partner features stop exercising. Making them the
 * factory default keeps them in the path of every test that does not think
 * about partners at all.
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'status' => 'active',
            'partner_id' => null,
        ];
    }

    public function forPartner(?Partner $partner = null): static
    {
        return $this->state(fn () => ['partner_id' => $partner ? $partner->id : Partner::factory()]);
    }
}
