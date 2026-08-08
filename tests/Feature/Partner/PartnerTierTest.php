<?php

namespace Tests\Feature\Partner;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Partner;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0 — the partner tier's data layer.
 *
 * `Platform Owner → Partner → Client → Workspace → Users`. Every link but the
 * first already existed.
 *
 * The centrepiece is not the new relation: it is that **platform-owned
 * customers keep working**. `partner_id = null` is a permanent supported state,
 * and a tier that quietly breaks direct customers would be worse than no tier.
 */
class PartnerTierTest extends TestCase
{
    use RefreshDatabase;

    // ══ THE REGRESSION THAT MATTERS: direct customers ═════════════════════

    /**
     * ⚠️ A platform-owned client has no partner and must behave exactly as
     * before — created, read, related, and reachable through the full
     * hierarchy.
     *
     * This is explicit rather than incidental because it is the failure that
     * would be easiest to ship and hardest to notice: every partner feature is
     * written against clients that HAVE a partner, and the ones that don't stop
     * being exercised.
     */
    #[Test]
    public function a_platform_owned_client_has_no_partner_and_still_works(): void
    {
        ['client' => $client, 'workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();

        $this->assertNull($client->partner_id, 'A client created the normal way must be direct.');
        $this->assertNull($client->partner, 'The relation must resolve to null, not error.');

        // The whole hierarchy still traverses.
        $this->assertSame((int) $client->id, (int) $workspace->client_id);
        $this->assertSame((int) $client->id, (int) $user->client_id);
        $this->assertTrue($client->workspaces()->exists());
        $this->assertTrue($client->users()->exists());
    }

    /**
     * The trap the named scopes exist to prevent: a partner-shaped query
     * silently omitting every direct customer.
     */
    #[Test]
    public function direct_only_finds_platform_owned_clients_that_a_partner_query_would_miss(): void
    {
        $partner = Partner::factory()->create();

        $partnered = Client::create(['name' => 'Resold Co', 'partner_id' => $partner->id]);
        $direct = Client::create(['name' => 'Direct Co']);

        $this->assertSame([$partnered->id], Client::forPartner($partner->id)->pluck('id')->all());
        $this->assertSame([$direct->id], Client::directOnly()->pluck('id')->all());

        // The point: neither view alone is the platform total.
        $this->assertSame(2, Client::count(),
            'A platform-wide count must include both. A bare where(partner_id) would report 1.');
    }

    /** A workspace's partner is DERIVED, not stored. */
    #[Test]
    public function a_workspaces_partner_is_derived_through_its_client(): void
    {
        $partner = Partner::factory()->create();
        ['client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $client->update(['partner_id' => $partner->id]);

        $this->assertSame(
            (int) $partner->id,
            (int) $workspace->fresh()->client->partner->id,
            'The partner must be reachable as workspace -> client -> partner.'
        );

        $this->assertFalse(
            Schema::hasColumn('workspaces', 'partner_id'),
            'partner_id must NOT be denormalised onto workspaces. It lives on clients and is '
            .'derived through that relationship; denormalising is permitted only onto '
            .'aggregate/billing tables, each documented.'
        );
    }

    // ══ Relations ══════════════════════════════════════════════════════════

    #[Test]
    public function a_partner_has_many_clients_and_a_client_belongs_to_one(): void
    {
        $partner = Partner::factory()->create();

        $a = Client::create(['name' => 'A', 'partner_id' => $partner->id]);
        $b = Client::create(['name' => 'B', 'partner_id' => $partner->id]);
        Client::create(['name' => 'Direct']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $partner->clients()->pluck('id')->all());
        $this->assertSame((int) $partner->id, (int) $a->partner->id);
    }

    #[Test]
    public function a_partner_gets_a_uuid_and_slug_automatically_and_routes_by_uuid(): void
    {
        $partner = Partner::create(['name' => 'Acme Resellers']);

        $this->assertNotEmpty($partner->uuid);
        $this->assertSame('acme-resellers', $partner->slug);
        $this->assertSame('uuid', $partner->getRouteKeyName(),
            'A sequential id in a URL would enumerate resellers.');
    }

    // ══ RESTRICT actually restricting ══════════════════════════════════════

    /**
     * A partner leaving is a business event with money attached. The database
     * must refuse until a human has decided where the customers go.
     *
     * The two rejected alternatives are why this matters: nullOnDelete would
     * silently convert a partner's customers into direct ones — changing who
     * bills them, with no record — and cascadeOnDelete would delete live
     * customers.
     */
    #[Test]
    public function deleting_a_partner_with_clients_is_refused_by_the_database(): void
    {
        $partner = Partner::factory()->create();
        $client = Client::create(['name' => 'Resold Co', 'partner_id' => $partner->id]);

        try {
            $partner->delete();
            $this->fail('A partner with live customers was deleted. RESTRICT is not in force.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }

        $this->assertSame(1, DB::table('partners')->where('id', $partner->id)->count(),
            'The partner survives.');
        $this->assertSame((int) $partner->id, (int) $client->fresh()->partner_id,
            'And so does the customer relationship — not silently converted to direct.');
    }

    /** POSITIVE CONTROL: a partner with NO clients deletes normally. */
    #[Test]
    public function a_partner_with_no_clients_can_be_deleted(): void
    {
        $partner = Partner::factory()->create();

        $partner->delete();

        $this->assertSame(0, DB::table('partners')->where('id', $partner->id)->count(),
            'RESTRICT must block only the case that has customers attached — otherwise it is '
            .'not a constraint, it is a bug.');
    }

    /** Losing the account manager must not take the partner with it. */
    #[Test]
    public function deleting_the_owning_admin_leaves_the_partner_intact(): void
    {
        $admin = $this->createSuperAdmin();
        $partner = Partner::factory()->create(['owner_admin_user_id' => $admin->id]);

        $admin->delete();

        $this->assertSame(1, DB::table('partners')->where('id', $partner->id)->count());
        $this->assertNull($partner->fresh()->owner_admin_user_id);
    }

    // ══ The scope must not reach this layer ════════════════════════════════

    /**
     * `Partner` sits TWO levels above the tenant boundary, so "which workspace
     * does a partner belong to" has no answer.
     *
     * The coverage guard cannot catch this: it inventories tables that HAVE a
     * `workspace_id` column, and this one never will. So it is asserted here, in
     * the shape of the existing `User` and `Workspace` guards.
     */
    #[Test]
    public function partner_is_not_workspace_scoped_and_must_never_be(): void
    {
        $this->assertNotContains(BelongsToWorkspace::class, class_uses_recursive(Partner::class),
            'Partner owns many clients, each owning many workspaces — scoping it to one '
            .'workspace is incoherent, not merely wrong.');

        $this->assertFalse(Schema::hasColumn('partners', 'workspace_id'));

        // The whole layer is above the boundary; pinned together so a later
        // change to any one of them is visible.
        foreach ([Client::class, Workspace::class, ClientSubscription::class] as $model) {
            $this->assertNotContains(BelongsToWorkspace::class, class_uses_recursive($model),
                $model.' is above the tenant boundary and must not be workspace-scoped.');
        }
    }
}
