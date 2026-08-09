<?php

namespace App\Modules\Entitlements\Support;

use App\Modules\Entitlements\Models\AddOnGrant;

/**
 * ⚠️ THE KIND AND UNIT OF EVERY LEGACY `plans.limits` KEY — ONE DEFINITION.
 *
 * Extracted from `PlanPackageSynthesizer` deliberately. Slice 4 must enforce
 * gauges by `COUNT(*)` and counters by `usage_meters`, and it needs the same
 * mapping — so if the mapping stayed a private detail of the synthesizer, slice
 * 4 would write a second one.
 *
 * This codebase has produced that failure four times already, and every instance
 * looked harmless when it was written:
 *
 *   User::accessibleWorkspaces() vs Workspace::isAccessibleBy()   membership
 *   whatsapp_global vs whatsapp_msg                               dedup
 *   PlanLimits.jsx LIMIT_KEYS (16) vs defaultLimits() (2)         BUG-027
 *   Client::activePlan() vs User::effectiveSubscription()         BUG-023
 *
 * The rule this class exists to enforce: `plans.limits` carries no kind and no
 * unit, so something must supply them, and there are exactly two ways —
 * INFER from the key's spelling, or DECLARE once. Inference from a suffix is
 * what BUG-025 is made of: `storage => 5120` means megabytes, `storage_gb`
 * means gigabytes, the difference lived in a suffix, and `MediaService` guessed
 * wrong for every customer on the system.
 */
final class PlanLimitKinds
{
    /**
     * @var array<string, array{kind: string, unit: string}>
     */
    public const MAP = [
        // ── counters (7) — per-period, measured by usage_meters ──
        'whatsapp_messages_per_month' => ['kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'messages'],
        'campaigns_per_month' => ['kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'campaigns'],
        'sms_per_month' => ['kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'messages'],
        'emails_per_month' => ['kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'emails'],
        'ai_tokens_per_month' => ['kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'tokens'],
        'social_posts_per_month' => ['kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'posts'],
        'lead_credits_per_month' => ['kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'credits'],

        // ── gauges (9) — cardinality, measured by COUNT(*). See BUG-024. ──
        'users' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'seats'],
        // ⚠️ MEGABYTES. The seeder writes 5120 / 51200 / 512000, and nothing
        // else in the codebase records that — which is the whole of BUG-025.
        'storage' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'megabytes'],
        'whatsapp_accounts' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'accounts'],
        'whatsapp_templates' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'templates'],
        'inbox_agents' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'agents'],
        'knowledge_bases' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'knowledge_bases'],
        'chatbots' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'chatbots'],
        'social_accounts' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'accounts'],
        'automations' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'automations'],
    ];

    public static function kindOf(string $key): ?string
    {
        return self::MAP[$key]['kind'] ?? null;
    }

    public static function unitOf(string $key): ?string
    {
        return self::MAP[$key]['unit'] ?? null;
    }

    /** @return list<string> */
    public static function keysOfKind(string $kind): array
    {
        return array_keys(array_filter(self::MAP, fn (array $m) => $m['kind'] === $kind));
    }
}
