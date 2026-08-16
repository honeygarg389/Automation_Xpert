<?php

namespace App\Modules\SmartQr;

use App\Modules\SmartQr\Console\Commands\AggregateSmartQrStatsCommand;
use App\Modules\SmartQr\Console\Commands\PruneSmartQrScansCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Smart QR — dynamic QR codes, platform-generated and tenant-assigned.
 *
 * Slice 1 was schema, models and the access boundary. Slice 2 added generation.
 * Slice 3 added the Super Admin surface, slice 4 the PUBLIC redirect
 * (`routes/public.php`, no auth middleware by design), slice 6 the CUSTOMER
 * pages (`routes/client.php`, gated on the smart_qr_enabled entitlement).
 *
 * ⚠️ `routes/admin.php` here is NOT the application's `routes/admin.php`. The
 * application one is wrapped in `['web', 'auth:admin', 'demo']` + the `admin`
 * prefix by `bootstrap/app.php`; a module file loaded here gets none of that,
 * so the module file declares all of it itself. See the note at the top of it.
 */
class SmartQrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/routes/public.php');
        $this->loadRoutesFrom(__DIR__.'/routes/client.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AggregateSmartQrStatsCommand::class,
                PruneSmartQrScansCommand::class,
            ]);
        }
    }
}
