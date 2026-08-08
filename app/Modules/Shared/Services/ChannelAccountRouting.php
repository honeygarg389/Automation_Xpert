<?php

namespace App\Modules\Shared\Services;

use App\Exceptions\AmbiguousChannelRoutingException;
use App\Exceptions\ChannelAlreadyConnectedException;
use App\Models\Scopes\WorkspaceScope;
use App\Modules\Shared\Models\ChannelAccount;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;

/**
 * BUG-019. The single place a channel routing identifier is attached to a
 * workspace, and the single place inbound traffic is routed by one.
 *
 * ═══ THIS SERVICE IS THE LOAD-BEARING CONTROL. NOT THE UNIQUE INDEX. ═══
 *
 * There is a `UNIQUE` index on `channel_accounts.phone_number_id`, and it is
 * easy to read that and conclude the database prevents cross-tenant routing
 * collisions. **It covers ONE of the three shapes.**
 *
 *   WhatsApp   phone_number_id                     -> a real column, UNIQUE ✓
 *   Messenger  meta_json->page_id                  -> a JSON path, NOT indexed
 *   Instagram  meta_json->instagram_page_id
 *              OR meta_json->instagram_account_id  -> TWO JSON paths, NOT indexed
 *
 * A generated column plus a unique index would cover Messenger. It was
 * deliberately NOT built, because it cannot express Instagram's rule — the
 * inbound router matches EITHER key with an `orWhere`, so uniqueness has to
 * hold across both, and one generated column cannot say that. A schema that
 * protects Messenger and not Instagram reads as "channels are covered" while
 * one of them is not, and a partial protection that looks complete is worse
 * than none.
 *
 * So: the index is a backstop for the one shape it fits. Everything else
 * depends on every attach going through here. `ChannelRoutingGuardTest`
 * enforces that no other file constructs a ChannelAccount carrying a routing
 * identifier.
 *
 * ═══ Why the Meta channels are the worse case, not the equal one ═══
 *
 * `webhooks/meta/{token}` validates against
 * `CredentialResolver::system()->meta()->verifyToken()` — a PLATFORM-GLOBAL
 * token, identical for every tenant. So a Messenger or Instagram webhook
 * carries no per-tenant identifier in its URL at all, and routing rests
 * entirely on the JSON match performed here. WhatsApp at least has the
 * per-WABA `webhooks/whatsapp/{token}` path, whose token IS unique.
 *
 * ═══ Phase 0 note ═══
 *
 * The queries below use Eloquent, so once `ChannelAccount` takes the
 * `BelongsToWorkspace` trait (Phase 0 slice 6) BOTH of them will need an
 * explicit one-query bypass: `findForInbound()` because it runs with no
 * authenticated user and the workspace is the ANSWER it is looking for, and
 * `resolveForAttach()` because detecting a cross-workspace claim means seeing
 * across workspaces by definition. Both are hazard H-3's shape. Left as-is
 * here because that trait does not exist on this branch — flagged so the slice
 * that adds it does not discover this by way of a silently empty result.
 */
