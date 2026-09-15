<?php

namespace App\Modules\Entitlements\Services;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Workspace;
use App\Modules\Entitlements\Support\Entitlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-through cache over {@see EntitlementResolver}.
 *
 * ─── ⚠️ A MISS IS NEVER EMPTY ───────────────────────────────────────────────
 *
 * On a miss this computes and returns the real answer. It never returns an empty
 * Entitlement, and it never returns null for a caller to interpret — an empty
 * entitlement reads as "no limits granted", which every consumer treats as
 * UNLIMITED. That is the fail-open shape this codebase has produced four times
 * (BUG-023, ContactCapacity, UsageMeter::current, the gauge counts), and a cache
 * is the easiest place yet to reintroduce it.
 *
 * Dropping every row must therefore change no answer. `truncating_the_cache_changes_no_answer`
 * asserts it.
 *
 * ─── Read-through, not write-through ────────────────────────────────────────
 *
 * Known plan and assignment writes synchronously DROP affected rows. That is
 * deliberately cheaper and safer than trying to duplicate the resolver here:
 * the next read takes the normal read-through path and computes the answer from
 * the source of truth. The queued reconciliation jobs then warm those rows;
 * they are a latency optimisation, never the moment correctness begins.
 *
 * Other event listeners remain a best-effort warm-up hint, and the scheduled
 * source-hash sweep is the repair net for a writer that was missed entirely.
 */
class EntitlementCache
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly EntitlementBoundary $boundary,
    ) {}

    public static function isEnabled(): bool
    {
        return (bool) config('entitlements.cache_enabled', true);
    }

    public function forWorkspace(int $workspaceId): Entitlement
    {
        if (! self::isEnabled()) {
            return $this->resolver->for($workspaceId);
        }

        $row = DB::table('workspace_entitlements')->where('workspace_id', $workspaceId)->first();

        if ($row !== null && ! $this->isStale($row)) {
            return $this->hydrate($row);
        }

        return $this->refresh($workspaceId);
    }

    /**
     * ⚠️ Stale at the BOUNDARY, not at an arbitrary age.
     *
     * A flat TTL means every expiry has a window in which the answer is known
     * wrong — and we know precisely when it goes wrong, so guessing is
     * inexcusable. The flat fallback applies only where there is genuinely no
     * boundary to compute.
     */
    private function isStale(object $row): bool
    {
        if ($row->valid_until !== null) {
            return now()->greaterThanOrEqualTo($row->valid_until);
        }

        $ttl = (int) config('entitlements.cache_fallback_ttl_minutes', 60);

        return now()->greaterThanOrEqualTo(
            Carbon::parse($row->computed_at)->addMinutes($ttl)
        );
    }

    /** Recompute, store, return. Idempotent: a pure recompute keyed by workspace. */
    public function refresh(int $workspaceId): Entitlement
    {
        $workspace = Workspace::with('client')->find($workspaceId);
        $client = $workspace?->client;

        $entitlement = $this->resolver->forClient($client);
        $boundary = $this->boundary->forClient($client);

        if ($workspace !== null) {
            DB::table('workspace_entitlements')->updateOrInsert(
                ['workspace_id' => $workspaceId],
                [
                    'payload' => json_encode([
                        'limits' => $entitlement->limits(),
                        'flags' => $entitlement->flags(),
                    ]),
                    'valid_until' => $boundary,
                    'computed_at' => now(),
                    'source_hash' => $this->sourceHash($client),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        return $entitlement;
    }

    public function forget(int $workspaceId): void
    {
        DB::table('workspace_entitlements')->where('workspace_id', $workspaceId)->delete();
    }

    /**
     * Forget every materialized answer owned by one client.
     *
     * Entitlements are client-level inputs rendered per workspace. A plan
     * reassignment or plan edit can therefore make every workspace under that
     * client stale at once; leaving even one row behind would make a workspace
     * switch resurrect the old answer until the fallback TTL elapsed.
     */
    public function forgetClient(?int $clientId): void
    {
        if ($clientId === null) {
            return;
        }

        DB::table('workspace_entitlements')
            ->whereIn('workspace_id', Workspace::query()->where('client_id', $clientId)->select('id'))
            ->delete();
    }

    /** Every workspace of a client — the unit an entitlement change actually affects. */
    public function refreshClient(?int $clientId): void
    {
        if ($clientId === null) {
            return;
        }

        Workspace::where('client_id', $clientId)->pluck('id')
            ->each(fn ($id) => $this->refresh((int) $id));
    }

    private function hydrate(object $row): Entitlement
    {
        $payload = json_decode($row->payload, true) ?: [];

        return new Entitlement($payload['limits'] ?? [], $payload['flags'] ?? []);
    }

    /**
     * Digest of the INPUTS, so the sweep can detect drift without recomputing.
     *
     * Statuses are included deliberately: an id-only hash would miss a status
     * change, which is exactly the divergence the sweep exists to catch.
     */
    public function sourceHash(?Client $client): string
    {
        if ($client === null) {
            return hash('sha256', 'no-client');
        }

        $parts = [
            'client' => $client->id,
            'partner' => $client->partner_id,
            'partner_mode' => $client->partner?->entitlement_mode,
            'client_subs' => DB::table('client_subscriptions')->where('client_id', $client->id)
                ->orderBy('id')->get(['id', 'plan_id', 'status'])->toJson(),
            'user_subs' => DB::table('subscriptions')
                ->whereIn('user_id', DB::table('users')->where('client_id', $client->id)->select('id'))
                ->orderBy('id')->get(['id', 'plan_id', 'status'])->toJson(),
            // Plan id alone cannot detect a shared plan row being edited. These
            // are the complete mutable plan inputs consumed by
            // PlanPackageSynthesizer: normaliseLimits() reads limits, and
            // legacyFlags() reads limits, white_label_enabled and
            // whatsapp_flows_enabled.
            'plan' => $this->planEntitlementInputs($this->planForEntitlementSource($client)),
            'grants' => DB::table('entitlement_grants')
                ->where('client_id', $client->id)
                ->orWhere('partner_id', $client->partner_id)
                ->orderBy('id')->get(['id', 'add_on_id', 'status', 'quantity'])->toJson(),
        ];

        return hash('sha256', json_encode($parts));
    }

    private function planForEntitlementSource(Client $client): ?Plan
    {
        return config('entitlements.enforce_effective_plan_source', false)
            ? $client->effectivePlan()
            : $client->activePlan();
    }

    /**
     * @return array{id: int, limits: mixed, white_label_enabled: bool, whatsapp_flows_enabled: bool}|null
     */
    private function planEntitlementInputs(?Plan $plan): ?array
    {
        if ($plan === null) {
            return null;
        }

        return [
            'id' => (int) $plan->id,
            'limits' => $this->canonicalize($plan->limits ?? []),
            'white_label_enabled' => (bool) $plan->white_label_enabled,
            'whatsapp_flows_enabled' => (bool) $plan->whatsapp_flows_enabled,
        ];
    }

    /**
     * A JSON object has no meaningful key order. Sort it before hashing so a
     * re-serialized but semantically identical limits payload does not create
     * false drift; list order remains meaningful and is preserved.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalize($item);
        }

        if (! array_is_list($value)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
