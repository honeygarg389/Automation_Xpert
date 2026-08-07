<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * BUG-019. Two or more workspaces claim the same inbound routing identifier.
 *
 * Thrown per MESSAGE, and caught by the driver's existing per-message
 * try/catch — one poisoned identifier must not stop the other messages in the
 * same webhook payload. That per-message isolation is a decision already made
 * correctly in the drivers and is not changed here.
 *
 * Visibility does NOT depend on this exception reaching anybody:
 * `ChannelAccountRouting` writes an `audit_logs` row before throwing, so the
 * ambiguity is visible at /admin/audit-log without reading application logs.
 *
 * Before this existed, the router called `->first()` on an unordered query, so
 * it silently picked one row — in practice the oldest — and every message for
 * that identifier kept landing in the previous tenant's inbox indefinitely.
 */
class AmbiguousChannelRoutingException extends RuntimeException
{
    /**
     * @param  list<int>  $workspaceIds
     */
    public function __construct(
        string $message,
        public readonly string $channel = '',
        public readonly array $workspaceIds = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $routingKeys
     * @param  list<int>  $workspaceIds
     */
    public static function forIdentifier(string $channel, array $routingKeys, array $workspaceIds): self
    {
        $identifier = implode(', ', array_map(
            fn ($k, $v) => "{$k}={$v}",
            array_keys($routingKeys),
            $routingKeys,
        ));

        return new self(
            "Ambiguous {$channel} routing: {$identifier} is claimed by workspaces "
            .implode(', ', $workspaceIds).'. Refusing to guess which tenant this message belongs to — '
            .'delivering it to the wrong one would put a customer\'s conversation in another company\'s inbox. '
            .'Run `php artisan channels:audit-routing`.',
            $channel,
            $workspaceIds,
        );
    }
}
