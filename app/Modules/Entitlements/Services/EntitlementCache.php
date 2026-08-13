<?php

namespace App\Modules\Entitlements\Services;

use App\Models\Client;
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
 * Write-through would put a cache write on every plan, subscription and grant
 * path — 24+ writers across four sources, each of which has to remember. A
 * forgotten writer produces a silently stale row, which is the same
 * one-idea-in-many-places disease that produced BUG-027 and BUG-028.
 *
 * The event listeners are a write-through HINT: they refresh eagerly so the
 * common case is warm, but correctness never depends on them firing.
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
            'grants' => DB::table('entitlement_grants')
                ->where('client_id', $client->id)
                ->orWhere('partner_id', $client->partner_id)
                ->orderBy('id')->get(['id', 'add_on_id', 'status', 'quantity'])->toJson(),
        ];

        return hash('sha256', json_encode($parts));
    }
}
