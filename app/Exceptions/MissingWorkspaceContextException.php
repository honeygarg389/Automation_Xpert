<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code that must be workspace-scoped cannot establish which
 * workspace it is operating on.
 *
 * Specified in docs/phase-0-tenant-isolation-plan.md §B.3 and deliberately
 * deferred there "to whichever commit first throws it". This is that commit —
 * see BUG-008, where a queued GDPR export silently produced the wrong
 * workspace's data because it fell back to the user's home workspace.
 *
 * The rule this encodes: a workspace is captured where it is known and carried
 * to where it is used. Code that cannot establish one says so rather than
 * guessing. Returning unscoped results leaks; falling back to "home" silently
 * produces wrong data, which in an export handed to a regulator is worse than
 * a failure. A failed job is loud, retryable and visible.
 */
class MissingWorkspaceContextException extends RuntimeException
{
    public static function forJob(string $job, int $userId): self
    {
        return new self(
            "{$job} could not establish a workspace for user {$userId}. "
            .'The workspace must be captured at dispatch and carried on the job — '
            .'it cannot be recovered from the user, because a user has a home '
            .'workspace but not a current one. Refusing to guess.'
        );
    }
}
