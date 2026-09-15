<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown by PosConnectionProvisioningService::activateLive() when the
 * connection's workspace has no CURRENT acceptance (LegalAcceptance::currentFor())
 * of LegalDocumentVersion::TYPE_DPA.
 *
 * Same real, already-persisted compliance mechanism as
 * RestaurantDeclarationNotAcceptedException — TYPE_DPA was defined on
 * LegalDocumentVersion since Phase 1A but, like TYPE_TERMS, had zero callers
 * anywhere in application logic before this gate-hardening pass.
 */
class DpaNotAcceptedException extends RuntimeException {}
