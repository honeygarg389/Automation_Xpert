<?php

use App\Modules\SmartQr\Http\Controllers\Public\PublicQrController;
use Illuminate\Support\Facades\Route;

/**
 * The public scan endpoint. §8.
 *
 * ⚠️ `web` ONLY — no auth, no workspace middleware, nothing that assumes a
 * session belongs to a tenant. This is the third public entry point in the
 * codebase and the first one a member of the public reaches by pointing a phone
 * at a sticker.
 *
 * ⚠️ RATE LIMITED PER TOKEN, NOT PER IP.
 *
 * A shopping centre, café or event venue puts every customer behind one address,
 * and repeat scans are the product working as intended — so per-IP throttling
 * would break the busiest legitimate installations first, which is exactly
 * backwards. Keying on the token bounds abuse of any single code while leaving a
 * venue's genuine traffic untouched.
 *
 * 60/minute is far above human use of one sticker and far below what makes
 * hammering one code useful.
 *
 * ⚠️ The parameter accepts hex only — `public_token` is 32 hex characters from
 * `bin2hex(random_bytes(16))`. The constraint keeps malformed probes from
 * reaching the database at all, and means a printed SERIAL (AX-000001) cannot
 * even match the route. That is the control the whole §8 response design rests
 * on: see QrRedirectOutcome's docblock.
 */
Route::middleware(['web', 'throttle:60,1'])->group(function () {
    Route::get('/q/{token}', PublicQrController::class)
        ->where('token', '[A-Za-z0-9]{16,64}')
        ->name('smartqr.scan');
});