class ChannelAccountRouting
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Resolve the ChannelAccount to update for an attach, or null to create one.
     *
     * @param  array<string, string>  $routingKeys  column-or-JSON-path => value
     *
     * @throws ChannelAlreadyConnectedException when the identifier already
     *                                          belongs to a DIFFERENT workspace
     */
    public function resolveForAttach(int $workspaceId, string $channel, array $routingKeys): ?ChannelAccount
    {
        $matches = $this->matching($channel, $routingKeys)->get();

        foreach ($matches as $match) {
            if ((int) $match->workspace_id !== $workspaceId) {
                // REFUSE. Ruled 2026-08-08.
                //
                // Reassigning silently is the alternative, and it is invisible:
                // if the reassignment is wrong, one company reads another's
                // conversations and nobody is told. A refusal produces a support
                // ticket, which is a person noticing.
                //
                // When the partner tier makes moving a number between workspaces
                // a supported business event, that gets a real flow —
                // deactivate + audit log + notify both workspaces — as its own
                // feature. See docs/found-bugs.md BUG-019.
                throw ChannelAlreadyConnectedException::inAnotherWorkspace(
                    $channel,
                    $routingKeys,
                    (int) $match->workspace_id,
                    $workspaceId,
                );
            }
        }

        // Same workspace, or nothing yet. A same-workspace reconnect must behave
        // EXACTLY as it did before this guard existed — it is the only case that
        // happens today, and breaking it is the failure a real customer meets
        // first.
        return $matches->first();
    }

    /**
     * Route an inbound event to its ChannelAccount.
     *
     * @param  array<string, string>  $routingKeys
     *
     * @throws AmbiguousChannelRoutingException when more than one workspace claims it
     */
    public function findForInbound(string $channel, array $routingKeys): ?ChannelAccount
    {
        $matches = $this->matching($channel, $routingKeys)->get();

        if ($matches->count() > 1) {
            // Two workspaces claim the same identifier. `->first()` used to pick
            // one arbitrarily — in practice the oldest row — so every message
            // for that number kept landing in the previous tenant's inbox
            // indefinitely, with no error anywhere.
            //
            // Refusing is fail-closed: the message is dropped and reported
            // rather than delivered to the wrong company.
            $this->recordAmbiguity($channel, $routingKeys, $matches->pluck('workspace_id')->all());

            throw AmbiguousChannelRoutingException::forIdentifier(
                $channel,
                $routingKeys,
                $matches->pluck('workspace_id')->map(fn ($id) => (int) $id)->all(),
            );
        }

        return $matches->first();
    }

    /**
     * Make the ambiguity visible WITHOUT reading logs, and without failing the
     * job.
     *
     * The requirement: one poisoned identifier must not stop the other messages
     * in the same payload — the per-message try/catch in the drivers is a
     * decision already correctly made — but an operator must still find out.
     *
     * Options considered:
     *
     *   Counter, then throw after the loop. Fails the job, so it lands in
     *   failed_jobs. Rejected: the job then retries the WHOLE payload forever
     *   until a human fixes the data, and "some messages were ambiguous" becomes
     *   indistinguishable from "this job is broken".
     *
     *   Sentry only. `report()` reaches Sentry — but only when SENTRY_LARAVEL_DSN
     *   is configured, which is optional. A control that is off by default is
     *   not a control.
     *
     *   A platform-admin notification. No precedent exists for notifying admins
     *   in this codebase; it would be new infrastructure for one caller.
     *
     *   CHOSEN: an AuditLog row. It is persistent, queryable, already surfaced
     *   at /admin/audit-log, and needs nothing new. `report()` is called too, so
     *   Sentry gets it when configured — belt and braces, no dependency.
     *
     * @param  array<string, string>  $routingKeys
     * @param  list<int|string>  $workspaceIds
     */
    private function recordAmbiguity(string $channel, array $routingKeys, array $workspaceIds): void
    {
        $this->auditLog->logAdmin(
            'channel.routing_ambiguous',
            ChannelAccount::class,
            null,
            [
                'channel' => $channel,
                'routing_keys' => $routingKeys,
                'claimed_by_workspaces' => $workspaceIds,
                'consequence' => 'Inbound messages for this identifier are being DROPPED until exactly one workspace claims it. Run `php artisan channels:audit-routing` for the full picture.',
            ],
        );

        // Belt and braces. Reaches Sentry when SENTRY_LARAVEL_DSN is configured,
        // and the 'errors' log channel regardless. Deliberately NOT the primary
        // mechanism — the audit row above is, because Sentry is optional.
        report(AmbiguousChannelRoutingException::forIdentifier(
            $channel,
            $routingKeys,
            array_map('intval', $workspaceIds),
        ));
    }

    /**
     * @param  array<string, string>  $routingKeys
     * @return Builder<ChannelAccount>
     */
    private function matching(string $channel, array $routingKeys): Builder
    {
        // Phase 0. BOTH callers need the scope off, and both are one query wide:
        //
        //   findForInbound()    routes every inbound message with no
        //                       authenticated user, and the workspace is the
        //                       ANSWER it is looking for. Scoped, it returns
        //                       null for EVERY message and each driver's "no
        //                       channel_account match" branch drops the lot.
        //
        //   resolveForAttach()  detects an identifier already claimed by
        //                       ANOTHER workspace. Seeing across workspaces is
        //                       the entire point; scoped, it refuses nothing and
        //                       BUG-019 quietly returns.
        //
        // Applied now rather than when ChannelAccount takes the trait:
        // withoutGlobalScope() is a no-op while the scope is absent, so this is
        // safe today and removes a trap from the slice that scopes it.
        return ChannelAccount::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('channel', $channel)
            ->where(function (Builder $query) use ($routingKeys) {
                // OR across keys, because Instagram genuinely stores its id under
                // either `instagram_page_id` or `instagram_account_id` and the
                // inbound router matches both. Uniqueness therefore has to hold
                // across the SET, which is exactly what a single unique index
                // cannot express.
                foreach ($routingKeys as $key => $value) {
                    if (str_contains($key, '->')) {
                        $query->orWhereJsonContains($key, $value);
                    } else {
                        $query->orWhere($key, $value);
                    }
                }
            });
    }
}
