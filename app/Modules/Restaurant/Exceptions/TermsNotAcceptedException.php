<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown by PosConnectionProvisioningService::activateLive() when the
 * connection's workspace has no CURRENT acceptance (LegalAcceptance::currentFor())
 * of LegalDocumentVersion::TYPE_TERMS.
 *
 * Same real, already-persisted compliance mechanism as
 * RestaurantDeclarationNotAcceptedException — TYPE_TERMS was defined on
 * LegalDocumentVersion since Phase 1A but, like TYPE_DPA, had zero callers
 * anywhere in application logic before this gate-hardening pass.
 */
class TermsNotAcceptedException extends RuntimeException {}
