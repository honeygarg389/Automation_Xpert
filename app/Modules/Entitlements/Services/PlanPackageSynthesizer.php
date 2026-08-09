<?php

namespace App\Modules\Entitlements\Services;

use App\Models\Plan;
use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Models\AddOnGrant;
use App\Modules\Entitlements\Support\GrantBundle;

/**
 * Turns an existing `plans.limits` JSON blob into the catalog shape the resolver
 * folds, WITHOUT writing anything.
 *
 * This is the backward-compatibility bridge for slice 2: the resolver reads only
 * synthesized packages, so it must return exactly what `EnforceLimit` reads
 * today for every seeded plan and every key. Once real catalog rows exist, this
 * class is how the migration is written — and until then it is how we prove the
 * fold before anything depends on it.
 *
 * ─── Keys are copied FAITHFULLY ─────────────────────────────────────────────
 *
 * `storage` stays `storage`, in megabytes. It is NOT renamed to `storage_gb` to
 * match what `MediaService` asks for. Renaming inside a migration would change
 * every customer's quota in both directions, unannounced — enterprise from the
 * hard-coded 1 GB fallback to 500 GB, and anyone over their real allowance into
 * breach the same day. That is BUG-025's own decision with its own data
 * migration, not something to slip into a synthesis step.
 */
class PlanPackageSynthesizer
{
    /**
     * ⚠️ THE KIND AND UNIT OF EVERY LEGACY LIMIT KEY, DECLARED.
     *
     * `plans.limits` carries no kind and no unit — it is a bare JSON map of key
     * to number. Something has to supply the missing half, and there are only
     * two ways to do it:
     *
     *   INFER it from the key's spelling (`*_per_month` means counter) — which
     *   is precisely the mistake BUG-025 is made of. `storage => 5120` means
     *   megabytes and `storage_gb` means gigabytes, the difference lived in a
     *   suffix, and the consumer guessed wrong for every customer on the system.
     *
     *   DECLARE it once, in a table a human can review — which is this.
     *
     * So this map is not a convenience. It is the reason `add_on_grants.kind`
     * and `.unit` are columns instead of conventions, applied to the sixteen
     * keys that predate them.
     *
     * The nine gauges are BUG-024: cardinality limits ("how many chatbots may
     * exist") that the current design checks against a per-period counter, so
     * they read 0 forever. Recording the kind here does not fix that — slice 4
     * does — but it is what makes the fix possible at all.
     *
     * @var array<string, array{kind: string, unit: string}>
     */
    public const LEGACY_KEYS = [
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
        // ⚠️ MEGABYTES. The seeder writes 5120 / 51200 / 512000. Declared here
        // because nothing else in the codebase says so — which is the bug.
        'storage' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'megabytes'],
        'whatsapp_accounts' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'accounts'],
        'whatsapp_templates' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'templates'],
        'inbox_agents' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'agents'],
        'knowledge_bases' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'knowledge_bases'],
        'chatbots' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'chatbots'],
        'social_accounts' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'accounts'],
        'automations' => ['kind' => AddOnGrant::KIND_GAUGE, 'unit' => 'automations'],
    ];

    /**
     * The plan's limits as a single dominant package.
     *
     * Rank 0: there is exactly one synthesized package per plan and a customer
     * holds exactly one plan, so nothing is ever compared against it yet. When
     * real packages arrive they carry real ranks and this one keeps losing,
     * which is correct — a legacy plan should not outrank a product someone
     * deliberately ranked.
     */
    public function forPlan(?Plan $plan): ?GrantBundle
    {
        if (! $plan) {
            return null;
        }

        return new GrantBundle(
            type: AddOn::TYPE_PACKAGE,
            rank: 0,
            quantity: 1,
            grants: $this->normaliseLimits($plan),
        );
    }

    /**
     * ⚠️ Legacy values: numeric strings accepted, anything else REFUSED.
     *
     * A JSON column round-trips whatever was written to it. Measured, the
     * current data is clean — all 16 keys across all 3 seeded plans are `int` or
     * `null`, and both live write paths produce integers (`PlanSeeder` literals,
     * and the admin UI which validates `integer`). No `'unlimited'` sentinel
     * exists anywhere in `app/` or `database/`.
     *
     * So the choice is about what to do if that ever stops being true:
     *
     *   COERCE SILENTLY — a non-numeric value becomes null, which every consumer
     *   reads as unlimited. That is the exact failure class of BUG-023: a limit
     *   silently becoming "no limit". Rejected.
     *
     *   SKIP THE KEY — same outcome by a different route, since an absent key is
     *   also read as unlimited. Rejected for the same reason.
     *
     *   REFUSE, loudly, naming the plan and the key. Chosen.
     *
     * A numeric string ("5") IS accepted and cast, because that is a legitimate
     * JSON round-trip artifact and its meaning is unambiguous. A boolean, an
     * array, or a word is not ambiguous either — it is *wrong*, and the fix is
     * to correct the row, not to let the resolver invent a reading for it.
     *
     * This is a tripwire, not a live risk: no current write path can produce
     * one. If it ever fires, a plan's limits have been corrupted and every
     * customer on that plan is about to be entitled to something nobody chose.
     *
     * @return array<string, int|null>
     *
     * @throws \UnexpectedValueException
     */
    private function normaliseLimits(Plan $plan): array
    {
        $limits = $plan->limits ?? [];
        $out = [];

        foreach ($limits as $key => $value) {
            if ($value === null) {
                $out[$key] = null;   // unlimited, faithfully preserved

                continue;
            }

            if (is_int($value)) {
                $out[$key] = $value;

                continue;
            }

            if (is_string($value) && $value !== '' && ctype_digit($value)) {
                $out[$key] = (int) $value;

                continue;
            }

            throw new \UnexpectedValueException(sprintf(
                'Plan %d (%s) has a non-numeric limit for "%s": %s. Refusing to guess — a '
                .'coerced or skipped limit reads as UNLIMITED to every consumer, which is how '
                .'BUG-023 happened. Correct the plan row.',
                $plan->id,
                $plan->slug ?? 'no-slug',
                $key,
                get_debug_type($value),
            ));
        }

        return $out;
    }
}
