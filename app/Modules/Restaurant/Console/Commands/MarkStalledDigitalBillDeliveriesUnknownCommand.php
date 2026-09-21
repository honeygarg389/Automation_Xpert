<?php

namespace App\Modules\Restaurant\Console\Commands;

use App\Modules\Restaurant\Services\RestaurantDigitalBillDeliveryService;
use Illuminate\Console\Command;

/** Converts ambiguous stale provider attempts to terminal state; never sends. */
final class MarkStalledDigitalBillDeliveriesUnknownCommand extends Command
{
    protected $signature = 'restaurant:mark-stalled-digital-bill-deliveries-unknown {--minutes=10}';

    protected $description = 'Mark ambiguous stale Digital Bill provider attempts outcome unknown without retrying them';

    public function handle(RestaurantDigitalBillDeliveryService $deliveries): int
    {
        $count = $deliveries->markStalledAttemptsOutcomeUnknown((int) $this->option('minutes'));
        $this->info("Marked {$count} stalled Digital Bill delivery attempt(s) outcome unknown.");

        return self::SUCCESS;
    }
}
