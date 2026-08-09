<?php

namespace App\Modules\Entitlements;

use App\Modules\Entitlements\Console\Commands\ReportGaugeBreachesCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 1 — the add-on catalog and entitlement layer.
 *
 * No routes yet: slice 1 is schema and models, wired to nothing. The resolver,
 * the facade and the purchase path are later slices.
 */
class EntitlementsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReportGaugeBreachesCommand::class,
            ]);
        }
    }
}
