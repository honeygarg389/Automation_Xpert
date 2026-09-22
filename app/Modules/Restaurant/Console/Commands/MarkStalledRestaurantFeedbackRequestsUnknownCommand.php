<?php

namespace App\Modules\Restaurant\Console\Commands;

use App\Modules\Restaurant\Services\RestaurantFeedbackDeliveryService;
use Illuminate\Console\Command;

final class MarkStalledRestaurantFeedbackRequestsUnknownCommand extends Command
{
    protected $signature = 'restaurant:mark-stalled-feedback-requests-unknown {--minutes=10 : Minimum age of a sending provider attempt}';

    protected $description = 'Terminalize stale ambiguous Restaurant Feedback provider attempts without resending.';

    public function handle(RestaurantFeedbackDeliveryService $delivery): int
    {
        $this->info('Marked '.$delivery->markStalledAttemptsOutcomeUnknown((int) $this->option('minutes')).' feedback request(s) outcome unknown.');

        return self::SUCCESS;
    }
}
