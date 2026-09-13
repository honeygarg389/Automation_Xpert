<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown when an action that requires zero webhook history (deleting a test
 * connection, or the self-service workspace-move flow) is attempted against
 * a connection that has accepted or rejected at least one real delivery. A
 * connection with real history is no longer "test data" that can be
 * silently discarded or relocated — see PosConnection::hasWebhookHistory().
 */
class ConnectionHasHistoryException extends RuntimeException {}
