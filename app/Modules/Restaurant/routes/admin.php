<?php

use App\Modules\Restaurant\Http\Controllers\Admin\PosConnectionController;
use App\Modules\Restaurant\Http\Controllers\Admin\RestaurantOutletController;
use Illuminate\Support\Facades\Route;

/**
 * ⚠️ THIS FILE DECLARES ITS OWN MIDDLEWARE, PREFIX AND NAME — deliberately.
 *
 * `routes/admin.php` (top-level) gets `['web', 'auth:admin', 'demo']`, the
 * `admin` prefix and the `admin.` name prefix from a group in
 * `bootstrap/app.php`. That group wraps THAT FILE ONLY. A module route file
 * loaded by its own service provider (RestaurantServiceProvider) is outside
 * it, so omitting any of the three below would publish these routes
 * unauthenticated — silently, because they would still work when a signed-in
 * admin visited them. Same convention as app/Modules/SmartQr/routes/admin.php,
 * the first module to do this.
 *
 * Every route is gated by its own `permission:` key — there is no separate
 * "must be Super Admin" middleware in this codebase; a Super-Admin-only
 * screen means "gated by a permission key only the SUPER_ADMIN role's seeded
 * set includes" (RoleSeeder syncs every permission to it). Restaurant/client
 * users authenticate on a completely different guard (the default `web`
 * guard, not `admin`) and so cannot reach any route in this file at all,
 * permission aside.
 */
Route::middleware(['web', 'auth:admin', 'demo'])
    ->prefix('admin/restaurant')
    ->name('admin.restaurant.')
    ->group(function () {
        Route::get('/connections', [PosConnectionController::class, 'index'])
            ->name('connections.index')->middleware('permission:view_pos_connections');

        Route::get('/connections/create', [PosConnectionController::class, 'create'])
            ->name('connections.create')->middleware('permission:manage_pos_connections');

        Route::post('/connections', [PosConnectionController::class, 'store'])
            ->name('connections.store')->middleware('permission:manage_pos_connections');

        Route::get('/connections/{connection}', [PosConnectionController::class, 'show'])
            ->name('connections.show')->middleware('permission:view_pos_connections');

        Route::put('/connections/{connection}/allowed-ips', [PosConnectionController::class, 'updateAllowedIps'])
            ->name('connections.allowed-ips')->middleware('permission:manage_pos_connections');

        // Phase 2A Slice 1 — editable any time after creation, same
        // permission as the IP allowlist it sits beside on the detail page.
        Route::put('/connections/{connection}/default-phone-country', [PosConnectionController::class, 'updateDefaultPhoneCountry'])
            ->name('connections.default-phone-country')->middleware('permission:manage_pos_connections');

        // ⚠️ THE ONLY ROUTE THAT EVER RETURNS A PLAINTEXT TOKEN. JSON only —
        // never an Inertia render — because an Inertia POST follows the
        // redirect-then-GET protocol, and the token would already be gone by
        // the time the resulting page renders. See generateToken()'s docblock.
        Route::post('/connections/{connection}/token', [PosConnectionController::class, 'generateToken'])
            ->name('connections.token')->middleware('permission:rotate_pos_webhook_secret');

        Route::post('/connections/{connection}/activate', [PosConnectionController::class, 'activate'])
            ->name('connections.activate')->middleware('permission:activate_pos_connections');

        // "Activate" and "Resume" are the SAME action/route (activate()
        // flips to STATUS_CONNECTED whether coming from pending or paused);
        // pause is the one-way-back-to-not-live toggle, its own action.
        Route::post('/connections/{connection}/pause', [PosConnectionController::class, 'pause'])
            ->name('connections.pause')->middleware('permission:activate_pos_connections');

        Route::post('/connections/{connection}/archive', [PosConnectionController::class, 'archive'])
            ->name('connections.archive')->middleware('permission:manage_pos_connections');

        // Reversible Archive/Restore — same permission as archive() itself,
        // its own inverse. Restores to PAUSED; resuming ingress from there
        // is the separate, existing connections.activate route.
        Route::post('/connections/{connection}/restore', [PosConnectionController::class, 'restore'])
            ->name('connections.restore')->middleware('permission:manage_pos_connections');

        Route::delete('/connections/{connection}', [PosConnectionController::class, 'destroy'])
            ->name('connections.destroy')->middleware('permission:manage_pos_connections');

        // The guarded Super Admin correction flow — its own permission,
        // separate from manage_pos_connections, per the seeder's reasoning.
        Route::post('/connections/{connection}/move', [PosConnectionController::class, 'move'])
            ->name('connections.move')->middleware('permission:move_pos_connections');

        // ── Outlet Management (Task B) ──────────────────────────────────
        Route::get('/outlets', [RestaurantOutletController::class, 'index'])
            ->name('outlets.index')->middleware('permission:view_pos_connections');

        Route::post('/outlets', [RestaurantOutletController::class, 'store'])
            ->name('outlets.store')->middleware('permission:manage_pos_connections');

        Route::put('/outlets/{outlet}', [RestaurantOutletController::class, 'update'])
            ->name('outlets.update')->middleware('permission:manage_pos_connections');

        // Phase 2 messaging settings — intentionally a dedicated write path,
        // not part of the ordinary outlet identity form.
        Route::put('/outlets/{outlet}/messaging-settings', [RestaurantOutletController::class, 'updateMessagingSettings'])
            ->name('outlets.messaging-settings.update')->middleware('permission:manage_pos_connections');

        Route::post('/outlets/{outlet}/archive', [RestaurantOutletController::class, 'archive'])
            ->name('outlets.archive')->middleware('permission:manage_pos_connections');

        Route::post('/outlets/{outlet}/restore', [RestaurantOutletController::class, 'restore'])
            ->name('outlets.restore')->middleware('permission:manage_pos_connections');

        // Petpooja Phase 2A gate-hardening pass — Gate 5 of the six-gate live
        // activation invariant. Its own permission, same reasoning as
        // rotate_pos_webhook_secret/activate_pos_connections above: this is
        // a deliberate, consequential admin verification act, not something
        // everyone who may manage outlets should automatically be able to do.
        Route::post('/outlets/{outlet}/authorize-live-pos', [RestaurantOutletController::class, 'authorizeLivePos'])
            ->name('outlets.authorize-live-pos')->middleware('permission:authorize_pos_outlets');
    });
