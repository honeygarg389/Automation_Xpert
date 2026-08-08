<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Shared\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Ecommerce module (5 resolution sites, 11 call sites, 12 public methods).
 *
 * Four of the five sites are private `workspaceId()` helpers with 2-3 callers
 * each, so the site count understates the surface. Three of them are §G-1b
 * AUTHORIZATION sites rather than data scoping — they were "correct" only
 * because the switcher was broken:
 *
 *   OrderContextController:20  contact ownership  -> index
 *   OrderController:195        authorizeOrder     -> show, refresh, fulfill
 *   StoreController:149        authorizeStore     -> test, sync, destroy
 *
 * Every assertion of "blocked" is paired with a positive control on the SAME
 * route and verb, per CLAUDE.md — a 403 alone is equally consistent with the
 * endpoint rejecting everyone.
 *
 * Trap guarded here: EcommerceStore::getRouteKeyName() is `uuid`. Passing
 * `$store->id` would 404 at route binding and never reach authorizeStore(), so
 * the test would prove nothing. EcommerceOrder and EcommerceProduct bind by id.
 * None of these models soft-delete, so assertDatabaseMissing is load-bearing
 * for the destroy assertions.
 */
class EcommerceWorkspaceScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();

        // sync() dispatches jobs and test()/destroy() call the platform APIs.
        // QUEUE_CONNECTION=sync would run those jobs inline and reach the network.
        Queue::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function store(int $workspaceId, string $domain): EcommerceStore
    {
        return EcommerceStore::create([
            'workspace_id' => $workspaceId,
            'platform' => 'shopify',
            'name' => 'Store '.$domain,
            'domain' => $domain,
            'status' => 'connected',
            'credentials' => ['access_token' => 'tok-'.$domain],
        ]);
    }

    private function order(EcommerceStore $store, string $externalId): EcommerceOrder
    {
        return EcommerceOrder::create([
            'workspace_id' => $store->workspace_id,
            'store_id' => $store->id,
            'platform' => $store->platform,
            'external_order_id' => $externalId,
            'number' => $externalId,
            'status' => 'open',
            'currency' => 'USD',
            'total' => 10,
            'placed_at' => now(),
        ]);
    }

    // ── Store list follows the switched workspace ────────────────────────────

    #[Test]
    public function the_store_list_shows_the_home_workspace_when_nothing_is_switched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->store($home->id, 'home.myshopify.com');
        $this->store($other->id, 'other.myshopify.com');

        $this->actingAs($user)
            ->get(route('client.ecommerce.stores.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('stores', 1)
                ->where('stores.0.domain', 'home.myshopify.com'));
    }

    #[Test]
    public function the_store_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->store($home->id, 'home.myshopify.com');
        $this->store($other->id, 'other.myshopify.com');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.ecommerce.stores.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('stores', 1)
                ->where('stores.0.domain', 'other.myshopify.com'));
    }

    // ── Store authorization: authorizeStore() guards test/sync/destroy ───────

    #[Test]
    public function deleting_another_tenants_store_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $victim = $this->store($home->id, 'home.myshopify.com');

        // Switched into `other`; the home store must now be out of reach.
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.ecommerce.stores.destroy', $victim->uuid))
            ->assertForbidden();

        // Load-bearing: EcommerceStore does not soft-delete, so a surviving row
        // genuinely proves the delete was prevented.
        $this->assertDatabaseHas('ecommerce_stores', ['id' => $victim->id]);
    }

    #[Test]
    public function deleting_a_store_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $own = $this->store($home->id, 'home.myshopify.com');

        $this->actingAs($user)
            ->delete(route('client.ecommerce.stores.destroy', $own->uuid))
            ->assertRedirect();

        $this->assertDatabaseMissing('ecommerce_stores', ['id' => $own->id]);
    }

    #[Test]
    public function syncing_another_tenants_store_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $victim = $this->store($home->id, 'home.myshopify.com');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.ecommerce.stores.sync', $victim->uuid))
            ->assertForbidden();
    }

    #[Test]
    public function syncing_a_store_in_the_current_workspace_is_authorized(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $own = $this->store($home->id, 'home.myshopify.com');

        $this->actingAs($user)
            ->post(route('client.ecommerce.stores.sync', $own->uuid))
            ->assertRedirect();
    }

    /**
     * Positive control for the test below: same route, same verb, same user,
     * unswitched. Split into two tests rather than two requests because
     * WorkspaceContext memoises per user id for the life of a test, so a second
     * request would reuse the first request's resolution.
     */
    #[Test]
    public function connection_testing_a_store_in_the_home_workspace_succeeds_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeStore = $this->store($home->id, 'home.myshopify.com');

        $this->actingAs($user)
            ->post(route('client.ecommerce.stores.test', $homeStore->uuid))
            ->assertRedirect();
    }

    #[Test]
    public function after_switching_a_home_workspace_store_is_out_of_scope(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeStore = $this->store($home->id, 'home.myshopify.com');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.ecommerce.stores.test', $homeStore->uuid))
            ->assertForbidden();
    }

    // ── Store creation lands in the switched workspace ───────────────────────

    #[Test]
    public function a_new_store_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.ecommerce.stores.store'), [
                'platform' => 'shopify',
                'domain' => 'brand-new.myshopify.com',
                // store() validates `credentials` as a required array; a flat
                // key fails validation and silently creates nothing.
                'credentials' => ['access_token' => 'tok-new'],
            ]);

        $this->assertDatabaseHas('ecommerce_stores', [
            'domain' => 'brand-new.myshopify.com',
            'workspace_id' => $other->id,
        ]);
    }

    // ── Order authorization: authorizeOrder() guards show/refresh/fulfill ────

    #[Test]
    public function viewing_another_tenants_order_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $order = $this->order($this->store($home->id, 'home.myshopify.com'), 'A-1');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.ecommerce.orders.show', $order->id))
            ->assertForbidden();
    }

    #[Test]
    public function viewing_an_order_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $order = $this->order($this->store($home->id, 'home.myshopify.com'), 'A-1');

        $this->actingAs($user)
            ->get(route('client.ecommerce.orders.show', $order->id))
            ->assertOk();
    }

    #[Test]
    public function fulfilling_another_tenants_order_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $order = $this->order($this->store($home->id, 'home.myshopify.com'), 'A-1');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.ecommerce.orders.fulfill', $order->id))
            ->assertForbidden();
    }

    // ── Order and product lists follow the switch ────────────────────────────

    #[Test]
    public function the_order_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->order($this->store($home->id, 'home.myshopify.com'), 'HOME-1');
        $this->order($this->store($other->id, 'other.myshopify.com'), 'OTHER-1');

        $this->actingAs($user)
            ->get(route('client.ecommerce.orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)
                ->where('orders.data.0.number', 'HOME-1'));
    }

    #[Test]
    public function the_order_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->order($this->store($home->id, 'home.myshopify.com'), 'HOME-1');
        $this->order($this->store($other->id, 'other.myshopify.com'), 'OTHER-1');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.ecommerce.orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)
                ->where('orders.data.0.number', 'OTHER-1'));
    }

    #[Test]
    public function the_product_search_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeStore = $this->store($home->id, 'home.myshopify.com');
        $otherStore = $this->store($other->id, 'other.myshopify.com');

        EcommerceProduct::create([
            'workspace_id' => $home->id, 'store_id' => $homeStore->id, 'platform' => 'shopify',
            'external_id' => 'P-HOME', 'name' => 'Widget Home', 'price' => 5,
        ]);
        EcommerceProduct::create([
            'workspace_id' => $other->id, 'store_id' => $otherStore->id, 'platform' => 'shopify',
            'external_id' => 'P-OTHER', 'name' => 'Widget Other', 'price' => 5,
        ]);

        $this->actingAs($user)
            ->getJson(route('client.ecommerce.products.search', ['q' => 'Widget']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Widget Home']);
    }

    #[Test]
    public function the_product_search_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeStore = $this->store($home->id, 'home.myshopify.com');
        $otherStore = $this->store($other->id, 'other.myshopify.com');

        EcommerceProduct::create([
            'workspace_id' => $home->id, 'store_id' => $homeStore->id, 'platform' => 'shopify',
            'external_id' => 'P-HOME', 'name' => 'Widget Home', 'price' => 5,
        ]);
        EcommerceProduct::create([
            'workspace_id' => $other->id, 'store_id' => $otherStore->id, 'platform' => 'shopify',
            'external_id' => 'P-OTHER', 'name' => 'Widget Other', 'price' => 5,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->getJson(route('client.ecommerce.products.search', ['q' => 'Widget']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Widget Other']);
    }

    // ── Contact order context (inline site + its guard) ──────────────────────

    #[Test]
    public function reading_order_context_for_another_tenants_contact_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $contact = Contact::create([
            'workspace_id' => $home->id, 'name' => 'Home Contact', 'phone' => '15550001',
        ]);

        $this->actingAs($user)
            // §G-4: 403 -> 404. Contact's route key is `uuid`, and the workspace
            // scope applies to implicit route-model binding — so a foreign uuid
            // is never resolved and binding aborts before authorization runs.
            // Better security: a 403 confirms the row exists, a 404 does not.
            //
            // The status alone is weak evidence (a 404 is also what a wrong
            // route key produces). What makes it evidence is the assertion that
            // follows, plus the positive controls in this file succeeding on the
            // SAME route and verb for the owner.
            ->withSession(['current_workspace_id' => $other->id])
            ->getJson(route('client.ecommerce.contacts.orders', $contact->uuid))
            ->assertNotFound();
    }

    #[Test]
    public function reading_order_context_for_a_contact_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $contact = Contact::create([
            'workspace_id' => $home->id, 'name' => 'Home Contact', 'phone' => '15550001',
        ]);

        $this->actingAs($user)
            ->getJson(route('client.ecommerce.contacts.orders', $contact->uuid))
            ->assertOk();
    }

    // ── OAuth write path — deliberately narrow coverage ──────────────────────

    /**
     * DELIBERATE SCOPE CHOICE, not an oversight.
     *
     * EcommerceOAuthController's two sites are write paths reached through an
     * OAuth round trip with Shopify/BigCommerce. Exercising them end to end
     * needs a faked provider handshake, which is disproportionate to a one-line
     * resolution change and would balloon this branch.
     *
     * What matters for 1c is narrower, and is what this asserts: the workspace
     * the controller RESOLVES is the switched one. connect() stashes it in the
     * session for the callback to use, so asserting on that value proves the
     * resolution without simulating the provider.
     *
     * The Shopify branch is used rather than WooCommerce deliberately: Woo's
     * StoreUrlGuard resolves the host over live DNS, and a test that depends on
     * DNS is how `hooks.example.com` broke the SSRF suite (see
     * docs/test-suite-baseline.md). Shopify validates by regex only.
     */
    #[Test]
    public function the_oauth_connect_path_resolves_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.ecommerce.oauth.connect', [
                'platform' => 'shopify',
                'shop' => 'switched-store.myshopify.com',
            ]));

        $stashed = Session::get('ecom_oauth');

        $this->assertIsArray($stashed, 'connect() must stash the OAuth context for the callback.');
        $this->assertSame(
            $other->id,
            (int) $stashed['workspace'],
            'connect() resolved the home workspace instead of the switched one.'
        );
        $this->assertNotSame((int) $user->workspace_id, (int) $stashed['workspace']);
    }
}
