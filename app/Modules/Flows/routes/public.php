<?php

use App\Modules\Flows\Http\Controllers\Public\FlowDataExchangeController;
use App\Modules\Flows\Http\Controllers\Public\PublicFlowFormController;
use Illuminate\Support\Facades\Route;

/*
 * Meta's Flow data-exchange callback. Its opaque token resolves the one active
 * phone-number encryption key before a workspace can exist; encryption then
 * authenticates the payload. No browser/session auth belongs on this route.
 */
Route::middleware(['web', 'throttle:webhooks'])->group(function (): void {
    Route::post('webhooks/flows/{token}', FlowDataExchangeController::class)
        ->where('token', '[A-Fa-f0-9]{64}')
        ->name('public.flows.data-exchange');
});

// Unlike a physical Smart QR sticker, repeat form submissions from one IP are
// plausibly automated abuse. Keep browsing permissive, but bind POST attempts
// to the visitor address so one bot cannot exhaust a shared public form.
Route::middleware(['web'])->group(function (): void {
    Route::get('/f/{slug}', [PublicFlowFormController::class, 'show'])
        ->where('slug', '[A-Fa-f0-9]{32}')
        ->middleware('throttle:60,1')
        ->name('public.flows.form.show');

    Route::post('/f/{slug}', [PublicFlowFormController::class, 'submit'])
        ->where('slug', '[A-Fa-f0-9]{32}')
        ->middleware('throttle:flow-form-submissions')
        ->name('public.flows.form.submit');
});
