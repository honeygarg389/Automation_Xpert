<?php

namespace App\Modules\Restaurant\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Restaurant\Models\RestaurantDigitalBillDelivery;
use App\Modules\Restaurant\Services\RestaurantDigitalBillDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Provider send boundary. It accepts only a trusted ledger identity. */
final class SendRestaurantDigitalBillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::from(RestaurantDigitalBillDelivery::class, $this->deliveryId)];
    }

    public function handle(RestaurantDigitalBillDeliveryService $delivery): void
    {
        $delivery->send($this->deliveryId);
    }
}
