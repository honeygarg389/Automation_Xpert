<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * BUG-019. A channel routing identifier already belongs to another workspace.
 *
 * Thrown at CONNECT time, never at message time. Refusing here is what keeps the
 * inbound router unambiguous: if this never fires, `findForInbound()` can never
 * find two rows.
 */
class ChannelAlreadyConnectedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $channel = '',
        public readonly int $ownedByWorkspaceId = 0,
        public readonly int $attemptedByWorkspaceId = 0,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $routingKeys
     */
    public static function inAnotherWorkspace(
        string $channel,
        array $routingKeys,
        int $ownedBy,
        int $attemptedBy,
    ): self {
        $identifier = implode(', ', array_map(
            fn ($k, $v) => "{$k}={$v}",
            array_keys($routingKeys),
            $routingKeys,
        ));

        return new self(
            "This {$channel} connection ({$identifier}) is already connected to another workspace. "
            .'Disconnect it there first. Moving a channel between workspaces is not supported yet — '
            .'reassigning it automatically could route one company\'s conversations into another\'s inbox.',
            $channel,
            $ownedBy,
            $attemptedBy,
        );
    }
}
