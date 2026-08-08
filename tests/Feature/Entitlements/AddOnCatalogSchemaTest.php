<?php

namespace Tests\Feature\Entitlements;

use App\Models\Client;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Partner;
use App\Models\Plan;
use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Models\AddOnGrant;
use App\Modules\Entitlements\Models\AddOnPrice;
use App\Modules\Entitlements\Models\EntitlementGrant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1, slice 1 — the add-on catalog, wired to nothing.
 *
 * Nothing reads these tables yet, so the deliverable is exactly this: the schema
 * stands up, the constraints hold, and the two hand-written rules fire. There is
 * no behaviour to test because there is deliberately no behaviour.
 *
 * The rules that carry weight and are NOT expressible in the schema:
 *
 *   1. an `entitlement_grants` row has EXACTLY ONE owner — client or partner;
 *   2. a partner in `ceiling` mode has at least one active grant (R-1).
 *
 * Both are enforced in model hooks, so both are asserted here in both
 * directions — the refusal AND the legitimate case that must still succeed.
 */
class AddOnCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    // ══ The tables exist and the rows create ═══════════════════════════════

    #[Test]
    public function every_catalog_table_exists(): void
    {
        foreach (['add_ons', 'add_on_grants', 'add_on_prices', 'plan_add_on', 'entitlement_grants'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumn('partners', 'entitlement_mode'));
    }

    #[Test]
    public function an_add_on_creates_with_a_uuid_and_slug_and_routes_by_uuid(): void
    {
        $addOn = AddOn::create(['name' => 'Extra Messages Pack', 'type' => AddOn::TYPE_PACK]);

        $this->assertNotEmpty($addOn->uuid);
        $this->assertSame('extra-messages-pack', $addOn->slug);
        $this->assertSame('uuid', $addOn->getRouteKeyName(),
            'A sequential catalog id in a URL enumerates the product line.');
    }

    #[Test]
    public function an_add_on_carries_grants_prices_and_plans(): void
    {
        $addOn = AddOn::factory()->package(20)->create();

        AddOnGrant::factory()->for($addOn)->create(['key' => 'campaigns_per_month', 'value' => 50]);
        AddOnGrant::factory()->for($addOn)->gauge('chatbots', 3)->create();
        AddOnPrice::factory()->for($addOn)->create(['price_cents' => 4900]);

        $plan = Plan::factory()->create();
        $plan->addOns()->attach($addOn->id, ['quantity' => 1]);

        $addOn->refresh();

        $this->assertCount(2, $addOn->grants);
        $this->assertCount(1, $addOn->prices);
        $this->assertSame([$plan->id], $addOn->plans->pluck('id')->all());
        $this->assertSame(1, (int) $addOn->plans->first()->pivot->quantity);
    }

    // ══ Dominant vs additive is DATA, not a code branch ════════════════════

    /**
     * The type column is CLAUDE.md rule 5 expressed as data. This test does not
     * exercise the resolver — it does not exist yet — but it pins that the three
     * types are distinguishable on the row, which is what lets the resolver be a
     * single fold instead of a pile of feature-specific branches.
     */
    #[Test]
    public function the_three_add_on_types_are_distinguishable_on_the_row(): void
    {
        $package = AddOn::factory()->package(30)->create();
        $pack = AddOn::factory()->pack()->create();
        $feature = AddOn::factory()->feature()->create();

        $this->assertTrue($package->isDominant(), 'A package is never summed — highest rank wins.');
        $this->assertFalse($package->isAdditive());

        $this->assertTrue($pack->isAdditive(), 'Only explicit packs and credits are additive.');
        $this->assertFalse($pack->isDominant());

        $this->assertFalse($feature->isDominant());
        $this->assertFalse($feature->isAdditive());

        $this->assertSame(30, $package->rank);
        $this->assertSame(0, $pack->rank, 'Rank is meaningless off a package and must not imply a comparison.');
    }

    /** NULL value = unlimited, matching plans.limits' existing convention. */
    #[Test]
    public function a_null_grant_value_means_unlimited(): void
    {
        $unlimited = AddOnGrant::factory()->unlimited()->create();
        $bounded = AddOnGrant::factory()->create(['value' => 10]);

        $this->assertTrue($unlimited->isUnlimited());
        $this->assertFalse($bounded->isUnlimited());
    }

    // ══ Constraints ════════════════════════════════════════════════════════

    #[Test]
    public function an_add_on_cannot_grant_the_same_key_twice(): void
    {
        $addOn = AddOn::factory()->create();
        AddOnGrant::factory()->for($addOn)->create(['key' => 'campaigns_per_month']);

        $this->expectException(QueryException::class);
        AddOnGrant::factory()->for($addOn)->create(['key' => 'campaigns_per_month']);
    }

    /** POSITIVE CONTROL: the same key on a DIFFERENT add-on is normal. */
    #[Test]
    public function two_different_add_ons_may_grant_the_same_key(): void
    {
        $a = AddOn::factory()->create();
        $b = AddOn::factory()->create();

        AddOnGrant::factory()->for($a)->create(['key' => 'campaigns_per_month']);
        AddOnGrant::factory()->for($b)->create(['key' => 'campaigns_per_month']);

        $this->assertSame(2, AddOnGrant::where('key', 'campaigns_per_month')->count(),
            'The unique constraint must be composite. If this fails it is scoped to the key '
            .'alone, and a second add-on could never grant an existing key — which is the '
            .'BUG-019/BUG-020 shape.');
    }

    #[Test]
    public function an_add_on_cannot_be_priced_twice_for_one_currency_and_interval(): void
    {
        $addOn = AddOn::factory()->create();
        AddOnPrice::factory()->for($addOn)->create(['currency_code' => 'USD', 'interval' => 'month']);

        $this->expectException(QueryException::class);
        AddOnPrice::factory()->for($addOn)->create(['currency_code' => 'USD', 'interval' => 'month']);
    }

    /** POSITIVE CONTROL: another currency, or another interval, is normal. */
    #[Test]
    public function an_add_on_may_be_priced_per_currency_and_per_interval(): void
    {
        $addOn = AddOn::factory()->create();

        AddOnPrice::factory()->for($addOn)->create(['currency_code' => 'USD', 'interval' => 'month']);
        AddOnPrice::factory()->for($addOn)->create(['currency_code' => 'USD', 'interval' => 'year']);
        AddOnPrice::factory()->for($addOn)->create(['currency_code' => 'GBP', 'interval' => 'month']);

        $this->assertCount(3, $addOn->refresh()->prices);
    }

    #[Test]
    public function deleting_an_add_on_takes_its_grants_and_prices_with_it(): void
    {
        $addOn = AddOn::factory()->create();
        AddOnGrant::factory()->for($addOn)->create();
        AddOnPrice::factory()->for($addOn)->create();

        $addOn->delete();

        $this->assertSame(0, DB::table('add_on_grants')->count());
        $this->assertSame(0, DB::table('add_on_prices')->count());
    }

    /**
     * …but NOT while somebody holds it. `entitlement_grants.add_on_id` is
     * RESTRICT for the same reason `clients.partner_id` is: deleting a catalog
     * row out from under a paying customer is a business event, not a tidy-up.
     */
    #[Test]
    public function an_add_on_that_someone_holds_cannot_be_deleted(): void
    {
        $addOn = AddOn::factory()->create();
        EntitlementGrant::factory()->create(['add_on_id' => $addOn->id]);

        try {
            $addOn->delete();
            $this->fail('A held add-on was deleted. RESTRICT is not in force.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }

        $this->assertSame(1, DB::table('add_ons')->where('id', $addOn->id)->count());
    }

    // ══ ⚠️ EXACTLY ONE OWNER ═══════════════════════════════════════════════

    #[Test]
    public function a_grant_with_no_owner_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must belong to either a client or a partner/');

        EntitlementGrant::factory()->create(['client_id' => null, 'partner_id' => null]);
    }

    #[Test]
    public function a_grant_owned_by_both_a_client_and_a_partner_is_refused(): void
    {
        $client = Client::factory()->create();
        $partner = Partner::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot belong to both/');

        EntitlementGrant::factory()->create([
            'client_id' => $client->id,
            'partner_id' => $partner->id,
        ]);
    }

    /** POSITIVE CONTROL, both legitimate shapes. */
    #[Test]
    public function a_grant_owned_by_exactly_one_of_them_is_accepted(): void
    {
        $clientGrant = EntitlementGrant::factory()->create();
        $partnerGrant = EntitlementGrant::factory()->forPartner()->create();

        $this->assertSame('client', $clientGrant->ownerType());
        $this->assertSame('partner', $partnerGrant->ownerType());
        $this->assertSame(2, DB::table('entitlement_grants')->count(),
            'Both legitimate shapes must persist — otherwise the rule is refusing everyone, '
            .'which the two negative tests above could not tell apart from working.');
    }

    /**
     * ⚠️ The rule must also hold on UPDATE, not only on create.
     *
     * `saving` covers both, but that is an implementation detail easy to lose in
     * a refactor to `creating` — and an update is exactly how a valid row turns
     * into a two-owner one in practice.
     */
    #[Test]
    public function an_existing_grant_cannot_be_updated_into_having_two_owners(): void
    {
        $grant = EntitlementGrant::factory()->create();
        $partner = Partner::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $grant->update(['partner_id' => $partner->id]);
    }

    /**
     * The model refuses to WRITE such a row, but it cannot stop a seeder or a
     * raw insert. So `ownerType()` must answer honestly when it reads one back,
     * rather than guessing an owner that is not there.
     */
    #[Test]
    public function owner_type_reports_null_for_a_row_written_around_the_model(): void
    {
        $addOn = AddOn::factory()->create();

        DB::table('entitlement_grants')->insert([
            'uuid' => (string) Str::uuid(),
            'client_id' => null,
            'partner_id' => null,
            'add_on_id' => $addOn->id,
            'quantity' => 1,
            'status' => 'active',
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(EntitlementGrant::first()->ownerType(),
            'A row with no owner must read as having no owner. Defaulting to "client" here '
            .'would hand an orphan grant to whichever resolver asked first.');
    }

    // ══ Grants in force ════════════════════════════════════════════════════

    /**
     * `status` alone is not enough. A missed expiry job leaves a stale `active`
     * row granting entitlement forever — the same failure `User::activeSubscription()`
     * already guards against, and worth guarding the same way here.
     */
    #[Test]
    public function a_grant_is_in_force_only_when_active_and_within_its_dates(): void
    {
        $live = EntitlementGrant::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $notYet = EntitlementGrant::factory()->create(['starts_at' => now()->addDay()]);
        $lapsed = EntitlementGrant::factory()->create(['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);
        $cancelled = EntitlementGrant::factory()->create(['status' => EntitlementGrant::STATUS_CANCELLED]);

        $this->assertTrue($live->isInForce());
        $this->assertFalse($notYet->isInForce(), 'A future grant is not in force yet.');
        $this->assertFalse($lapsed->isInForce(),
            'A grant whose end date has passed must not be in force even while status says active — '
            .'that is exactly what a missed expiry job leaves behind.');
        $this->assertFalse($cancelled->isInForce());
    }

    // ══ R-1 — the partner ceiling tri-state ════════════════════════════════

    #[Test]
    public function a_partner_defaults_to_unrestricted(): void
    {
        $partner = Partner::factory()->create();

        $this->assertSame(Partner::MODE_UNRESTRICTED, $partner->entitlement_mode);
        $this->assertFalse($partner->hasCeiling(),
            'Existing partners must keep working. A ceiling nobody configured is not a ceiling.');
    }

    #[Test]
    public function a_partner_cannot_be_created_directly_in_ceiling_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be created directly in ceiling mode/');

        Partner::create(['name' => 'Premature Ceiling', 'entitlement_mode' => Partner::MODE_CEILING]);
    }

    /**
     * ⚠️ The rule that carries the weight of R-1.
     *
     * Without it, a dropdown could set `ceiling` on a partner with no grants —
     * which resolves to "may resell nothing", a total outage for every one of
     * that partner's customers, produced by a UI interaction nobody would think
     * of as dangerous.
     */
    #[Test]
    public function a_partner_cannot_switch_to_ceiling_mode_with_no_grants(): void
    {
        $partner = Partner::factory()->create();

        try {
            $partner->update(['entitlement_mode' => Partner::MODE_CEILING]);
            $this->fail('A partner was put into ceiling mode with an empty ceiling. Every one '
                .'of its customers would resolve to zero entitlement.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no active entitlement', $e->getMessage());
        }

        $this->assertSame(Partner::MODE_UNRESTRICTED, $partner->fresh()->entitlement_mode);
    }

    /** POSITIVE CONTROL: with a grant, the switch is allowed. */
    #[Test]
    public function a_partner_with_an_active_grant_can_switch_to_ceiling_mode(): void
    {
        $partner = Partner::factory()->create();
        EntitlementGrant::factory()->forPartner($partner)->create();

        $partner->update(['entitlement_mode' => Partner::MODE_CEILING]);

        $this->assertTrue($partner->fresh()->hasCeiling(),
            'The rule must block only the empty case. If this fails it blocks everyone, and '
            .'ceiling mode could never be turned on at all.');
    }

    /** An EXPIRED grant is not a ceiling either. */
    #[Test]
    public function an_expired_grant_does_not_qualify_a_partner_for_ceiling_mode(): void
    {
        $partner = Partner::factory()->create();
        EntitlementGrant::factory()->forPartner($partner)->expired()->create();

        $this->expectException(\InvalidArgumentException::class);

        $partner->update(['entitlement_mode' => Partner::MODE_CEILING]);
    }

    /** A CLIENT's grant must not qualify a partner. Different owners entirely. */
    #[Test]
    public function a_clients_grant_does_not_qualify_a_partner_for_ceiling_mode(): void
    {
        $partner = Partner::factory()->create();
        $client = Client::factory()->forPartner($partner)->create();
        EntitlementGrant::factory()->forClient($client)->create();

        $this->expectException(\InvalidArgumentException::class);

        $partner->update(['entitlement_mode' => Partner::MODE_CEILING]);
    }

    // ══ The scope must not reach this layer ════════════════════════════════

    /**
     * None of these tables carries `workspace_id`, so the Phase 0 coverage guard
     * cannot see any of them — it inventories tables that HAVE that column.
     * Asserted here for the same reason `PartnerTierTest` asserts it for
     * `Partner`: the models most likely to be wrongly scoped are exactly the
     * ones that guard is blind to.
     *
     * §A.5 of the Phase 0 plan classifies the whole catalog/pricing layer
     * (`Plan`, `Coupon`, `PaymentGatewayConfig`, `BillingEvent`) as
     * platform-global with no scope. This is that layer.
     */
    #[Test]
    public function no_catalog_model_is_workspace_scoped(): void
    {
        foreach ([AddOn::class, AddOnGrant::class, AddOnPrice::class, EntitlementGrant::class] as $model) {
            $this->assertNotContains(BelongsToWorkspace::class, class_uses_recursive($model),
                $model.' is platform-owned catalog and sits above the tenant boundary.');
        }

        foreach (['add_ons', 'add_on_grants', 'add_on_prices', 'plan_add_on', 'entitlement_grants'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'workspace_id'),
                "{$table} must not carry workspace_id: entitlements are bought by an "
                .'organisation and consumed by all of its workspaces.');
        }
    }
}
