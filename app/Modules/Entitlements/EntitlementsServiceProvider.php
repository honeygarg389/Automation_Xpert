<?php

namespace App\Modules\Entitlements;

use App\Events\PlanChanged;
use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionExpired;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Models\Client;
use App\Modules\Entitlements\Console\Commands\ReconcileEntitlementsCommand;
use App\Modules\Entitlements\Console\Commands\ReportGaugeBreachesCommand;
use App\Modules\Entitlements\Jobs\ReconcileWorkspaceEntitlements;
use App\Modules\Entitlements\Listeners\InvalidateEntitlementCache;
use App\Modules\Entitlements\Models\EntitlementGrant;
use Illuminate\Support\Facades\Event;
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

        // ⚠️ The five event-driven invalidators. Rule 7: dispatched
        // immediately; the scheduled sweep is the net, never the primary path.
        //
        // Four of the five already had domain events. Grant changes had none, so
        // EntitlementGrant's model hooks supply them below.
        foreach ([
            PlanChanged::class,
            SubscriptionStarted::class,
            SubscriptionCancelled::class,
            SubscriptionExpired::class,
            SubscriptionRenewed::class,
        ] as $event) {
            Event::listen(
                $event,
                InvalidateEntitlementCache::class
            );
        }

        // Grant changes — customer-held AND partner ceiling. saved covers create
        // and update; deleted covers revocation.
        $reconcile = function (EntitlementGrant $grant) {
            if ($grant->client_id !== null) {
                ReconcileWorkspaceEntitlements::dispatch($grant->client_id);
            }
            if ($grant->partner_id !== null) {
                Client::where('partner_id', $grant->partner_id)->pluck('id')
                    ->each(fn ($id) => ReconcileWorkspaceEntitlements::dispatch((int) $id));
            }
        };
        EntitlementGrant::saved($reconcile);
        EntitlementGrant::deleted($reconcile);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReportGaugeBreachesCommand::class,
                ReconcileEntitlementsCommand::class,
            ]);
        }
    }
}
