<?php

namespace App\Modules\SmartQr\Services;

use App\Modules\SmartQr\Models\SmartQrAttributionSession;
use Illuminate\Support\Carbon;

/**
 * Issues and resolves §9's reference token.
 *
 * ─── ⚠️ NO FALLBACK ATTRIBUTION. EVER. A REFUSED DESIGN. ────────────────────
 *
 * §9: when the customer removes the reference, "exact attribution is
 * unavailable" and **must not be faked**.
 *
 * So there is deliberately no method here that guesses. Not by phone number, not
 * by "the only scan in the last ten minutes", not by "this workspace's single
 * recent scan". Each is superficially reasonable and quietly wrong: a customer
 * who scans a QR, ignores it, and messages an hour later from a business card
 * would be credited to the QR — and the tenant would read a conversion figure
 * describing something that did not happen.
 *
 * `resolve()` returns null when there is no token. That is the whole of the
 * behaviour, and `a_message_with_the_token_stripped_attributes_nothing` is the
 * test guarding the honesty of every number §10 reports.
 */
class SmartQrAttribution
{
    /**
     * ⚠️ THE PRINTED-REFERENCE ALPHABET. Crockford-style: no vowels (so no word
     * can form by accident), no 0/O and no 1/I/L (so a customer retyping what
     * they see cannot produce a different valid token).
     */
    private const ALPHABET = '23456789BCDFGHJKMNPQRSTVWXYZ';

    private const LENGTH = 8;

    /**
     * The visible prefix. Short, and unambiguous in a chat window.
     *
     * ⚠️ It is part of the contract with the extractor: the listener matches on
     * this prefix rather than scanning every 8-character word in every inbound
     * message, which would produce false positives on ordinary text.
     */
    public const PREFIX = 'Ref:';

    public function ttlMinutes(): int
    {
        return (int) config('smartqr.attribution_ttl_minutes', 30);
    }

    /**
     * A new token. ~38 bits over the 28-character alphabet.
     *
     * ⚠️ Deliberately far shorter than `public_token`'s 128 bits, and the
     * difference is a different threat model rather than a weaker one. That one
     * addresses a code from an unauthenticated URL and must resist offline
     * enumeration. This one is single-use, expires in 30 minutes, and is only
     * reachable by sending an actual WhatsApp message through a deduplicated
     * webhook — while a human has to read it inside their own message, so every
     * extra character is a usability cost paid by the customer.
     */
    public function newToken(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /** Issue a session for an assignment, returning the persisted row. */
    public function issue(int $assignmentId, ?Carbon $now = null): SmartQrAttributionSession
    {
        $now ??= now();

        return SmartQrAttributionSession::create([
            'token' => $this->newToken(),
            'smart_qr_assignment_id' => $assignmentId,
            'issued_at' => $now,
            'expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
        ]);
    }

    /** How the reference appears in the customer's prefilled message. */
    public function reference(string $token): string
    {
        return self::PREFIX.' '.$token;
    }

    /**
     * Append the reference to a default message.
     *
     * On its own line, at the end, so it reads as metadata rather than part of
     * what the customer is saying — and so deleting it is a single obvious
     * action rather than surgery on a sentence.
     */
    public function appendReference(string $message, string $token): string
    {
        $reference = $this->reference($token);

        return trim($message) === '' ? $reference : trim($message)."\n\n".$reference;
    }

    /**
     * Pull a token out of an inbound message body, or null.
     *
     * ⚠️ Anchored on the PREFIX, not on "any 8-character word". An unanchored
     * match would hit ordinary text — product codes, postcodes, someone's
     * booking reference — and attribute a message to whichever session happened
     * to collide. Returning null is always safe; a false positive is not.
     *
     * Case-insensitive on the prefix because phone keyboards capitalise, and the
     * token is upper-cased because the alphabet is.
     */
    public function extractToken(?string $body): ?string
    {
        if ($body === null || trim($body) === '') {
            return null;
        }

        $pattern = '/'.preg_quote(self::PREFIX, '/').'\s*(['.self::ALPHABET.']{'.self::LENGTH.'})/i';

        if (preg_match($pattern, $body, $m) !== 1) {
            return null;
        }

        return strtoupper($m[1]);
    }

    /**
     * The live, unconsumed session for a token — or null.
     *
     * ⚠️ Null for expired and null for already-consumed, both deliberately. An
     * expired token is not an attribution, and a re-sent token is a REPEAT
     * message, not a second customer. Neither is an error worth surfacing to
     * the customer, who is simply having a conversation.
     */
    public function resolve(?string $token): ?SmartQrAttributionSession
    {
        if ($token === null) {
            return null;
        }

        return SmartQrAttributionSession::query()
            ->where('token', $token)
            ->whereNull('consumed_at')
            ->where('expires_at', '>=', now())
            ->first();
    }

    /**
     * Claim a session for a message. Returns false if somebody else won.
     *
     * ⚠️ A CONDITIONAL UPDATE, not a read-then-write. Two messages carrying the
     * same token can arrive concurrently; `where consumed_at IS NULL` makes the
     * database decide, and exactly one update reports a row affected.
     *
     * The unique index on (attribution_session_id, type) is the actual
     * guarantee — this is the fast path that avoids relying on a constraint
     * violation for ordinary flow control.
     */
    public function claim(SmartQrAttributionSession $session, int $contactId, int $conversationId, int $messageId): bool
    {
        $affected = SmartQrAttributionSession::query()
            ->whereKey($session->id)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'contact_id' => $contactId,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
            ]);

        return $affected === 1;
    }
}
