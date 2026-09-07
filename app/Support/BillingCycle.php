<?php

namespace App\Support;

/**
 * ═══ THE BILLING CYCLE VOCABULARY, AND ITS MAPPING TO FOUR GATEWAYS ═════════
 *
 * ⚠️ TWO TABLES, TWO VOCABULARIES, AND THEY MUST NOT BE MIXED.
 *
 *   subscriptions.billing_cycle         month | quarter | half_year | year
 *   client_subscriptions.billing_cycle  monthly | quarterly | half_yearly | yearly
 *
 * Both are correct for their own table. `subscriptions` is the self-serve /
 * gateway path; `client_subscriptions` is admin assignment. The long forms live
 * on `ClientSubscription` as BILLING_* constants; the short forms live here.
 *
 * A seeder once copied one onto the other and silently disabled the no-op guard
 * in Client\SubscriptionController — see SubscriptionBillingCycleVocabularyTest.
 * `toClientVocabulary()` / `fromClientVocabulary()` exist so nobody has to
 * remember which spelling a given table wants.
 *
 * ─── ⚠️ WHY THE GATEWAY MAPPING IS DATA, NOT TERNARIES ──────────────────────
 *
 * Every gateway previously derived its interval with `$cycle === 'year' ? A : B`.
 * That is not merely inflexible — it FAILS OPEN. A third value falls into the
 * `month` branch, so a quarterly plan would have been created, charged and
 * renewed monthly, with no error anywhere. Eight such ternaries existed across
 * four gateways plus two more in Stripe's price-id lookup.
 *
 * A map cannot fail that way: an unknown cycle returns null and the caller
 * refuses. Adding a fifth cycle means editing this file and nothing else.
 */
final class BillingCycle
{
    public const MONTH = 'month';

    public const QUARTER = 'quarter';

    public const HALF_YEAR = 'half_year';

    public const YEAR = 'year';

    /** @var list<string> Canonical order — shortest first. Drives UI ordering too. */
    public const ALL = [
        self::MONTH,
        self::QUARTER,
        self::HALF_YEAR,
        self::YEAR,
    ];

    /**
     * ⚠️ HORIZONS ARE ~10 REAL-WORLD YEARS, EXPRESSED IN EACH CYCLE'S OWN UNITS.
     *
     * Razorpay and Cashfree both refuse an open-ended subscription and demand a
     * finite count, so the app fakes one with a long horizon. The previous code
     * used `$cycle === 'year' ? 10 : 120` — correct for the two cycles that
     * existed, but the 120 is "120 MONTHS", not "120 cycles". Applied to
     * quarterly it would have meant 120 quarters = **30 years**, and to
     * half-yearly 60 years.
     *
     * These counts are cycles, so each row multiplies out to the same ~10 years.
     * Ten years is arbitrary but deliberate: long enough that no real customer
     * reaches it, short enough that a stuck subscription eventually stops rather
     * than billing forever.
     *
     * ⚠️ Both gateways expire the subscription SILENTLY at the cap. That was true
     * before this change and remains true; it is recorded in CLAUDE.md.
     *
     * @var array<string, int>
     */
    public const HORIZON_CYCLES = [
        self::MONTH => 120,     // 10 years
        self::QUARTER => 40,    // 10 years
        self::HALF_YEAR => 20,  // 10 years
        self::YEAR => 10,       // 10 years
    ];

    /**
     * How many months one cycle spans. Used for the gateway multipliers below
     * and for monthly-equivalent price comparisons in the UI.
     *
     * @var array<string, int>
     */
    public const MONTHS = [
        self::MONTH => 1,
        self::QUARTER => 3,
        self::HALF_YEAR => 6,
        self::YEAR => 12,
    ];

    // ── Per-plan column names ─────────────────────────────────────────────

    /** @var array<string, string> */
    public const PRICE_COLUMN = [
        self::MONTH => 'monthly_price_cents',
        self::QUARTER => 'quarterly_price_cents',
        self::HALF_YEAR => 'half_yearly_price_cents',
        self::YEAR => 'yearly_price_cents',
    ];

    /**
     * ⚠️ THE COLUMN THAT FIXED A SILENT MIS-BILLING.
     *
     * `StripeGateway::changePlan()` looked this up as
     * `$cycle === 'year' ? 'stripe_yearly_id' : 'stripe_monthly_id'`. A quarterly
     * change would have found the MONTHLY price id and, if set, moved the
     * customer to monthly billing while the caller believed it was quarterly.
     *
     * @var array<string, string>
     */
    public const STRIPE_PRICE_ID_COLUMN = [
        self::MONTH => 'stripe_monthly_id',
        self::QUARTER => 'stripe_quarterly_id',
        self::HALF_YEAR => 'stripe_half_yearly_id',
        self::YEAR => 'stripe_yearly_id',
    ];

    // ── Gateway mappings ──────────────────────────────────────────────────

