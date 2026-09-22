<?php

namespace App\Modules\Restaurant\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Restaurant\Models\RestaurantFeedbackRequest;
use App\Modules\Restaurant\Services\RestaurantFeedbackDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendRestaurantFeedbackRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $feedbackRequestId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::from(RestaurantFeedbackRequest::class, $this->feedbackRequestId)];
    }

    public function handle(RestaurantFeedbackDeliveryService $delivery): void
    {
        $delivery->send($this->feedbackRequestId);
    }
}
