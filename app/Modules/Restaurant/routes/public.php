<?php

use App\Modules\Restaurant\Http\Controllers\Public\PetpoojaWebhookController;
use App\Modules\Restaurant\Http\Controllers\Public\PublicRestaurantBillController;
use App\Modules\Restaurant\Http\Controllers\Public\PublicRestaurantBillProfileController;
use App\Modules\Restaurant\Http\Controllers\Public\PublicRestaurantFeedbackController;
use Illuminate\Support\Facades\Route;

/*
 * Restaurant — unauthenticated surface.
 *
 * Phase 1B: the Petpooja POS webhook ingress. This route is loaded via
 * RestaurantServiceProvider::loadRoutesFrom(), which is NOT wrapped by
 * bootstrap/app.php's own route groups — so 'web' is declared explicitly
 * here, same as every other module's public.php/admin.php in this
 * codebase. 'throttle:webhooks' matches the limiter every other live
 * webhook route in this app already uses (1000/min by client IP, defined
 * in AppServiceProvider::boot()) — chosen over inventing a second,
 * inconsistent webhook rate limit for this one provider.
 *
 * No auth middleware, no tenant/workspace middleware: the controller
 * resolves its own tenant from the payload's restID via
 * PosConnection::findByProviderAndRef() before any of that could exist.
 * CSRF is exempted for this exact path in bootstrap/app.php.
 */
Route::middleware(['web', 'throttle:webhooks'])->group(function () {
    Route::post('webhooks/pos/petpooja', PetpoojaWebhookController::class)
        ->name('public.pos.petpooja');
});

Route::middleware(['web', 'throttle:60,1'])->group(function () {
    Route::get('b/{token}', PublicRestaurantBillController::class)
        ->where('token', '[a-f0-9]{64}')
        ->name('public.restaurant.bills.show');
});

Route::middleware(['web', 'throttle:10,1'])->group(function () {
    Route::post('b/{token}/profile', PublicRestaurantBillProfileController::class)
        ->where('token', '[a-f0-9]{64}')
        ->name('public.restaurant.bills.profile.update');
});

Route::middleware(['web', 'throttle:10,1'])->group(function () {
    Route::get('f/{token}', [PublicRestaurantFeedbackController::class, 'show'])
        ->where('token', '[a-f0-9]{64}')
        ->name('public.restaurant.feedback.show');
    Route::post('f/{token}', [PublicRestaurantFeedbackController::class, 'submit'])
        ->where('token', '[a-f0-9]{64}')
        ->name('public.restaurant.feedback.submit');
});
