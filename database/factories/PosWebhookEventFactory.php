<?php

namespace Database\Factories;

use App\Modules\Restaurant\Models\PosWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosWebhookEvent>
 */
class PosWebhookEventFactory extends Factory
{
    protected $model = PosWebhookEvent::class;

    public function definition(): array
    {
        return [
            'provider' => 'petpooja',
            'payload_hash' => hash('sha256', fake()->uuid()),
            'received_at' => now(),
            'processing_status' => PosWebhookEvent::STATUS_PENDING,
            'raw_payload' => ['event' => 'order.created'],
            'attempts' => 0,
        ];
    }
}
