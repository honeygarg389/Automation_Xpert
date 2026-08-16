<?php

namespace App\Modules\SmartQr\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;

/**
 * ⚠️ §6 — "the assigned user and the WhatsApp channel must belong to the
 * selected tenant". Two checks, and they are OPPOSITE SHAPES.
 *
 * ─── THE USER: R-14, and four candidate definitions ─────────────────────────
 *
 * This codebase answers "does this user belong to this workspace?" in four
 * places, two of which have already diverged into a complete bypass once:
 *
 *   users.workspace_id            the user's PRIMARY workspace — not membership
 *   workspace_user pivot          raw rows, NEVER revoked
 *   User::accessibleWorkspaces()  owned ∪ pivot, filtered by client_id
 *   Workspace::isAccessibleBy()   client_id check, then owner, then pivot
 *
 * R-14 rules `isAccessibleBy()`. It answers the boolean question actually being
 * asked, it carries the client_id control so a stale pivot row from a former
 * organisation grants nothing, and it is what WorkspacePolicy::view uses — so
 * this validator and workspace authorization cannot drift into two answers.
 *
 * ⚠️ `users.workspace_id` is the trap. It reads simpler and is WRONG: a
 * legitimate member whose PRIMARY workspace is a different one would be
 * silently refused, and the admin would see a valid user missing from the
 * picker with no explanation. One test carries this —
 * `a_member_whose_primary_workspace_is_different_is_accepted`.
 *
 * ─── THE CHANNEL: the opposite direction, and it FAILS CLOSED ───────────────
 *
 * `ChannelAccount` uses BelongsToWorkspace, and an admin request carries NO
 * workspace context — so a plain query has the scope AND'd onto it and matches
 * NOTHING, rejecting every channel including the correct one.
 *
 * The explicit workspace_id below IS the boundary, so the scope comes off. Same
 * shape as SmartQrAccess::boundedTo(), same fail-closed direction that made the
 * slice-1 canary return 0 while its cross-tenant half passed throughout.
 */
class SmartQrAssignmentValidator
{
    /**
     * Channels this workspace may be assigned, as an id => label map for the
     * picker. Also the authoritative list the store path validates against —
     * one definition, so the form and the check cannot disagree.
     *
     * @return \Illuminate\Support\Collection<int, ChannelAccount>
     */
    public function channelsFor(Workspace $workspace)
    {
        return ChannelAccount::withoutWorkspaceScope(
            'reason: admin assignment runs with no workspace context; the explicit workspace_id '
            .'below IS the boundary, and the scope failing closed would reject every channel '
            .'including the correct one'
        )
            ->where('workspace_id', $workspace->id)
            ->where('channel', 'whatsapp')
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Is this channel assignable to this workspace?
     *
     * Two conditions, and the second is §6's: "prevent assignment to inactive or
     * disconnected WhatsApp channels". A QR pointing at a dead channel is a
     * printed sticker that goes nowhere.
     *
     * ⚠️ There is no `disconnected` state in this codebase — `status` is
     * `enum('active','inactive','error')`. So the rule is written as "must be
     * active" rather than "must not be disconnected": a deny-list against a
     * value that does not exist would let `error` through, and the spec's
     * wording was written without seeing the column.
     */
    public function channelIsAssignable(Workspace $workspace, ?int $channelAccountId): bool
    {
        if ($channelAccountId === null) {
            return false;
        }

        $channel = $this->channelsFor($workspace)->firstWhere('id', $channelAccountId);

        return $channel !== null && $channel->status === 'active';
    }

    /**
     * ⚠️ R-14. Membership, not primary workspace.
     *
     * Delegates to Workspace::isAccessibleBy so there is ONE definition. If this
     * ever becomes `$user->workspace_id === $workspace->id`, the test named in
     * the class docblock fails — which is the entire reason that test exists.
     */
    public function userBelongsToWorkspace(Workspace $workspace, ?int $userId): bool
    {
        if ($userId === null) {
            return true;   // the assigned user is optional (§6 step 6)
        }

        $user = User::find($userId);

        return $user !== null && $workspace->isAccessibleBy($user);
    }

    /**
     * Users assignable to this workspace, for the picker.
     *
     * ⚠️ Built by FILTERING through isAccessibleBy rather than by querying
     * users.workspace_id, so the picker and the validation above agree by
     * construction. A picker built from a different query is how a form offers
     * an option its own validator rejects.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function assignableUsersFor(Workspace $workspace)
    {
        return User::query()
            ->where(function ($q) use ($workspace) {
                $q->where('client_id', $workspace->client_id)
                    ->orWhere('id', $workspace->owner_id);
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $workspace->isAccessibleBy($u))
            ->values();
    }
}
