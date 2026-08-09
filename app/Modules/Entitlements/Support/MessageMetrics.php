<?php

namespace App\Modules\Entitlements\Support;

/**
 * ⚠️ ONE METRIC PER MESSAGING CHANNEL, DECLARED ONCE.
 *
 * ─── What was wrong ─────────────────────────────────────────────────────────
 *
 * `SendCampaignMessageJob` wrote TWO meters for every WhatsApp campaign message:
 * `messages_whatsapp` and `whatsapp_messages`. The second was a WhatsApp-only
 * duplicate of the first, not the per-channel visibility it looked like — and
 * `messages_whatsapp` was read by nothing at all.
 *
 * The damage was not the duplication. It was that the two halves of enforcement
 * sat on OPPOSITE paths:
 *
 *   campaign send    incremented `whatsapp_messages`, and was never checked
 *                    against it (launch is gated by campaigns_per_month)
 *   inbox reply      was checked against `whatsapp_messages`, and never
 *                    incremented it — InboxController had no UsageMeter call
 *
 * So a workspace that only used the inbox never accumulated and could never be
 * refused, however many replies it sent. A workspace that ran campaigns
 * accumulated, and was then refused ON INBOX REPLIES for volume it had spent on
 * campaigns. The limit fired on the wrong customer, in the wrong place.
 *
 * Unifying the metric alone would NOT have fixed this — it would have made
 * campaigns count and left replies invisible, a half-fix that looks whole.
 * Both paths must track, and both must enforce.
 *
 * ─── Why the channel-parameterised name won ────────────────────────────────
 *
 * `messages_{channel}` generalises; `whatsapp_messages` does not. `sms_per_month`
 * and `emails_per_month` are seeded, sold, and enforced nowhere — when they are
 * turned on they need `messages_sms` and `messages_email`, which already exist.
 * Keeping the WhatsApp-shaped name would have meant inventing a second
 * convention for the other two.
 */
final class MessageMetrics
{
    /**
     * limit key => usage_meters metric.
     *
     * ONE declaration. Routes, the campaign job and the inbox controller all
     * read it, so the middleware's `countKey` argument and the job's `track()`
     * call cannot drift — which is exactly how the split happened.
     *
     * @var array<string, string>
     */
    public const LIMIT_TO_METRIC = [
        'whatsapp_messages_per_month' => 'messages_whatsapp',
        'sms_per_month' => 'messages_sms',
        'emails_per_month' => 'messages_email',
    ];

    /**
     * The metric a send on `$channel` increments.
     *
     * Deliberately derived rather than a second literal map: the channel IS the
     * suffix, and two maps that must agree is the disease being cured here.
     */
    public static function forChannel(string $channel): string
    {
        return 'messages_'.$channel;
    }

    /** The limit key that governs `$channel`, or null if none does. */
    public static function limitKeyForChannel(string $channel): ?string
    {
        $metric = self::forChannel($channel);

        return array_search($metric, self::LIMIT_TO_METRIC, true) ?: null;
    }

    /**
     * The metric retired by this change.
     *
     * Kept as a named constant, not a bare string, so the data migration and the
     * regression test refer to the same thing and a later reader can find every
     * mention of it.
     */
    public const RETIRED_METRIC = 'whatsapp_messages';
}
