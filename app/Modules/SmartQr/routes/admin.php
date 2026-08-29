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

        // ⚠️ Rename, delete and retire all gate on manage_qr_batches — the same
        // key that creates them. Deleting is destructive but it is refused
        // outright for anything printed or ever assigned, so the dangerous case
        // is unreachable rather than permission-gated.
        Route::patch('/batches/{batch}', [QrBatchController::class, 'update'])
            ->name('batches.update')->middleware('permission:manage_qr_batches');
        Route::delete('/batches/{batch}', [QrBatchController::class, 'destroy'])
            ->name('batches.destroy')->middleware('permission:manage_qr_batches');
        Route::post('/batches/{batch}/retire', [QrBatchController::class, 'retire'])
            ->name('batches.retire')->middleware('permission:manage_qr_batches');

        // ⚠️ manage_qr_batches, NOT view_qr_inventory. This CREATES inventory —
        // it is the only route besides store() that causes codes to exist — so it
        // belongs with the mutating actions above, not with the export routes
        // below that merely read what is already there.
        Route::post('/batches/{batch}/add-codes', [QrBatchController::class, 'addCodes'])
            ->name('batches.add-codes')->middleware('permission:manage_qr_batches');

        // ⚠️ Batch-scoped export — SEPARATE from /inventory/export, not a
        // variant of it. That route takes a client-supplied code_ids[] because
        // the inventory screen selects arbitrary codes across batches; this one
        // reads ONLY `format` and resolves the ids from the batch server-side.
        //
        // ⚠️ Gated on view_qr_inventory, matching batches.show and
        // inventory.export — exporting reads inventory and writes no domain
        // state (the smart_qr_exports rows are bookkeeping for the archive, not
        // a change to any batch or code). manage_qr_batches gates the actions
        // that alter the batch itself: rename, delete, retire.
        Route::post('/batches/{batch}/export', [QrBatchController::class, 'export'])
            ->name('batches.export')->middleware('permission:view_qr_inventory');
        Route::get('/batches/{batch}/exports', [QrBatchController::class, 'exports'])
            ->name('batches.exports')->middleware('permission:view_qr_inventory');
        // ⚠️ NOT inventory.export-download. That route validates the filename
        // against readyExports()'s 20-most-recent listing, so parts of a
        // 20-part batch export fall out of the window and 404 — the exact cap
        // this feature exists to escape. This one is keyed on the
        // smart_qr_exports row and scoped to the batch. See the controller.
        Route::get('/batches/{batch}/exports/{export}', [QrBatchController::class, 'downloadExport'])
            ->name('batches.export-download')->middleware('permission:view_qr_inventory');

        // ── Inventory (§5) ─────────────────────────────────────────────────
        Route::get('/inventory', [QrInventoryController::class, 'index'])
            ->name('inventory.index')->middleware('permission:view_qr_inventory');
        Route::post('/inventory/mark-printed', [QrInventoryController::class, 'markPrinted'])
            ->name('inventory.mark-printed')->middleware('permission:manage_qr_batches');
        Route::post('/inventory/change-status', [QrInventoryController::class, 'changeStatus'])
            ->name('inventory.change-status')->middleware('permission:manage_qr_batches');
        // §5 export — queued, capped at 500, SVG by default. See the controller.
        Route::post('/inventory/export', [QrInventoryController::class, 'export'])
            ->name('inventory.export')->middleware('permission:view_qr_inventory');
        // ⚠️ The archive was previously written to storage with NO WAY TO GET IT.
        // The success flash said "it will appear in storage", which is true and
        // useless: storage/app/private is not web-reachable, so every export
        // ever run was unreachable by the admin who asked for it.
        Route::get('/inventory/exports', [QrInventoryController::class, 'exports'])
            ->name('inventory.exports')->middleware('permission:view_qr_inventory');
        Route::get('/inventory/exports/{name}', [QrInventoryController::class, 'downloadExport'])
            ->name('inventory.export-download')->middleware('permission:view_qr_inventory');
        Route::delete('/inventory', [QrInventoryController::class, 'destroy'])
            ->name('inventory.destroy')->middleware('permission:manage_qr_batches');

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
        // Editing per-tenant QR details. `assign_qr_codes`, not a new key:
        // whoever may put a code in a tenant's hands may relabel it.
        Route::patch('/assignments/{assignment}', [QrAssignmentController::class, 'update'])
            ->name('assignments.update')->middleware('permission:assign_qr_codes');

        Route::delete('/assignments/{assignment}', [QrAssignmentController::class, 'destroy'])
            ->name('assignments.destroy')->middleware('permission:assign_qr_codes');
    });
