<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown by the guarded workspace-move flow for any reason other than
 * existing webhook history (which gets the more specific
 * ConnectionHasHistoryException) — e.g. attempting to move a production
 * connection, or a target outlet that is not actually eligible.
 */
class ConnectionNotMovableException extends RuntimeException {}
