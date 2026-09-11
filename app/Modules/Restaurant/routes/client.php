<?php

/*
 * Restaurant — client (tenant) surface. Placeholder.
 *
 * Follow SmartQrServiceProvider's client.php precedent: this file is loaded
 * outside the app's own tenant route groups, so declare 'web' + 'client-app'
 * (+ any entitlement-gating middleware) and a distinct prefix/name here:
 *
 *   Route::middleware(['web', 'client-app'])
 *       ->prefix('app/restaurant')
 *       ->name('client.restaurant.')
 *       ->group(function () {
 *           // ...
 *       });
 */
