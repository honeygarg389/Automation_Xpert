<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown by PosConnectionProvisioningService::activateLive() when the
 * connection's workspace has no WhatsappBusinessAccount row with
 * status='active' — the same "connected sender" check already used by
 * WhatsappBusinessAccount::resolveAccessTokenForWorkspace() and
 * defaultPhoneNumberIdForWorkspace(), reused here as Gate 4 of the six-gate
 * live activation invariant. A live Petpooja connection exists to route
 * incoming orders into WhatsApp messages; there is nothing to send them
 * through without a connected sender for this workspace.
 */
class NoConnectedWabaException extends RuntimeException {}
