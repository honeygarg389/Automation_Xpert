<?php

use App\Modules\Restaurant\Http\Controllers\Client\RestaurantBrandingController;
use App\Modules\Restaurant\Http\Controllers\Client\RestaurantDigitalBillDeliveryConfigController;
use App\Modules\Restaurant\Http\Controllers\Client\RestaurantMessagingSettingsController;
use App\Modules\Restaurant\Http\Controllers\Client\RestaurantProfileMessagingController;
use Illuminate\Support\Facades\Route;

/**
 * Restaurant's tenant surface is intentionally limited to current-workspace
 * messaging preferences. Admin-only outlet/POS lifecycle controls stay in
 * routes/admin.php and are never exposed through this group.
 */
Route::middleware(['web', 'client-app'])
    ->prefix('app/restaurant/profile-messaging')
    ->name('client.restaurant.profile-messaging.')
    ->group(function (): void {
        Route::get('/', [RestaurantProfileMessagingController::class, 'index'])->name('index');
        Route::put('/profile', [RestaurantProfileMessagingController::class, 'updateProfile'])->name('profile.update');
        Route::put('/outlets/{outlet}', [RestaurantProfileMessagingController::class, 'updateOutlet'])->name('outlets.update');
        Route::put('/outlets/{outlet}/messaging', [RestaurantProfileMessagingController::class, 'updateMessaging'])->name('outlets.messaging.update');
        Route::put('/outlets/{outlet}/digital-bill', [RestaurantProfileMessagingController::class, 'updateDigitalBillConfig'])->name('outlets.digital-bill.update');
        Route::put('/outlets/{outlet}/feedback', [RestaurantProfileMessagingController::class, 'updateFeedbackConfig'])->name('outlets.feedback.update');
    });

Route::middleware(['web', 'client-app'])
    ->prefix('app/restaurant/messaging-settings')
    ->name('client.restaurant.messaging.')
    ->group(function (): void {
        Route::get('/', [RestaurantMessagingSettingsController::class, 'index'])->name('index');
        Route::put('/outlets/{outlet}', [RestaurantMessagingSettingsController::class, 'update'])->name('outlets.update');
        Route::put('/outlets/{outlet}/digital-bill-delivery-config', [RestaurantDigitalBillDeliveryConfigController::class, 'update'])
            ->name('outlets.digital-bill-delivery-config.update');
    });

Route::middleware(['web', 'client-app'])
    ->prefix('app/restaurant/branding')
    ->name('client.restaurant.branding.')
    ->group(function (): void {
        Route::get('/', [RestaurantBrandingController::class, 'index'])->name('index');
        Route::put('/', [RestaurantBrandingController::class, 'update'])->name('update');
        Route::put('/outlets/{outlet}', [RestaurantBrandingController::class, 'updateOutlet'])->name('outlets.update');
    });
