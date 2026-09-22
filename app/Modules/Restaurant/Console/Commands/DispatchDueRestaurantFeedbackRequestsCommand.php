<?php

namespace App\Modules\Restaurant\Console\Commands;

use App\Modules\Restaurant\Services\RestaurantFeedbackDeliveryService;
use Illuminate\Console\Command;

final class DispatchDueRestaurantFeedbackRequestsCommand extends Command
{
    protected $signature = 'restaurant:dispatch-due-feedback-requests';

    protected $description = 'Dispatch due Restaurant Feedback Request jobs without sending directly.';

    public function handle(RestaurantFeedbackDeliveryService $delivery): int
    {
        $this->info('Dispatched '.$delivery->dispatchDue().' due feedback request(s).');

        return self::SUCCESS;
    }
}
