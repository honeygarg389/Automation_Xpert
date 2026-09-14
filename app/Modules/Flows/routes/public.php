<?php

use App\Modules\Flows\Http\Controllers\Public\FlowDataExchangeController;
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
