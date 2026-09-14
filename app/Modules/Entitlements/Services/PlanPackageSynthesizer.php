<?php

namespace App\Modules\Entitlements\Services;

use App\Models\Plan;
use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Support\GrantBundle;
use App\Modules\Entitlements\Support\PlanLimitKinds;

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
     * @deprecated Use {@see PlanLimitKinds::MAP}. Kept as an alias only so an
     *             existing reference does not silently resolve to a second,
     *             divergent copy — which is the trap the extraction closed.
     *
     * @var array<string, array{kind: string, unit: string}>
     */
    public const LEGACY_KEYS = PlanLimitKinds::MAP;

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
            flags: $this->legacyFlags($plan),
        );
    }

    /**
     * ⚠️ THREE legacy bridges: `plans.white_label_enabled`,
     * `plans.whatsapp_flows_enabled`, and, derived from `plans.limits`,
     * `smart_qr_enabled`.
     *
     * The column is the legacy source, exactly as `plans.limits` is: synthesized
     * into the package so the resolver is the single authority and the partner
     * ceiling can intersect it. A boolean column on `plans` has no partner
     * dimension, so rule 6 was unenforceable for it — a partner who may not
     * white-label could not cap a customer whose plan flag was true.
     *
     * The column is NOT removed. It is the seed, and it stays until a test
     * proves nothing reads it — the same discipline plans.limits is under. It is
     * annotated as DERIVED at the model and in the admin form so it cannot go on
     * looking authoritative, which is the trap this codebase keeps producing.
     *
     * @return array<string, bool>
     */
    private function legacyFlags(Plan $plan): array
    {
        $flags = $plan->white_label_enabled ? ['white_label' => true] : [];

        // Flows has no cardinality limit to derive from, unlike Smart QR. Its
        // dedicated plan column is the legacy seed for this boolean grant; the
        // resolver remains the only authority feature code asks.
        if ($plan->whatsapp_flows_enabled) {
            $flags['whatsapp_flows_enabled'] = true;
        }

        // ⚠️ `smart_qr_enabled` DERIVED from the presence of the assignment
        // limit. A bridge, exactly like `white_label_enabled` above, and it
        // stays until add-ons can grant the flag directly.
        //
        // ─── Why derived rather than gated on a real flag ──────────────────
        //
        // R-5 puts `smart_qr_enabled` at display. But NOTHING can set it:
        // legacyFlags() was the only source of boolean flags and returned just
        // white_label, and the other source — add-on grants — is Phase 1 slice
        // 6, which is BLOCKED behind BUG-032.
        //
        // ⚠️ So gating on the flag as it stood would have hidden Smart QR from
        // EVERY customer on EVERY plan. That is R-13's failure inverted: not a
        // gate that can never fire, but one that can never OPEN. Same class of
        // bug, opposite direction — and both are now written down together, in
        // R-13 and R-22.
        //
        // Deriving from `smart_qr_max_assigned` is correct rather than merely
        // convenient: R-13 seeded that limit on all three tiers, so every
        // current customer has the feature, and a future plan that omits the
        // limit correctly omits the feature. `array_key_exists`, not truthiness
        // — a limit of 0 means "bounded at zero", which is still a granted
        // feature the customer simply cannot use yet (BUG-030 semantics).
        //
        // ⚠️ DERIVED, NOT AUTHORITATIVE. Like white_label_enabled, this is a
        // seed, and it is annotated so it cannot go on looking like the source
        // of truth once add-ons can grant the flag properly.
        $limits = is_array($plan->limits) ? $plan->limits : [];

        if (array_key_exists('smart_qr_max_assigned', $limits)) {
            $flags['smart_qr_enabled'] = true;
        }

        return $flags;
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
