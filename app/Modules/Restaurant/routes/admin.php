<?php

/*
 * Restaurant — admin surface. Placeholder.
 *
 * A module route file loaded via loadRoutesFrom() is NOT wrapped by
 * bootstrap/app.php's ['web', 'auth:admin', 'demo'] + 'admin' prefix group —
 * see SmartQrServiceProvider's admin.php for the precedent. Any route added
 * here must declare its own middleware/prefix/name explicitly:
 *
 *   Route::middleware(['web', 'auth:admin', 'demo'])
 *       ->prefix('admin/restaurant')
 *       ->name('admin.restaurant.')
 *       ->group(function () {
 *           // ...
 *       });
 */
