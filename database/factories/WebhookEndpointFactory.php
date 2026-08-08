<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),

            // Phase 0 slice 9. workspace_id is NOT NULL now, and it must follow
            // the OWNER — a hardcoded 1 (the pattern in ContactFactory and
            // LeadFactory) would put every endpoint in workspace 1 regardless of
            // which user the test created, so a scoped read would find nothing
            // and the test would fail for a reason that has nothing to do with
            // what it asserts.
            //
            // The closure receives the already-resolved attributes, so this works
            // whether the caller passed user_id explicitly or let the factory
            // make one.
            'workspace_id' => fn (array $attributes) => User::find($attributes['user_id'])?->workspace_id
                ?? User::factory()->create()->workspace_id,
            'url' => $this->faker->url(),
            'secret' => 'whsec_'.bin2hex(random_bytes(16)),
            'events' => ['subscription.created', 'subscription.cancelled'],
            'enabled' => true,
        ];
    }
}
