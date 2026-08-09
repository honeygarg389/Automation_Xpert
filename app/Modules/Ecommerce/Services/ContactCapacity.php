<?php

namespace App\Modules\Ecommerce\Services;

use App\Models\Workspace;
use App\Modules\Entitlements\Support\Entitlements;
use App\Modules\Shared\Models\Contact;

/**
 * Resolves how many more contacts a workspace may create, based on the plan's
 * optional `max_contacts` limit. Used to stop a bulk store sync from ballooning
 * the contacts table past plan limits.
 *
 * Returns null = unlimited (no `max_contacts` set), preserving existing behavior
 * for plans that don't define the limit.
 */
class ContactCapacity
{
    /**
     * ⚠️ `max_contacts` is not a key any plan carries.
     *
     * It appears in no seeder and is absent from the admin form's `LIMIT_KEYS`,
     * so it cannot be set through any live write path. This method therefore
     * returns null — unlimited — for every workspace, and has since it was
     * written. Same family as BUG-025: a limit that reads as absent and is
     * therefore treated as no limit at all.
     *
     * Not fixed here. Introducing the key would start bounding a table nobody
     * has been bounding, which is a customer-visible change, not a refactor.
     * The facade is asked for the same key and returns the same null.
     */
    public function remaining(int $workspaceId): ?int
    {
        $limit = Entitlements::isEnabled()
            ? app(Entitlements::class)->limitForWorkspace($workspaceId, 'max_contacts')
            : Workspace::with('client')->find($workspaceId)
                ?->client?->activePlan()?->limitValue('max_contacts');

        if ($limit === null) {
            return null;
        }

        // ⚠️ DELIBERATELY BYPASSES THE WORKSPACE SCOPE — hazard H-2, in its
        // fail-OPEN direction.
        //
        // This method takes an explicit $workspaceId and its own where() IS the
        // tenant boundary. The global scope on top is redundant when a context
        // is established and dangerous when it is not: the scope fails CLOSED,
        // so a missing context ANDs an unsatisfiable condition onto this query,
        // the count comes back 0, and `remaining()` reports the FULL limit as
        // available. A capacity check that reads "nothing used" grants unbounded
        // headroom — the same failure shape as UsageMeter::current().
        //
        // Both live callers (SyncStoreCustomersJob, BackfillStoreOrdersJob) do
        // establish context via EstablishesWorkspaceContext, so this is not a
        // live defect today. It is a signature that lies: the method looks
        // self-sufficient because it takes the workspace id, and the next caller
        // — a controller, a command, a tinker session — would be right to
        // believe it.
        $current = Contact::withoutWorkspaceScope(
            'reason: the explicit workspace_id argument IS the boundary; the scope failing '
            .'closed here would report zero contacts used and grant unbounded capacity'
        )->where('workspace_id', $workspaceId)->count();

        return max(0, (int) $limit - $current);
    }
}
