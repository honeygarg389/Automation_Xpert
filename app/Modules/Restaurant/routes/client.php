<?php

use App\Modules\Restaurant\Http\Controllers\Client\RestaurantBrandingController;
use App\Modules\Restaurant\Http\Controllers\Client\RestaurantMessagingSettingsController;
use Illuminate\Support\Facades\Route;

/**
 * Restaurant's tenant surface is intentionally limited to current-workspace
 * messaging preferences. Admin-only outlet/POS lifecycle controls stay in
 * routes/admin.php and are never exposed through this group.
 */
Route::middleware(['web', 'client-app'])
    ->prefix('app/restaurant/messaging-settings')
    ->name('client.restaurant.messaging.')
    ->group(function (): void {
        Route::get('/', [RestaurantMessagingSettingsController::class, 'index'])->name('index');
        Route::put('/outlets/{outlet}', [RestaurantMessagingSettingsController::class, 'update'])->name('outlets.update');
    });

Route::middleware(['web', 'client-app'])
    ->prefix('app/restaurant/branding')
    ->name('client.restaurant.branding.')
    ->group(function (): void {
        Route::get('/', [RestaurantBrandingController::class, 'index'])->name('index');
        Route::put('/', [RestaurantBrandingController::class, 'update'])->name('update');
        Route::put('/outlets/{outlet}', [RestaurantBrandingController::class, 'updateOutlet'])->name('outlets.update');
    });
