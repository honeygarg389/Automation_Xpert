<?php

namespace App\Modules\Entitlements\Support;

/**
 * The resolved answer to "what may this workspace do".
 *
 * Immutable, and deliberately dumb: every decision was made by the fold that
 * built it. Nothing here re-derives anything.
 *
 * ─── ⚠️ null is TWO different facts, and callers must be able to tell ───────
 *
 * `limit()` returns null both for "granted, unlimited" and for "not granted at
 * all", because that is exactly what `EnforceLimit` does today with
 * `$limits[$key] ?? null` — and slice 2 must not change behaviour while it is
 * proving the mechanism.
 *
 * But conflating the two is the BUG-023 failure class in miniature: a limit that
 * resolves to null and is read as unlimited, with nothing saying which it meant.
 * So `has()` exists to separate them, and every caller that cares must ask.
 */
final class Entitlement
{
    /**
     * @param  array<string, int|null>  $limits  key => value; null = unlimited. Presence = granted.
     * @param  array<string, bool>  $flags
     */
    public function __construct(
        private readonly array $limits = [],
        private readonly array $flags = [],
    ) {}

    /**
     * The numeric limit for `$key`, or null for unlimited.
     *
     * ⚠️ Also null when the key was never granted — matching today's
     * `$limits[$key] ?? null`. Use `has()` to tell the two apart.
     */
    public function limit(string $key): ?int
    {
        return $this->limits[$key] ?? null;
    }

    /** Whether `$key` was granted at all, regardless of its value. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->limits) || array_key_exists($key, $this->flags);
    }

    /** Granted AND unbounded. False when the key is absent — absence is not a grant. */
    public function isUnlimited(string $key): bool
    {
        return array_key_exists($key, $this->limits) && $this->limits[$key] === null;
    }

    /** A boolean feature. */
    public function allows(string $key): bool
    {
        return $this->flags[$key] ?? false;
    }

    /** @return array<string, int|null> */
    public function limits(): array
    {
        return $this->limits;
    }

    /** @return array<string, bool> */
    public function flags(): array
    {
        return $this->flags;
    }
}
