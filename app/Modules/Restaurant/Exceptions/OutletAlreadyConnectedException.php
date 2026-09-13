<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown when an attempt is made to create a second non-archived Petpooja
 * connection for an outlet that already has one. The DB-level
 * UNIQUE(outlet_id, active_slot) constraint is the real backstop (see the
 * migration that added it); this exception is what turns that constraint's
 * raw duplicate-key error into a message a Super Admin can act on, matching
 * StorePosConnectionRequest's "friendly error, not a database exception"
 * treatment of the (provider, external_ref) uniqueness rule.
 */
class OutletAlreadyConnectedException extends RuntimeException {}
