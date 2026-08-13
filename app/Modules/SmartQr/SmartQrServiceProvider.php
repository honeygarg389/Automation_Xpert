<?php

namespace App\Modules\SmartQr;

use Illuminate\Support\ServiceProvider;

/**
 * Smart QR — dynamic QR codes, platform-generated and tenant-assigned.
 *
 * Slice 1 is schema, models and the access boundary. No routes: nothing is
 * reachable yet.
 */
class SmartQrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
