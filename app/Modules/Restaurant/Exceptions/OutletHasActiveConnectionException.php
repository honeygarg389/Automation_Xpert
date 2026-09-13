<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown when archiving an outlet is attempted while it still has a
 * non-archived Petpooja connection. Archiving the outlet underneath a live
 * connection would leave that connection accepting Petpooja deliveries for
 * an outlet the admin has just told the system to stop treating as active —
 * the connection must be paused or archived first.
 */
class OutletHasActiveConnectionException extends RuntimeException {}
