<?php

namespace App\Modules\Restaurant;

use Illuminate\Support\ServiceProvider;

/**
 * Restaurant — foundation schema.
 *
 * This slice ships schema and models only: legal document versioning +
 * acceptance tracking, restaurant outlets, POS connections, POS webhook
 * event capture, and the supporting Contact/Workspace/AuditLog columns.
 * Nothing is wired to a route yet — the three route files are placeholders,
 * following the Smart QR module's convention (see SmartQrServiceProvider),
 * so that Phase 1B can add admin/client/public surfaces without touching
 * this provider.
 */
class RestaurantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/routes/client.php');
        $this->loadRoutesFrom(__DIR__.'/routes/public.php');
    }
}
