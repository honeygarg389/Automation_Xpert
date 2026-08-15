<?php

namespace App\Modules\SmartQr;

use Illuminate\Support\ServiceProvider;

/**
 * Smart QR — dynamic QR codes, platform-generated and tenant-assigned.
 *
 * Slice 1 was schema, models and the access boundary. Slice 2 added generation.
 * Slice 3 added the Super Admin surface. Slice 4 adds the PUBLIC redirect —
 * `routes/public.php`, which carries no auth middleware by design.
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
    }
}
