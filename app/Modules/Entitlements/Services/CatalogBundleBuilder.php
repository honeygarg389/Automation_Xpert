<?php

namespace App\Modules\Entitlements\Services;

use App\Models\Client;
use App\Models\Partner;
use App\Modules\Entitlements\Models\EntitlementGrant;
use App\Modules\Entitlements\Support\GrantBundle;

/**
 * Turns real `entitlement_grants` rows into the shape the fold consumes.
 *
 * Slice 2 only ever synthesized bundles from `plans.limits`; this is the first
 * code that reads the catalog. The fold cannot tell the difference, which is the
 * point — it must not know whether a bundle came from a legacy plan or a
 * purchased add-on.
 */
class CatalogBundleBuilder
{
    /**
     * Every grant a partner holds IN FORCE, as fold bundles.
     *
     * ⚠️ "In force" is status AND dates. A grant whose `ends_at` has passed is
     * not a ceiling, and this is the crux of the read-time invariant: a grant
     * LAPSES BY TIME, with no write to any row. No hook on Partner and no hook
     * on grant deletion can observe that, which is why the resolver has to.
     *
     * @return list<GrantBundle>
     */
    public function forPartner(Partner $partner): array
    {
        return $this->bundlesFor('partner_id', $partner->id);
    }

    /**
     * Every grant a CLIENT holds in force.
     *
     * ⚠️ Added when the slice-7 cache tests found that customer-held grants were
     * written by nobody and read by nobody. Slice 1 created the table, slice 5
     * read only the PARTNER side for the ceiling, and the client side was never
     * folded into the customer's own entitlement — so a purchased or
     * admin-granted add-on would have conferred nothing.
     *
     * The tests caught it because they granted a pack and got the plan's number
     * back. Nothing else would have: no code path writes a client grant yet, so
     * the gap was invisible until something read one.
     *
     * @return list<GrantBundle>
     */
    public function forClient(Client $client): array
    {
        return $this->bundlesFor('client_id', $client->id);
    }

    /** @return list<GrantBundle> */
    private function bundlesFor(string $column, int $id): array
    {
        $grants = EntitlementGrant::query()
            ->where($column, $id)
            ->where('status', EntitlementGrant::STATUS_ACTIVE)
            ->with('addOn.grants')
            ->get()
            ->filter(fn (EntitlementGrant $g) => $g->isInForce());

        $bundles = [];

        foreach ($grants as $grant) {
            $addOn = $grant->addOn;

            if (! $addOn) {
                continue;
            }

            $values = [];
            foreach ($addOn->grants as $addOnGrant) {
                $values[$addOnGrant->key] = $addOnGrant->value;
            }

            $bundles[] = new GrantBundle(
                type: $addOn->type,
                rank: (int) $addOn->rank,
                quantity: (int) $grant->quantity,
                grants: $values,
            );
        }

        return $bundles;
    }
}
