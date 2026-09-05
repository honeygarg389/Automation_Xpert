<?php

use App\Modules\SmartQr\Http\Controllers\Admin\QrAssignmentController;
use App\Modules\SmartQr\Http\Controllers\Admin\QrBatchController;
use App\Modules\SmartQr\Http\Controllers\Admin\QrDashboardController;
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

        // ── Dashboard ────────────────────────────────────────────────────
        //
        // ⚠️ NAMED `dashboard`, NOT `dashboard.index`, deliberately matching
        // the sidebar placeholder that already referenced `admin.qr.dashboard`
        // by name before this route existed (AdminLayout.jsx) — the group's
        // own `->name('admin.qr.')` composes with this to the exact string the
        // placeholder was written against.
        Route::get('/dashboard', [QrDashboardController::class, 'index'])
            ->name('dashboard')->middleware('permission:view_qr_inventory');

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

        // ═══ ⚠️ FORCE DELETE — REGISTERED ONLY ON LOCAL ═══════════════════
        //
        // A developer convenience for clearing test batches that destroy()
        // rightly refuses: it destroys assignment history, scan events and
        // exported archives with no undo, which is precisely what destroy()
        // exists to prevent on real inventory.
        //
        // ⚠️ THE ENVIRONMENT CHECK IS HERE **AND** AGAIN IN THE CONTROLLER, and
        // the duplication is the point. This block means the route does not
        // exist off local — nothing to probe, nothing to reach. The controller's
        // own abort_unless() then covers every way the method could still be
        // called if this file were ever edited, refactored into a shared group,
        // or the method wired to a second route. One guard in one place is a
        // guard that a future edit can silently remove.
        if (app()->environment('local')) {
            Route::delete('/batches/{batch}/force', [QrBatchController::class, 'forceDestroy'])
                ->name('batches.forceDestroy')->middleware('permission:manage_qr_batches');
        }

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
        // ⚠️ NOT inventory.export-download, and the reason has CHANGED — the
        // note here used to say that route validated against readyExports()'s
        // truncated listing, so batch parts fell out of the window and 404'd.
        // That coupling is gone: inventory.export-download now checks the disk,
        // not the display list.
        //
        // This route still earns its place. It is keyed on the smart_qr_exports
        // ROW, so it can refuse a part that is queued, failed or expired, and it
        // scopes the part to its batch. The inventory route knows only "a file
        // with this name exists" — correct for an ad-hoc archive that nothing
        // tracks, insufficient for one that has a lifecycle.
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

        // ── Per-code detail (§5) ───────────────────────────────────────────
        //
        // ⚠️ DECLARED AFTER the literal /inventory/... routes above, and it has
        // to be. {code} binds on serial_number, so /inventory/exports would
        // otherwise match this pattern and look up a code with the serial
        // "exports" — a 404 on a route that exists, which is the confusing kind.
        //
        // ⚠️ view_qr_inventory on all three, matching inventory.export: these
        // READ inventory and render artwork from it. They create nothing, so
        // manage_qr_batches would be the wrong gate. The page's own actions —
        // Change Stage, Assign — post to the existing bulk endpoints and carry
        // their own stricter gates.
        Route::get('/inventory/{code}', [QrInventoryController::class, 'show'])
            ->name('inventory.show')->middleware('permission:view_qr_inventory');
        Route::get('/inventory/{code}/preview.svg', [QrInventoryController::class, 'preview'])
            ->name('inventory.preview')->middleware('permission:view_qr_inventory');
        Route::get('/inventory/{code}/download', [QrInventoryController::class, 'download'])
            ->name('inventory.download')->middleware('permission:view_qr_inventory');

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

        // ⚠️ lock_qr_assignments, NOT assign_qr_codes — and the separation is the
        // point, exactly as override_qr_assignment_limit is separate from
        // assign_qr_codes. Locking takes a control away from a paying customer
        // until an admin gives it back; everyone who may assign a code should not
        // automatically be able to freeze the tenant out of it.
        //
        // ⚠️ These are the ONLY routes that write the lock columns. The customer
        // update path cannot reach them, and cannot mass-assign them either —
        // they are absent from $fillable, which SmartQrAssignmentLockTest proves
        // by posting the field rather than trusting the list.
        Route::post('/assignments/{assignment}/lock', [QrAssignmentController::class, 'lock'])
            ->name('assignments.lock')->middleware('permission:lock_qr_assignments');
        Route::delete('/assignments/{assignment}/lock', [QrAssignmentController::class, 'unlock'])
            ->name('assignments.unlock')->middleware('permission:lock_qr_assignments');
    });
