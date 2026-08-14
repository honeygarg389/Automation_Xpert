<?php

use App\Modules\SmartQr\Http\Controllers\Admin\QrAssignmentController;
use App\Modules\SmartQr\Http\Controllers\Admin\QrBatchController;
use App\Modules\SmartQr\Http\Controllers\Admin\QrInventoryController;
use Illuminate\Support\Facades\Route;

/**
 * ⚠️ THIS FILE DECLARES ITS OWN MIDDLEWARE, PREFIX AND NAME — deliberately.
 *
 * `routes/admin.php` gets `['web', 'auth:admin', 'demo']`, the `admin` prefix
 * and the `admin.` name prefix from a group in `bootstrap/app.php`. That group
 * wraps THAT FILE ONLY. A module route file loaded by its own service provider
 * is outside it, so omitting any of the four here would publish these routes
 * unauthenticated — silently, because they would still work when a signed-in
 * admin visited them.
 *
 * No other module registers admin routes today; this is the first, and the
 * duplication above is the cost of the module owning its own surface.
 */
Route::middleware(['web', 'auth:admin', 'demo'])
    ->prefix('admin/qr')
    ->name('admin.qr.')
    ->group(function () {

        // ── Batches (§4) ───────────────────────────────────────────────────
        Route::get('/batches', [QrBatchController::class, 'index'])
            ->name('batches.index')->middleware('permission:view_qr_inventory');
        Route::post('/batches', [QrBatchController::class, 'store'])
            ->name('batches.store')->middleware('permission:manage_qr_batches');
        Route::get('/batches/{batch}', [QrBatchController::class, 'show'])
            ->name('batches.show')->middleware('permission:view_qr_inventory');

        // ── Inventory (§5) ─────────────────────────────────────────────────
        Route::get('/inventory', [QrInventoryController::class, 'index'])
            ->name('inventory.index')->middleware('permission:view_qr_inventory');
        Route::post('/inventory/mark-printed', [QrInventoryController::class, 'markPrinted'])
            ->name('inventory.mark-printed')->middleware('permission:manage_qr_batches');
        Route::post('/inventory/change-status', [QrInventoryController::class, 'changeStatus'])
            ->name('inventory.change-status')->middleware('permission:manage_qr_batches');

        // ── Assignment (§6) ────────────────────────────────────────────────
        Route::get('/assignments', [QrAssignmentController::class, 'index'])
            ->name('assignments.index')->middleware('permission:view_qr_inventory');
        Route::get('/assignments/options/{workspace}', [QrAssignmentController::class, 'optionsFor'])
            ->name('assignments.options')->middleware('permission:assign_qr_codes');
        Route::post('/assignments', [QrAssignmentController::class, 'store'])
            ->name('assignments.store')->middleware('permission:assign_qr_codes');

        // ⚠️ {assignment} is the UUID, resolved by hand in the controller —
        // SmartQrAssignment's route key is `uuid` AND the model is
        // workspace-scoped, so implicit binding would 404 on a row that exists
        // and the permission check would never run.
        Route::delete('/assignments/{assignment}', [QrAssignmentController::class, 'destroy'])
            ->name('assignments.destroy')->middleware('permission:assign_qr_codes');
    });
