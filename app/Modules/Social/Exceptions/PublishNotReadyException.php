<?php

namespace App\Modules\Social\Exceptions;

use RuntimeException;

/**
 * The platform has accepted the upload but has not finished processing it.
 *
 * ⚠️ THIS IS THE ONLY EXCEPTION SocialPublisher RE-THROWS. Every other failure
 * is caught per account, recorded on that account's link row, and allowed to
 * fail the other accounts independently — a Twitter outage must not prevent a
 * LinkedIn post going out.
 *
 * This one is different because it is not a failure at all: the work is still in
 * flight on the platform's side and will very likely succeed on a later attempt.
 * Swallowing it would mark the account failed and stop, throwing away an upload
 * that was already accepted. Re-throwing hands it to PublishSocialPostJob's
 * existing retry schedule ([30, 120, 300] seconds, jittered, 3 tries), which is
 * the mechanism already built for exactly this shape of wait.
 *
 * The container id is persisted before this is thrown, so the retry resumes the
 * SAME upload rather than starting a new one.
 */
class PublishNotReadyException extends RuntimeException {}
