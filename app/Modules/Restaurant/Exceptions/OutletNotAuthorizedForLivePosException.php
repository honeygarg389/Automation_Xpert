<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown by PosConnectionProvisioningService::activateLive() when the
 * connection's outlet has not been authorized via
 * RestaurantOutletService::authorizeForLivePos() — Gate 5 of the six-gate
 * live activation invariant ("outlet-specific authorization").
 *
 * Unlike Gates 1-4, this concept had no existing persisted representation
 * anywhere in the codebase before this gate-hardening pass; see the
 * migration that added RestaurantOutlet::pos_live_authorized_at for the
 * full reasoning on why a new, auditable admin action was built rather than
 * inventing a fake boolean or silently skipping this gate.
 */
class OutletNotAuthorizedForLivePosException extends RuntimeException {}
