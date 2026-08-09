<?php

namespace App\Modules\Entitlements\Support;

use App\Models\Client;
use App\Modules\Entitlements\Services\EntitlementResolver;

/**
 * The facade feature code asks. CLAUDE.md rule 4: feature code asks an
 * entitlement facade, never `plans.limits` directly.
 *
 * Thin on purpose. It resolves an owner to an {@see Entitlement} and answers
 * one question; every decision was made by the fold. If logic starts
 * accumulating here it belongs in the resolver, where it is tested against the
 * canary.
 *
 * ─── ⚠️ THE BRAKE ───────────────────────────────────────────────────────────
 *
 * `entitlements.enabled` is checked by the CALLERS, not here. That is
 * deliberate: each of the three enforcing sites keeps its original legacy
 * expression intact and visible beside the new one, so the fallback is a path a
 * reader can compare rather than a deleted branch they have to reconstruct from
 * git history during an incident.
 */
class Entitlements
{
    public function __construct(private readonly EntitlementResolver $resolver) {}

    public static function isEnabled(): bool
    {
        return (bool) config('entitlements.enabled', true);
    }

    public function forWorkspace(int $workspaceId): Entitlement
    {
        return $this->resolver->for($workspaceId);
    }

    public function forClient(?Client $client): Entitlement
    {
        return $this->resolver->forClient($client);
    }

    /** null = unlimited, or never granted. Ask `has()` to tell them apart. */
    public function limitForWorkspace(int $workspaceId, string $key): ?int
    {
        return $this->forWorkspace($workspaceId)->limit($key);
    }

    public function limitForClient(?Client $client, string $key): ?int
    {
        return $this->forClient($client)->limit($key);
    }
}
