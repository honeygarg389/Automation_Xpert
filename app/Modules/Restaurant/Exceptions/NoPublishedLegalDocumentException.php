<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown by LegalAcceptance::recordFor() when $documentType has no currently
 * published version to accept — either nothing has ever been published, or
 * (defensively) the row returned by currentPublished() does not actually
 * carry status='published'/published_slot=1.
 */
class NoPublishedLegalDocumentException extends RuntimeException {}