    /**
     * Stripe: `recurring.interval` + `recurring.interval_count`.
     *
     * Stripe has no native quarter or half-year; both are month multiples. Its
     * documented ceiling is a 3-year total interval, so 3 and 6 months are well
     * inside it. Verified against stripe-php 19.4.1, whose Price::create()
     * signature accepts `recurring: {interval, interval_count}`.
     *
     * @var array<string, array{interval: string, interval_count: int}>
     */
    public const STRIPE = [
        self::MONTH => ['interval' => 'month', 'interval_count' => 1],
        self::QUARTER => ['interval' => 'month', 'interval_count' => 3],
        self::HALF_YEAR => ['interval' => 'month', 'interval_count' => 6],
        self::YEAR => ['interval' => 'year', 'interval_count' => 1],
    ];

    /**
     * Razorpay: `period` + `interval`.
     *
     * ⚠️ Razorpay is the ONLY one of the four with a NATIVE `quarterly` period
     * (its Create Plan API accepts daily|weekly|monthly|quarterly|yearly), so
     * quarter uses it directly rather than monthly×3. There is no half-yearly
     * period, so that is monthly×6.
     *
     * @var array<string, array{period: string, interval: int}>
     */
    public const RAZORPAY = [
        self::MONTH => ['period' => 'monthly', 'interval' => 1],
        self::QUARTER => ['period' => 'quarterly', 'interval' => 1],
        self::HALF_YEAR => ['period' => 'monthly', 'interval' => 6],
        self::YEAR => ['period' => 'yearly', 'interval' => 1],
    ];

    /**
     * Cashfree: `plan_interval_type` + `plan_intervals`.
     *
     * Accepts DAY|WEEK|MONTH|YEAR only — no quarter, no half-year — so both are
     * MONTH multiples.
     *
     * @var array<string, array{type: string, intervals: int}>
     */
    public const CASHFREE = [
        self::MONTH => ['type' => 'MONTH', 'intervals' => 1],
        self::QUARTER => ['type' => 'MONTH', 'intervals' => 3],
        self::HALF_YEAR => ['type' => 'MONTH', 'intervals' => 6],
        self::YEAR => ['type' => 'YEAR', 'intervals' => 1],
    ];

    /**
     * PayPal: `billing_cycles[].frequency.interval_unit` + `.interval_count`.
     *
     * DAY|WEEK|MONTH|YEAR, with MONTH capped at an interval_count of 12.
     *
     * @var array<string, array{interval_unit: string, interval_count: int}>
     */
    public const PAYPAL = [
        self::MONTH => ['interval_unit' => 'MONTH', 'interval_count' => 1],
        self::QUARTER => ['interval_unit' => 'MONTH', 'interval_count' => 3],
        self::HALF_YEAR => ['interval_unit' => 'MONTH', 'interval_count' => 6],
        self::YEAR => ['interval_unit' => 'YEAR', 'interval_count' => 1],
    ];

    // ── Helpers ───────────────────────────────────────────────────────────

    public static function isValid(string $cycle): bool
    {
        return in_array($cycle, self::ALL, true);
    }

    /**
     * ⚠️ Returns null for an unknown cycle rather than defaulting. Callers must
     * refuse — defaulting is exactly the fail-open behaviour this class replaces.
     *
     * @return array{interval: string, interval_count: int}|null
     */
    public static function stripe(string $cycle): ?array
    {
        return self::STRIPE[$cycle] ?? null;
    }

    /** @return array{period: string, interval: int}|null */
    public static function razorpay(string $cycle): ?array
    {
        return self::RAZORPAY[$cycle] ?? null;
    }

    /** @return array{type: string, intervals: int}|null */
    public static function cashfree(string $cycle): ?array
    {
        return self::CASHFREE[$cycle] ?? null;
    }

    /** @return array{interval_unit: string, interval_count: int}|null */
    public static function paypal(string $cycle): ?array
    {
        return self::PAYPAL[$cycle] ?? null;
    }

    public static function horizonCycles(string $cycle): ?int
    {
        return self::HORIZON_CYCLES[$cycle] ?? null;
    }

    public static function priceColumn(string $cycle): ?string
    {
        return self::PRICE_COLUMN[$cycle] ?? null;
    }

    public static function stripePriceIdColumn(string $cycle): ?string
    {
        return self::STRIPE_PRICE_ID_COLUMN[$cycle] ?? null;
    }

    public static function months(string $cycle): ?int
    {
        return self::MONTHS[$cycle] ?? null;
    }

    /**
     * Translate to the `client_subscriptions` vocabulary.
     *
     * @return string|null null when the cycle is unknown — never a guess.
     */
    public static function toClientVocabulary(string $cycle): ?string
    {
        return match ($cycle) {
            self::MONTH => 'monthly',
            self::QUARTER => 'quarterly',
            self::HALF_YEAR => 'half_yearly',
            self::YEAR => 'yearly',
            default => null,
        };
    }

    /** Translate from the `client_subscriptions` vocabulary. */
    public static function fromClientVocabulary(string $clientCycle): ?string
    {
        return match ($clientCycle) {
            'monthly' => self::MONTH,
            'quarterly' => self::QUARTER,
            'half_yearly' => self::HALF_YEAR,
            'yearly' => self::YEAR,
            default => null,
        };
    }
}
