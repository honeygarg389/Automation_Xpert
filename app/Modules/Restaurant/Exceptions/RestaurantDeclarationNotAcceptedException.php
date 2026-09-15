<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown by PosConnectionProvisioningService::activateLive() when the
 * connection's workspace has no CURRENT acceptance (LegalAcceptance::currentFor())
 * of LegalDocumentVersion::TYPE_RESTAURANT_DECLARATION.
 *
 * This is the real, already-persisted compliance mechanism from Phase 1A
 * (legal_document_versions / legal_acceptances) — not a new gate invented for
 * this slice. It existed with no caller wiring it into any activation path;
 * this exception is what makes that omission fail loudly instead of silently
 * letting a live connection activate with zero compliance check. There is
 * still no client-facing flow that lets a workspace actually accept the
 * declaration (routes/client.php is an empty placeholder) — see this slice's
 * final report for that gap. Building one is out of scope here.
 */
class RestaurantDeclarationNotAcceptedException extends RuntimeException {}
