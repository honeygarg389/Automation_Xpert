<?php

namespace App\Modules\Entitlements\Support;

use App\Modules\Broadcasting\Models\UsageMeter;

/**
 * "Is this workspace at its limit for `$limitKey`?" — asked in ONE place.
 *
 * `EnforceLimit` answers this for HTTP paths. `SendCampaignMessageJob` needs the
 * same answer and is not an HTTP path. Letting each compute it would be a fifth
 * instance of the trap this codebase keeps producing — and the specific bug this
 * class exists to prevent is the one it was extracted from: the campaign path
 * and the inbox path disagreeing about which meter governs a WhatsApp message.
 */
class QuotaGuard
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /** null = unlimited, or never granted. */
    public function limit(int $workspaceId, string $limitKey): ?int
    {
        return $this->entitlements->limitForWorkspace($workspaceId, $limitKey);
    }

    public function usage(int $workspaceId, string $metric): int
    {
        return UsageMeter::current($workspaceId, $metric);
    }

    /**
     * Whether a further unit would exceed the limit.
     *
     * `>=` deliberately, matching `EnforceLimit`: at exactly the limit, the next
     * one is refused. Changing this to `>` would silently grant every customer
     * one extra of everything.
     */
    public function isExceeded(int $workspaceId, string $limitKey, string $metric): bool
    {
        $limit = $this->limit($workspaceId, $limitKey);

        if ($limit === null) {
            return false;   // unlimited, or ungranted — today those are the same
        }

        return $this->usage($workspaceId, $metric) >= $limit;
    }

    /** The message-channel form, so callers never assemble the pair themselves. */
    public function isChannelExceeded(int $workspaceId, string $channel): bool
    {
        $limitKey = MessageMetrics::limitKeyForChannel($channel);

        if ($limitKey === null) {
            return false;   // no limit governs this channel
        }

        return $this->isExceeded($workspaceId, $limitKey, MessageMetrics::forChannel($channel));
    }
}
