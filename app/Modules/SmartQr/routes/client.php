<?php

use App\Modules\SmartQr\Http\Controllers\Client\SmartQrCodeController;
use App\Modules\SmartQr\Http\Controllers\Client\SmartQrDashboardController;
use App\Modules\SmartQr\Http\Controllers\Client\SmartQrReportController;
use App\Modules\SmartQr\Http\Middleware\EnsureSmartQrEnabled;
use Illuminate\Support\Facades\Route;

/**
 * §11 — the customer-facing Smart QR pages.
 *
 * ⚠️ `['web', 'client-app']` matches the Social module's client routes exactly.
 * `client-app` is what establishes the tenant session; without it every read
 * through SmartQrAccess would resolve no workspace and fail closed.
 *
 * ⚠️ EnsureSmartQrEnabled on the whole group. R-5 puts `smart_qr_enabled` at
 * DISPLAY, and a customer without it gets 403 plus no nav entry — hidden
 * entirely, not present-and-empty (R-22).
 *
 * ⚠️ THREE pages, not §11's four. Settings is not built: §11 names the heading
 * and never says what is in it, and R-18 already ruled out the only tier that
 * would have given it content. See R-23.
 */
Route::middleware(['web', 'client-app', EnsureSmartQrEnabled::class])
    ->prefix('app/smart-qr')
    ->name('client.smartqr.')
    ->group(function () {
        // A — Overview
        Route::get('/', [SmartQrDashboardController::class, 'overview'])->name('overview');

        // B — My QR Codes
        Route::get('/codes', [SmartQrCodeController::class, 'index'])->name('codes.index');

        // ⚠️ Bound by the PRINTED SERIAL, never the public token. The token is
        // the secret the QR encodes; putting it in a URL would place it in
        // browser history, referers and server logs. Same reasoning as
        // the code model's getRouteKeyName().
        Route::patch('/codes/{serial}', [SmartQrCodeController::class, 'update'])->name('codes.update');

        // §11's preview column and download action — slice 8.
        Route::get('/codes/{serial}/preview.svg', [SmartQrCodeController::class, 'preview'])->name('codes.preview');
        Route::get('/codes/{serial}/download', [SmartQrCodeController::class, 'download'])->name('codes.download');

        // C — Activity
        Route::get('/activity', [SmartQrDashboardController::class, 'activity'])->name('activity');
    });

/**
 * §12 — Smart QR Reports, under the existing client Reports namespace.
 *
 * ⚠️ THE SAME ENTITLEMENT GATE as the pages above, and that is not optional:
 * without it a customer lacking `smart_qr_enabled` would see a Reports nav entry
 * (or follow a bookmark) straight into a 403. The nav is hidden by the shared
 * `features.smart_qr` flag, but hiding is presentation — this is the protection.
 */
Route::middleware(['web', 'client-app', EnsureSmartQrEnabled::class])
    ->prefix('app/reports/smart-qr')
    ->name('client.reports.smartqr.')
    ->group(function () {
        Route::get('/', [SmartQrReportController::class, 'index'])->name('index');

        // ⚠️ R-26 — aggregates only. See the controller.
        Route::get('/export', [SmartQrReportController::class, 'export'])->name('export');
    });
