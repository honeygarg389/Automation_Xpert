<?php

/*
 * Restaurant — unauthenticated surface. Placeholder.
 *
 * Reserved for Phase 1B's POS webhook ingress endpoint. That endpoint will
 * resolve its own tenant via PosConnection::findActiveByProviderAndRef() —
 * see the CRITICAL trust-boundary docblock on PosWebhookEvent before
 * implementing it. No routes are declared yet.
 *
 *   Route::middleware(['web', 'throttle:60,1'])->group(function () {
 *       // ...
 *   });
 */
