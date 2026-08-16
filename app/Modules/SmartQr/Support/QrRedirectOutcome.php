<?php

namespace App\Modules\SmartQr\Support;

/**
 * ⚠️ THE SEVEN STATES OF A PUBLIC SCAN — §8, plus the one it does not list.
 *
 * §8 enumerates six response behaviours. There is a seventh: a code that IS
 * assigned to an active, in-date assignment whose channel has no dialable
 * number. `ChannelAccount` stores `phone_number_id`, a Meta API identifier, not
 * a phone number — the E.164 number lives on `whatsapp_phone_numbers`. So
 * resolving a redirect target can legitimately fail on correctly-assigned data.
 *
 * ─── ⚠️ WHAT THE STRANGER SEES vs WHAT WE RECORD ────────────────────────────
 *
 * §8: "Do not reveal customer or internal configuration details." So
 * UNCONFIGURED renders the SAME page as INACTIVE — a visitor cannot tell a
 * deliberately-paused QR from a tenant whose WABA is misconfigured, and neither
 * page names the tenant, the channel or the reason.
 *
 * The operator needs the opposite. `PublicQrController` logs UNCONFIGURED
 * distinguishably, with the assignment id, precisely because the page cannot say
 * it. **The log says what the page does not.**
 *
 * ─── ⚠️ AND WHY DISTINCT PAGES ARE SAFE AT ALL ──────────────────────────────
 *
 * Distinguishable responses are an enumeration oracle only if an attacker can
 * obtain hits. `public_token` is 128 bits from `random_bytes`, so they cannot —
 * there is nothing to map without a valid token, and holding one already means
 * holding a code.
 *
 * The control that makes this true is that the route accepts ONLY the token.
 * `serial_number` is printed on the sticker, sequential, and trivially
 * enumerable; if the route ever resolved one, every distinction below would
 * become an inventory oracle. `a_serial_number_is_not_accepted_as_a_token`
 * guards that, and it is the test protecting this whole design.
 */
enum QrRedirectOutcome: string
{
    /** Everything resolved. Redirect to wa.me. */
    case REDIRECT = 'redirect';

    /** No such token. Indistinguishable from any other 404 on the site. */
    case INVALID = 'invalid';

    /** The code exists but no tenant holds it — a Business Kit not yet set up. */
    case UNASSIGNED = 'unassigned';

    /** Held, but the tenant switched it off. */
    case INACTIVE = 'inactive';

    /** Held, but outside its starts_at/expires_at window. */
    case EXPIRED = 'expired';

    /** The physical code is withdrawn (R-10 physical vocabulary). */
    case RETIRED = 'retired';

    /**
     * ⚠️ The seventh. Assigned and in-date, but no dialable number.
     * Renders as INACTIVE to the public; logged distinguishably for the operator.
     */
    case UNCONFIGURED = 'unconfigured';

    /** Does this outcome mean the code exists? Drives the 200-vs-404 split. */
    public function codeExists(): bool
    {
        return $this !== self::INVALID;
    }

    /**
     * ⚠️ ONE status for every "exists" state.
     *
     * Differentiating by HTTP status — 410 for retired, say — is the part a
     * script could read. The copy differs because it serves a person holding a
     * sticker; the status does not, because it would serve nobody but a prober.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::REDIRECT => 302,
            self::INVALID => 404,
            default => 200,
        };
    }
}
