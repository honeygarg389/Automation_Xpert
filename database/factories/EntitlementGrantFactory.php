<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Partner;
use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Models\EntitlementGrant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EntitlementGrant>
 *
 * Defaults to a CLIENT-owned grant. There is deliberately no default that sets
 * both owners or neither — the factory must not be able to produce a row the
 * model would refuse, or the constraint tests would be testing the factory.
 */
class EntitlementGrantFactory extends Factory
{
    protected $model = EntitlementGrant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'client_id' => Client::factory(),
            'partner_id' => null,
            'add_on_id' => AddOn::factory(),
            'quantity' => 1,
            'status' => EntitlementGrant::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'source' => EntitlementGrant::SOURCE_PURCHASE,
        ];
    }

    /** A partner's own entitlement — the ceiling side, not a holding. */
    public function forPartner(?Partner $partner = null): static
    {
        return $this->state(fn () => [
            'client_id' => null,
            'partner_id' => $partner ? $partner->id : Partner::factory(),
        ]);
    }

    public function forClient(?Client $client = null): static
    {
        return $this->state(fn () => [
            'partner_id' => null,
            'client_id' => $client ? $client->id : Client::factory(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => EntitlementGrant::STATUS_EXPIRED,
            'ends_at' => now()->subDay(),
        ]);
    }

    /** Rule 9: a manual override carries its reason with it. */
    public function manual(string $reason = 'Goodwill credit after an outage'): static
    {
        return $this->state(fn () => [
            'source' => EntitlementGrant::SOURCE_MANUAL,
            'reason' => $reason,
        ]);
    }
}
