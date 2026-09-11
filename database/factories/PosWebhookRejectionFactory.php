<?php

namespace Database\Factories;

use App\Modules\Restaurant\Models\PosWebhookRejection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosWebhookRejection>
 */
class PosWebhookRejectionFactory extends Factory
{
    protected $model = PosWebhookRejection::class;

    public function definition(): array
    {
        return [
            'provider' => 'petpooja',
            'payload_hash' => hash('sha256', fake()->uuid()),
            'source_ip' => fake()->ipv4(),
            'failure_reason' => PosWebhookRejection::REASON_UNKNOWN_RESTID,
            'received_at' => now(),
        ];
    }
}
