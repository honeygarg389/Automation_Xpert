<?php

namespace App\Modules\Restaurant;

use App\Modules\Restaurant\Console\Commands\DispatchDueRestaurantFeedbackRequestsCommand;
use App\Modules\Restaurant\Console\Commands\MarkStalledDigitalBillDeliveriesUnknownCommand;
use App\Modules\Restaurant\Console\Commands\MarkStalledRestaurantFeedbackRequestsUnknownCommand;
use App\Modules\Restaurant\Console\Commands\SweepStalledPosWebhookEventsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Restaurant — foundation schema, Petpooja ingress, and order processing.
 *
 * Phase 1B added the public webhook ingress (capture only). Phase 2 slice 2
 * added `ProcessPosWebhookEventJob`/`RestaurantBill` — turning a captured
 * `pending` event into a durable bill — plus this provider's
 * `SweepStalledPosWebhookEventsCommand` registration, the safety net for
 * that job (see the command's own docblock).
 */
class RestaurantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/routes/client.php');
        $this->loadRoutesFrom(__DIR__.'/routes/public.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MarkStalledDigitalBillDeliveriesUnknownCommand::class,
                DispatchDueRestaurantFeedbackRequestsCommand::class,
                MarkStalledRestaurantFeedbackRequestsUnknownCommand::class,
                SweepStalledPosWebhookEventsCommand::class,
            ]);
        }
    }
}
