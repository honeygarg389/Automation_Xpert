<?php

namespace App\Modules\Flows;

use Illuminate\Support\ServiceProvider;

/**
 * WhatsApp Flows — workspace-authored static Flow definitions.
 *
 * This follows the Smart QR module convention exactly: ModuleServiceProvider
 * discovers this provider from app/Modules, and this module owns its migrations
 * and route files. The public route file now owns Meta's encrypted dynamic
 * data-exchange callback; the later standalone HTML form remains separate.
 */
class FlowsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/routes/client.php');
        $this->loadRoutesFrom(__DIR__.'/routes/public.php');
    }
}
