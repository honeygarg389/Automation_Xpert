<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown when an UPDATE attempts to change any field of an already-created
 * legal_acceptances row. An acceptance is a record of an event that already
 * happened — appended once, at creation, and never revised afterward. See
 * LegalAcceptance::booted()'s updating() listener, the only place this is
 * thrown.
 */
class ImmutableLegalAcceptanceException extends RuntimeException {}
