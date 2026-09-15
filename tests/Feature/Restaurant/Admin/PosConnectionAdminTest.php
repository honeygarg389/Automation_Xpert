<?php

namespace Tests\Feature\Restaurant\Admin;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Restaurant\Models\LegalAcceptance;
use App\Modules\Restaurant\Models\LegalDocumentVersion;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\LegalDocumentPublishingService;
use App\Modules\Restaurant\Services\PosConnectionProvisioningService;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1C — Super Admin Restaurant Integrations UI: access control, the
 * create flow, and the guarantee that no screen this phase builds ever
 * exposes a plaintext token, a token hash, or raw payload/customer data.
 *
 * Phase 2A Slice 1 adds: an explicit environment choice at creation, the
 * live activation path (gated on the real, already-persisted restaurant
 * declaration acceptance — see acceptRestaurantDeclarationFor()), and the
 * default phone country field/endpoint.
 */
class PosConnectionAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $permissionKeys */
    private function adminWith(array $permissionKeys): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'TEST_ROLE_'.uniqid(), 'name' => 'Test Role', 'description' => 'test']);

        foreach ($permissionKeys as $key) {
            $perm = Permission::firstOrCreate(['key' => $key], ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'test']);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    private function outlet(): RestaurantOutlet
    {
        return RestaurantOutlet::factory()->create();
    }

    private function service(): PosConnectionProvisioningService
    {
        return app(PosConnectionProvisioningService::class);
    }

    /**
     * Same helpers as PosConnectionProvisioningServiceTest — records a
     * CURRENT acceptance of $documentType for $workspaceId via the real
     * LegalDocumentVersion/LegalAcceptance models and
     * LegalDocumentPublishingService, not a shortcut.
     */
    private function acceptLegalDocumentFor(int $workspaceId, string $documentType): LegalAcceptance
    {
        $version = LegalDocumentVersion::factory()->create(['document_type' => $documentType]);
        app(LegalDocumentPublishingService::class)->publish($version);

        return LegalAcceptance::create([
            'workspace_id' => $workspaceId,
            'document_type' => $documentType,
            'legal_document_version_id' => $version->id,
            'document_version' => $version->version,
            'content_sha256' => $version->content_sha256,
            'accepted_by_user_id' => User::factory()->create()->id,
            'accepted_at' => now(),
        ]);
    }

    private function acceptTermsFor(int $workspaceId): LegalAcceptance
    {
        return $this->acceptLegalDocumentFor($workspaceId, LegalDocumentVersion::TYPE_TERMS);
    }

    private function acceptDpaFor(int $workspaceId): LegalAcceptance
    {
        return $this->acceptLegalDocumentFor($workspaceId, LegalDocumentVersion::TYPE_DPA);
    }

    private function acceptRestaurantDeclarationFor(int $workspaceId): LegalAcceptance
    {
        return $this->acceptLegalDocumentFor($workspaceId, LegalDocumentVersion::TYPE_RESTAURANT_DECLARATION);
    }

    private function connectWabaFor(int $workspaceId): WhatsappBusinessAccount
    {
        return WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspaceId,
            'status' => 'active',
        ]);
    }

    private function authorizeOutletForLivePos(RestaurantOutlet $outlet): RestaurantOutlet
    {
        return app(RestaurantOutletService::class)->authorizeForLivePos($outlet);
    }

    /**
     * Satisfies every one of the five compliance/authorization gates
     * activateLive() checks (gate 6, the configured token, is set up
     * separately by every caller via the token endpoint/service call) except
     * whichever gate keys are named in $except — same isolation shape as
     * PosConnectionProvisioningServiceTest's helper of the same name.
     *
     * @param  list<'terms'|'dpa'|'restaurant_declaration'|'connected_waba'|'outlet_authorization'>  $except
     */
    private function satisfyAllLiveActivationGatesExcept(RestaurantOutlet $outlet, array $except = []): void
    {
        if (! in_array('terms', $except, true)) {
            $this->acceptTermsFor($outlet->workspace_id);
        }
        if (! in_array('dpa', $except, true)) {
            $this->acceptDpaFor($outlet->workspace_id);
        }
        if (! in_array('restaurant_declaration', $except, true)) {
            $this->acceptRestaurantDeclarationFor($outlet->workspace_id);
        }
        if (! in_array('connected_waba', $except, true)) {
            $this->connectWabaFor($outlet->workspace_id);
        }
        if (! in_array('outlet_authorization', $except, true)) {
            $this->authorizeOutletForLivePos($outlet);
        }
    }

    // ══ Access control ═════════════════════════════════════════════════

    #[Test]
    public function super_admin_can_access_list_create_and_detail_screens(): void
    {
        $admin = $this->adminWith(['view_pos_connections', 'manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-ACCESS-1', null);

        $this->actingAs($admin, 'admin')->get(route('admin.restaurant.connections.index'))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.restaurant.connections.create'))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.restaurant.connections.show', $connection))->assertOk();
    }

    #[Test]
    public function an_admin_without_the_permission_is_redirected_away_with_an_error(): void
    {
        $admin = $this->adminWith([]); // no permissions at all

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.connections.index'))
            ->assertRedirect(route('admin.dashboard'));
    }

    #[Test]
    public function a_client_user_on_the_web_guard_cannot_reach_any_admin_restaurant_route(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $outlet = $this->outlet();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-ACCESS-2', null);

        // The default (web) guard, never the admin guard — a restaurant/client
        // user simply has no session on the guard these routes require.
        $this->actingAs($user)->get(route('admin.restaurant.connections.index'))->assertRedirect();
        $this->actingAs($user)->get(route('admin.restaurant.connections.show', $connection))->assertRedirect();
        $this->actingAs($user)->postJson(route('admin.restaurant.connections.token', $connection))->assertUnauthorized();
    }

    #[Test]
    public function token_endpoints_are_denied_by_json_for_an_admin_lacking_the_specific_permission(): void
    {
        // Has view/manage, but NOT rotate_pos_webhook_secret or activate_pos_connections —
        // the separate, more consequential permissions this module deliberately splits out.
        $admin = $this->adminWith(['view_pos_connections', 'manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-ACCESS-3', null);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.connections.token', $connection))
            ->assertForbidden();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.connections.activate', $connection))
            ->assertForbidden();

        // Positive control: the SAME routes succeed for an admin who does hold them.
        $rotator = $this->adminWith(['rotate_pos_webhook_secret']);
        $this->actingAs($rotator, 'admin')
            ->postJson(route('admin.restaurant.connections.token', $connection))
            ->assertOk();
    }

    // ══ Creation ════════════════════════════════════════════════════════

    #[Test]
    public function creating_a_connection_with_an_existing_outlet_links_workspace_outlet_and_starts_sandbox_pending_with_no_token(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-NEW-1',
        ]);

        $response->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-NEW-1')->firstOrFail();
        $this->assertSame($outlet->id, $connection->outlet_id);
        $this->assertSame($outlet->workspace_id, $connection->workspace_id);
        $this->assertSame(PosConnection::ENVIRONMENT_SANDBOX, $connection->environment);
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->status);
        $this->assertNull($connection->webhook_secret_hash);

        $response->assertRedirect(route('admin.restaurant.connections.show', $connection));
    }

    #[Test]
    public function creating_a_connection_with_a_new_outlet_creates_and_links_the_outlet(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'new',
            'workspace_id' => $workspace->id,
            'new_outlet_name' => 'Brand New Outlet',
            'new_outlet_address' => '123 Test Street',
            'external_ref' => 'REST-NEW-2',
        ])->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-NEW-2')->firstOrFail();
        $this->assertNotNull($connection->outlet_id);
        $this->assertSame('Brand New Outlet', $connection->outlet->name);
        $this->assertSame($workspace->id, $connection->outlet->workspace_id);
    }

    /**
     * ⚠️ THE MEASURED DEFECT, PINNED AT THE HTTP LAYER: a request carrying a
     * stale outlet_id (as a real Inertia form would after switching radios,
     * before this phase's frontend fix) together with mode=new must still
     * create the FRESHLY-TYPED outlet — never silently reuse the stale
     * outlet_id. The server-side `mode` field is the whole fix; this proves
     * it holds even when the client sends contradictory fields.
     */
    #[Test]
    public function mode_new_ignores_a_stale_outlet_id_and_creates_the_freshly_typed_outlet(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $staleOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Food Court']);

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'new',
            'workspace_id' => $workspace->id,
            'outlet_id' => $staleOutlet->id, // stale, must be ignored entirely
            'new_outlet_name' => 'Burger King',
            'external_ref' => 'REST-STALE-1',
        ])->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-STALE-1')->firstOrFail();
        $this->assertSame('Burger King', $connection->outlet->name);
        $this->assertNotSame($staleOutlet->id, $connection->outlet_id);
        $this->assertSame(0, $staleOutlet->posConnections()->count(),
            'The stale outlet_id must never receive the new connection.');
    }

    /** The mirror case: a stale new_outlet_name must never spawn a phantom outlet when mode=existing. */
    #[Test]
    public function mode_existing_ignores_a_stale_new_outlet_name_and_links_the_selected_outlet(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'new_outlet_name' => 'Phantom Outlet', // stale, must be ignored entirely
            'external_ref' => 'REST-STALE-2',
        ])->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-STALE-2')->firstOrFail();
        $this->assertSame($outlet->id, $connection->outlet_id);
        $this->assertSame(0, RestaurantOutlet::query()->where('name', 'Phantom Outlet')->count(),
            'A stale new_outlet_name must never create an outlet when mode=existing.');
    }

    /**
     * The server-side twin of the create form's eligible-dropdown filter:
     * even if a client bypasses the UI and posts the outlet_id of an
     * already-connected outlet directly, validation must still reject it.
     */
    #[Test]
    public function an_outlet_that_already_has_a_non_archived_connection_cannot_be_selected_in_existing_mode(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();
        $this->service()->createSandboxConnection($outlet, 'REST-ALREADY-1', null);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-ALREADY-2',
        ]);

        $response->assertSessionHasErrors('outlet_id');
        $this->assertSame(1, $outlet->posConnections()->count(),
            'The second attempt must not have created a connection.');
    }

    #[Test]
    public function an_outlet_belonging_to_a_different_workspace_is_rejected(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();
        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $otherWorkspace->id,
            'outlet_id' => $outlet->id, // belongs to a DIFFERENT workspace
            'external_ref' => 'REST-MISMATCH',
        ])->assertSessionHasErrors('outlet_id');

        $this->assertSame(0, PosConnection::query()->where('external_ref', 'REST-MISMATCH')->count());
    }

    /**
     * ⚠️ THE FRIENDLY-ERROR PROOF: a duplicate restID must fail VALIDATION,
     * never bubble up as a raw database UNIQUE-constraint exception. Tested
     * across two DIFFERENT workspaces, since the constraint is GLOBAL.
     */
    #[Test]
    public function a_duplicate_restid_is_rejected_gracefully_across_workspaces(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $firstOutlet = $this->outlet();
        $this->service()->createSandboxConnection($firstOutlet, 'REST-DUPLICATE', null);

        $secondOutlet = $this->outlet();
        $this->assertNotSame($firstOutlet->workspace_id, $secondOutlet->workspace_id);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $secondOutlet->workspace_id,
            'outlet_id' => $secondOutlet->id,
            'external_ref' => 'REST-DUPLICATE',
        ]);

        $response->assertSessionHasErrors('external_ref');
        $this->assertSame(1, PosConnection::query()->where('external_ref', 'REST-DUPLICATE')->count(),
            'The duplicate attempt must not have created a second row.');
    }

    // ══ Token generation / rotation — the one-time-reveal guarantees ═════

    #[Test]
    public function generating_a_token_returns_plaintext_only_in_the_immediate_response_and_stores_only_the_hash(): void
    {
        $admin = $this->adminWith(['rotate_pos_webhook_secret']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-TOKEN-1', null);

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.connections.token', $connection));

        $response->assertOk();
        // The session middleware's own no-cache header is also present
        // (max-age=0, must-revalidate, no-cache, private) — the requirement
        // is that `no-store` is among the directives, not that our header
        // call was the only contributor to the final value.
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $plaintext = $response->json('token');
        $this->assertNotEmpty($plaintext);

        $fresh = $connection->fresh();
        $this->assertNotNull($fresh->webhook_secret_hash);
        $this->assertSame(hash('sha256', $plaintext), $fresh->webhook_secret_hash);
        $this->assertTrue(PosConnection::verifyToken($fresh, $plaintext));

        // Never in session/flash.
        $this->assertStringNotContainsString($plaintext, json_encode(session()->all()));
    }

    #[Test]
    public function the_plaintext_token_is_not_present_anywhere_in_the_detail_pages_inertia_response(): void
    {
        $admin = $this->adminWith(['view_pos_connections', 'rotate_pos_webhook_secret']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-TOKEN-2', null);

        $plaintext = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.connections.token', $connection))
            ->json('token');

        // A fresh page load (the normal GET a refresh/revisit would perform).
        // Inspected via assertInertia's own prop payload (toArray()), not raw
        // HTML string matching — the data-page attribute JSON-escapes
        // forward slashes, which would make a naive assertSee/assertDontSee
        // substring check unreliable in either direction.
        $hash = $connection->fresh()->webhook_secret_hash;

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.connections.show', $connection))
            ->assertOk()
            ->assertInertia(function ($page) use ($plaintext, $hash) {
                $encoded = json_encode($page->toArray());
                $this->assertStringNotContainsString($plaintext, $encoded);
                $this->assertStringNotContainsString($hash, $encoded);
                $page->where('connection.token_configured', true);
            });
    }

    #[Test]
    public function rotating_invalidates_the_old_token_and_the_audit_trail_carries_no_secret(): void
    {
        $admin = $this->adminWith(['rotate_pos_webhook_secret']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-TOKEN-3', null);

        $oldToken = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.connections.token', $connection))
            ->json('token');

        $newToken = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.connections.token', $connection))
            ->json('token');

        $this->assertNotSame($oldToken, $newToken);
        $fresh = $connection->fresh();
        $this->assertFalse(PosConnection::verifyToken($fresh, $oldToken));
        $this->assertTrue(PosConnection::verifyToken($fresh, $newToken));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.token_rotated',
            'auditable_id' => $connection->id,
        ]);
        $log = DB::table('audit_logs')
            ->where('action', 'restaurant.pos_connection.token_rotated')
            ->where('auditable_id', $connection->id)
            ->first();
        $this->assertStringNotContainsString($newToken, (string) $log->meta);
        $this->assertStringNotContainsString($fresh->webhook_secret_hash, (string) $log->meta);
    }

    // ══ Sandbox activation ════════════════════════════════════════════

    #[Test]
    public function activation_requires_a_configured_token(): void
    {
        $admin = $this->adminWith(['activate_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-ACTIVATE-1', null);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection))
            ->assertRedirect();

        $this->assertSame(PosConnection::STATUS_PENDING, $connection->fresh()->status);
    }

    #[Test]
    public function activation_succeeds_once_a_token_is_configured(): void
    {
        $admin = $this->adminWith(['rotate_pos_webhook_secret', 'activate_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-ACTIVATE-2', null);
        $this->actingAs($admin, 'admin')->postJson(route('admin.restaurant.connections.token', $connection));

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection->fresh()));

        $response->assertRedirect();
        $this->assertSame(PosConnection::STATUS_CONNECTED, $connection->fresh()->status);

        // ⚠️ Petpooja Phase 2A visual-review fix: this message used to read
        // "Petpooja sandbox/test deliveries can now reach this connection" —
        // false, since Petpooja provides no sandbox at all and never delivers
        // anything to a test/sandbox connection. Pins BOTH the removal of the
        // false claim and the accurate replacement wording, so a future edit
        // cannot silently reintroduce a "Petpooja ... sandbox" phrase here.
        $response->assertSessionHas('success', 'AutomationXpert test ingress is activated. Use internal test requests only; Petpooja does not provide a sandbox environment.');
        $this->assertStringNotContainsString('Petpooja sandbox', (string) session('success'),
            'The message must never claim Petpooja itself operates or delivers to a sandbox — it provides none.');
        $this->assertStringNotContainsString('Petpooja deliveries', (string) session('success'),
            'The message must never claim this test/sandbox connection receives Petpooja deliveries — only a live connection does.');
    }

    /**
     * The live counterpart's positive control: a real Petpooja delivery
     * claim is accurate ONLY for a live connection, so this message is left
     * unchanged by the sandbox-copy fix above and must keep mentioning
     * Petpooja by name.
     */
    #[Test]
    public function live_activation_success_message_still_accurately_names_petpooja(): void
    {
        $admin = $this->adminWith(['activate_pos_connections']);
        $outlet = $this->outlet();
        $connection = app(PosConnectionProvisioningService::class)
            ->createLiveConnection($outlet, 'REST-LIVE-COPY-1', null, null);
        app(PosConnectionProvisioningService::class)->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection->fresh()));

        $response->assertSessionHas('success', 'Live ingress activated. Petpooja deliveries can now reach this connection.');
    }

    /**
     * ⚠️ Phase 2A Slice 1 CORRECTION: this test used to be named
     * "a_production_connection_cannot_be_activated_through_this_action" and
     * pinned a blanket "production is categorically unsupported" claim. That
     * claim is now false — a live connection CAN activate (see the positive
     * control below). What this scenario actually proves, unchanged at the
     * HTTP-observable level (redirect, status stays PENDING), is that the
     * REAL compliance gate still blocks it when unsatisfied. Renamed and the
     * assertion sharpened to check the SPECIFIC reason, not just "it
     * failed" — the old assertion would have passed just as well if
     * activation had been refused for a completely wrong reason.
     */
    /**
     * Gate 3 isolated at the HTTP level: terms and DPA are satisfied for
     * this connection's (factory-generated) workspace, restaurant
     * declaration deliberately is not — so this can only be failing on the
     * gate the test names, not an accident of gates 1/2 also being
     * unsatisfied. outlet_id is null on this raw factory row (no outlet
     * relationship default), which is fine: gates 4/5 are never reached
     * because gate 3 blocks first.
     */
    #[Test]
    public function a_live_connection_without_an_accepted_restaurant_declaration_cannot_be_activated(): void
    {
        $admin = $this->adminWith(['activate_pos_connections']);
        $connection = PosConnection::factory()->create(['environment' => PosConnection::ENVIRONMENT_PRODUCTION]);
        $connection->forceFill(['webhook_secret_hash' => hash('sha256', 'x')])->save();
        $this->acceptTermsFor($connection->workspace_id);
        $this->acceptDpaFor($connection->workspace_id);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('restaurant declaration', session('error'));
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->fresh()->status,
            'A live connection must never be flipped to connected while its workspace has not accepted the current restaurant declaration.');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.live_activation_blocked',
            'auditable_id' => $connection->id,
        ]);
    }

    /**
     * The positive control alongside the refusal above (per CLAUDE.md's
     * "every is-blocked test needs a positive control" convention): the
     * SAME route, SAME connection shape, succeeds once every one of the six
     * gates — terms, DPA, restaurant declaration, a connected WABA, outlet
     * authorization, and a configured token — is genuinely satisfied. This
     * is what proves the gate-hardening pass actually enforces the complete
     * invariant, not merely one gate among several that happen to exist.
     */
    #[Test]
    public function a_live_connection_activates_once_every_gate_is_satisfied(): void
    {
        $admin = $this->adminWith(['activate_pos_connections']);
        $outlet = $this->outlet();
        $connection = app(PosConnectionProvisioningService::class)
            ->createLiveConnection($outlet, 'REST-LIVE-HTTP-1', null, null);
        app(PosConnectionProvisioningService::class)->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection->fresh()));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(PosConnection::STATUS_CONNECTED, $connection->fresh()->status);
    }

    /**
     * Each remaining gate isolated at the HTTP level — the operator-facing
     * error message must name the blocked reason, and activation must stay
     * refused, whichever single gate is left unsatisfied.
     */
    #[Test]
    public function a_live_connection_without_accepted_terms_cannot_be_activated(): void
    {
        $admin = $this->adminWith(['activate_pos_connections']);
        $outlet = $this->outlet();
        $connection = app(PosConnectionProvisioningService::class)
            ->createLiveConnection($outlet, 'REST-LIVE-HTTP-TERMS', null, null);
        app(PosConnectionProvisioningService::class)->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['terms']);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection->fresh()));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Terms', session('error'));
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->fresh()->status);
    }

    #[Test]
    public function a_live_connection_without_accepted_dpa_cannot_be_activated(): void
    {
        $admin = $this->adminWith(['activate_pos_connections']);
        $outlet = $this->outlet();
        $connection = app(PosConnectionProvisioningService::class)
            ->createLiveConnection($outlet, 'REST-LIVE-HTTP-DPA', null, null);
        app(PosConnectionProvisioningService::class)->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['dpa']);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection->fresh()));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Data Processing Agreement', session('error'));
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->fresh()->status);
    }

    #[Test]
    public function a_live_connection_with_no_connected_waba_cannot_be_activated(): void
    {
        $admin = $this->adminWith(['activate_pos_connections']);
        $outlet = $this->outlet();
        $connection = app(PosConnectionProvisioningService::class)
            ->createLiveConnection($outlet, 'REST-LIVE-HTTP-WABA', null, null);
        app(PosConnectionProvisioningService::class)->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['connected_waba']);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection->fresh()));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('WhatsApp Business Account', session('error'));
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->fresh()->status);
    }

    #[Test]
    public function a_live_connection_on_an_unauthorized_outlet_cannot_be_activated(): void
    {
        $admin = $this->adminWith(['activate_pos_connections']);
        $outlet = $this->outlet();
        $connection = app(PosConnectionProvisioningService::class)
            ->createLiveConnection($outlet, 'REST-LIVE-HTTP-OUTLET', null, null);
        app(PosConnectionProvisioningService::class)->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['outlet_authorization']);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection->fresh()));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('outlet has not been authorized', session('error'));
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->fresh()->status);
    }

    /**
     * Authorizing an outlet is a real admin action reachable through its own
     * route, not a database-only workaround — this proves the route itself
     * is what unblocks Gate 5, end to end via HTTP.
     */
    #[Test]
    public function authorizing_an_outlet_for_live_pos_through_its_admin_route_unblocks_gate_5(): void
    {
        $admin = $this->adminWith(['authorize_pos_outlets', 'activate_pos_connections']);
        $outlet = $this->outlet();
        $connection = app(PosConnectionProvisioningService::class)
            ->createLiveConnection($outlet, 'REST-LIVE-HTTP-AUTHZ', null, null);
        app(PosConnectionProvisioningService::class)->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['outlet_authorization']);
        $this->assertFalse($outlet->fresh()->isAuthorizedForLivePos());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.outlets.authorize-live-pos', $outlet))
            ->assertRedirect();

        $this->assertTrue($outlet->fresh()->isAuthorizedForLivePos());

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection->fresh()));

        $response->assertSessionHasNoErrors();
        $this->assertSame(PosConnection::STATUS_CONNECTED, $connection->fresh()->status);
    }

    /** A permission-gated route: manage_pos_connections alone must not be enough for this consequential act. */
    #[Test]
    public function authorizing_an_outlet_for_live_pos_requires_its_own_permission(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.outlets.authorize-live-pos', $outlet))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertFalse($outlet->fresh()->isAuthorizedForLivePos());
    }

    // ══ Phase 2A Slice 1 — live connection creation via HTTP ═════════════

    #[Test]
    public function creating_a_live_connection_requires_an_explicit_environment_choice(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-NOENV-1',
            // 'environment' intentionally omitted.
        ]);

        $response->assertSessionHasErrors('environment');
        $this->assertSame(0, PosConnection::query()->where('external_ref', 'REST-NOENV-1')->count());
    }

    #[Test]
    public function creating_a_connection_with_environment_production_creates_a_pending_live_connection(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'production',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-LIVE-HTTP-2',
            'default_phone_country' => 'IN',
        ])->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-LIVE-HTTP-2')->firstOrFail();
        $this->assertSame(PosConnection::ENVIRONMENT_PRODUCTION, $connection->environment);
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->status,
            'A live connection must not become connected merely because it was created.');
        $this->assertNull($connection->webhook_secret_hash);
        $this->assertSame('IN', $connection->default_phone_country);
    }

    // ══ Phase 2A Slice 1 — default phone country ═════════════════════════

    #[Test]
    public function default_phone_country_is_nullable_and_not_selected_when_omitted(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-NOCOUNTRY-1',
            // 'default_phone_country' intentionally omitted.
        ])->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-NOCOUNTRY-1')->firstOrFail();
        $this->assertNull($connection->default_phone_country,
            'Omitting the field must leave it unset — never a guessed default, not even India.');
    }

    #[Test]
    public function an_explicit_valid_default_phone_country_persists(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-COUNTRY-1',
            'default_phone_country' => 'GB',
        ])->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-COUNTRY-1')->firstOrFail();
        $this->assertSame('GB', $connection->default_phone_country);
    }

    #[Test]
    public function an_invalid_default_phone_country_is_rejected(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-COUNTRY-BAD',
            'default_phone_country' => 'ZZ', // not a real ISO 3166-1 alpha-2 code
        ]);

        $response->assertSessionHasErrors('default_phone_country');
        $this->assertSame(0, PosConnection::query()->where('external_ref', 'REST-COUNTRY-BAD')->count());
    }

    #[Test]
    public function updating_the_default_phone_country_on_the_detail_page_persists_and_is_audited(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-COUNTRY-UPDATE-1', null);

        $this->actingAs($admin, 'admin')->put(
            route('admin.restaurant.connections.default-phone-country', $connection),
            ['default_phone_country' => 'US'],
        )->assertSessionHasNoErrors();

        $this->assertSame('US', $connection->fresh()->default_phone_country);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.default_phone_country_changed',
            'auditable_id' => $connection->id,
        ]);
    }

    #[Test]
    public function updating_the_default_phone_country_can_clear_it_back_to_unset(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-COUNTRY-UPDATE-2', null);
        app(PosConnectionProvisioningService::class)->updateDefaultPhoneCountry($connection, 'IN');

        $this->actingAs($admin, 'admin')->put(
            route('admin.restaurant.connections.default-phone-country', $connection),
            ['default_phone_country' => ''],
        )->assertSessionHasNoErrors();

        $this->assertNull($connection->fresh()->default_phone_country);
    }

    #[Test]
    public function an_admin_without_manage_permission_cannot_update_the_default_phone_country(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-COUNTRY-UPDATE-3', null);

        $this->actingAs($admin, 'admin')->putJson(
            route('admin.restaurant.connections.default-phone-country', $connection),
            ['default_phone_country' => 'IN'],
        )->assertForbidden();

        $this->assertNull($connection->fresh()->default_phone_country);
    }

    // ══ Phase 2A Slice 1 — no outbound side effects ══════════════════════

    /**
     * The scope exclusion made explicit: creating and activating a LIVE
     * connection through this slice must not dispatch a queue job, a
     * WhatsApp/SMS/email send, or anything else — this slice is
     * provisioning and activation only.
     */
    #[Test]
    public function creating_and_activating_a_live_connection_dispatches_no_queue_jobs(): void
    {
        Queue::fake();
        Bus::fake();

        $admin = $this->adminWith(['manage_pos_connections', 'rotate_pos_webhook_secret', 'activate_pos_connections']);
        $outlet = $this->outlet();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'production',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-NOJOBS-1',
        ])->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-NOJOBS-1')->firstOrFail();
        $this->actingAs($admin, 'admin')->postJson(route('admin.restaurant.connections.token', $connection));
        $this->satisfyAllLiveActivationGatesExcept($outlet);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.restaurant.connections.activate', $connection->fresh()))
            ->assertSessionHasNoErrors();

        $this->assertSame(PosConnection::STATUS_CONNECTED, $connection->fresh()->status);
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    // ══ No payload/secret leakage on the detail page ═══════════════════

    #[Test]
    public function the_detail_page_never_contains_raw_body_raw_payload_or_customer_fields(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-LEAK-1', null);

        $secretMarker = 'CUSTOMER_PHONE_9999999999_SECRET_MARKER';
        PosWebhookEvent::create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'provider' => 'petpooja',
            'payload_hash' => hash('sha256', $secretMarker),
            'event_type' => 'orderdetails',
            'received_at' => now(),
            'processing_status' => 'pending',
            'raw_payload' => ['customer' => ['phone' => $secretMarker]],
            'raw_body' => '{"customer":{"phone":"'.$secretMarker.'"}}',
            'attempts' => 0,
        ]);

        $page = $this->actingAs($admin, 'admin')->get(route('admin.restaurant.connections.show', $connection));

        $page->assertOk();
        $page->assertDontSee($secretMarker, false);
    }

    #[Test]
    public function the_exact_static_webhook_url_is_shown_on_the_detail_page(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-URL-1', null);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.connections.show', $connection))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('webhookUrl', url('/webhooks/pos/petpooja')));
    }

    // ══ IP allowlist — trim/dedupe/validate, on both the create and update paths ══

    #[Test]
    public function creating_a_connection_with_messy_but_valid_ips_trims_and_deduplicates_them(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-IP-1',
            'allowed_ips' => ['203.0.113.5', ' 203.0.113.5 ', '198.51.100.1', ''],
        ])->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-IP-1')->firstOrFail();
        $this->assertSame(['203.0.113.5', '198.51.100.1'], $connection->allowed_ips);
    }

    #[Test]
    public function creating_a_connection_with_an_invalid_ip_is_rejected_with_a_friendly_error(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-IP-2',
            'allowed_ips' => ['203.0.113.5', 'not-an-ip'],
        ]);

        $response->assertSessionHasErrors('allowed_ips.1');
        $this->assertSame(
            'Each allowed IP must be a valid IP address. Check for typos and remove anything that is not a plain IP (CIDR ranges are not supported).',
            session('errors')->first('allowed_ips.1')
        );
        $this->assertSame(0, PosConnection::query()->where('external_ref', 'REST-IP-2')->count());
    }

    #[Test]
    public function updating_the_allowlist_trims_deduplicates_and_persists_valid_ips(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-IP-3', null);

        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.connections.allowed-ips', $connection), [
            'allowed_ips' => [' 203.0.113.5', '203.0.113.5 ', '198.51.100.1'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['203.0.113.5', '198.51.100.1'], $connection->fresh()->allowed_ips);
    }

    #[Test]
    public function updating_the_allowlist_with_an_invalid_ip_is_rejected_and_leaves_the_existing_list_unchanged(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-IP-4', null);
        app(PosConnectionProvisioningService::class)
            ->updateAllowedIps($connection, ['203.0.113.5']);

        $response = $this->actingAs($admin, 'admin')->put(route('admin.restaurant.connections.allowed-ips', $connection), [
            'allowed_ips' => ['203.0.113.5', '999.999.999.999'],
        ]);

        $response->assertSessionHasErrors('allowed_ips.1');
        $this->assertSame(['203.0.113.5'], $connection->fresh()->allowed_ips,
            'A rejected update must not partially apply.');
    }

    #[Test]
    public function an_all_blank_allowlist_is_treated_as_no_restriction_not_a_validation_error(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'existing',
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'external_ref' => 'REST-IP-5',
            'allowed_ips' => ['', "\n", '   '],
        ])->assertSessionHasNoErrors();

        $connection = PosConnection::query()->where('external_ref', 'REST-IP-5')->firstOrFail();
        $this->assertNull($connection->allowed_ips);
    }

    // ══ The guarded Super Admin correction flow (move) ═══════════════════

    #[Test]
    public function moving_a_pre_event_connection_succeeds_rotates_the_token_and_reassigns_workspace_and_outlet(): void
    {
        $admin = $this->adminWith(['move_pos_connections']);
        $sourceOutlet = $this->outlet();
        $connection = $this->service()->createSandboxConnection($sourceOutlet, 'REST-MOVE-1', null);
        $oldToken = $this->service()->generateToken($connection);

        ['workspace' => $targetWorkspace] = $this->createWorkspaceContext();
        $targetOutlet = RestaurantOutlet::factory()->create([
            'workspace_id' => $targetWorkspace->id,
            'status' => RestaurantOutlet::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.move', $connection), [
            'mode' => 'existing',
            'target_workspace_id' => $targetWorkspace->id,
            'target_outlet_id' => $targetOutlet->id,
            'confirmed' => true,
        ]);

        $response->assertSessionHasNoErrors();

        $fresh = $connection->fresh();
        $this->assertSame($targetWorkspace->id, $fresh->workspace_id);
        $this->assertSame($targetOutlet->id, $fresh->outlet_id);
        $this->assertSame(PosConnection::STATUS_PAUSED, $fresh->status,
            'A moved connection is paused, never left connected under its new identity automatically.');
        $this->assertFalse(PosConnection::verifyToken($fresh, $oldToken),
            'The pre-move token must be invalidated immediately.');
        $this->assertSame(0, $sourceOutlet->posConnections()->where('status', '!=', PosConnection::STATUS_ARCHIVED)->count());
    }

    #[Test]
    public function moving_without_explicit_confirmation_is_rejected(): void
    {
        $admin = $this->adminWith(['move_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-MOVE-2', null);
        ['workspace' => $targetWorkspace] = $this->createWorkspaceContext();
        $targetOutlet = RestaurantOutlet::factory()->create([
            'workspace_id' => $targetWorkspace->id,
            'status' => RestaurantOutlet::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.move', $connection), [
            'mode' => 'existing',
            'target_workspace_id' => $targetWorkspace->id,
            'target_outlet_id' => $targetOutlet->id,
            // 'confirmed' intentionally omitted.
        ]);

        $response->assertSessionHasErrors('confirmed');
        $this->assertSame($connection->workspace_id, $connection->fresh()->workspace_id);
    }

    #[Test]
    public function moving_a_connection_with_webhook_history_is_blocked_and_explains_super_admin_review_is_required(): void
    {
        $admin = $this->adminWith(['move_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-MOVE-3', null);
        PosWebhookEvent::create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'provider' => 'petpooja',
            'payload_hash' => hash('sha256', 'x'),
            'event_type' => 'orderdetails',
            'received_at' => now(),
            'processing_status' => 'pending',
            'raw_payload' => ['event' => 'orderdetails'],
            'raw_body' => '{"event":"orderdetails"}',
            'attempts' => 0,
        ]);

        ['workspace' => $targetWorkspace] = $this->createWorkspaceContext();
        $targetOutlet = RestaurantOutlet::factory()->create([
            'workspace_id' => $targetWorkspace->id,
            'status' => RestaurantOutlet::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.move', $connection), [
            'mode' => 'existing',
            'target_workspace_id' => $targetWorkspace->id,
            'target_outlet_id' => $targetOutlet->id,
            'confirmed' => true,
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Super Admin review', session('error'));
        $this->assertSame($connection->workspace_id, $connection->fresh()->workspace_id,
            'A connection with webhook history must never be moved by this self-service flow.');
    }

    #[Test]
    public function an_admin_without_the_move_permission_cannot_move_a_connection(): void
    {
        // Holds manage_pos_connections but NOT move_pos_connections — the
        // deliberately separate, more consequential permission this action requires.
        $admin = $this->adminWith(['manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-MOVE-4', null);
        ['workspace' => $targetWorkspace] = $this->createWorkspaceContext();
        $targetOutlet = RestaurantOutlet::factory()->create([
            'workspace_id' => $targetWorkspace->id,
            'status' => RestaurantOutlet::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin, 'admin')->postJson(route('admin.restaurant.connections.move', $connection), [
            'mode' => 'existing',
            'target_workspace_id' => $targetWorkspace->id,
            'target_outlet_id' => $targetOutlet->id,
            'confirmed' => true,
        ])->assertForbidden();

        $this->assertSame($connection->workspace_id, $connection->fresh()->workspace_id);
    }

    // ══ Reversible Archive / Restore ═════════════════════════════════════

    #[Test]
    public function the_default_connections_index_shows_only_non_archived_connections(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $this->service()->createSandboxConnection($this->outlet(), 'REST-IDX-LIVE', null);
        $archived = $this->service()->createSandboxConnection($this->outlet(), 'REST-IDX-ARCHIVED', null);
        $this->service()->archiveConnection($archived->fresh());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.connections.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                /** @var list<array<string, mixed>> $rows */
                $rows = $page->toArray()['props']['connections']['data'];
                $refs = array_column($rows, 'external_ref');
                $this->assertContains('REST-IDX-LIVE', $refs);
                $this->assertNotContains('REST-IDX-ARCHIVED', $refs);
            });
    }

    /** The Archived tab, and its inverse (default tab excludes archived) — the positive control alongside it. */
    #[Test]
    public function archived_connections_appear_only_under_the_archived_filter(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-IDX-ARCHIVED-2', null);
        $this->service()->archiveConnection($connection->fresh());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.connections.index', ['status' => 'archived']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('filters.status', 'archived');
                /** @var list<array<string, mixed>> $rows */
                $rows = $page->toArray()['props']['connections']['data'];
                $this->assertContains('REST-IDX-ARCHIVED-2', array_column($rows, 'external_ref'));
            });
    }

    #[Test]
    public function restoring_an_archived_connection_lands_on_paused_and_preserves_restid_and_history(): void
    {
        $admin = $this->adminWith(['manage_pos_connections', 'rotate_pos_webhook_secret', 'activate_pos_connections']);
        $outlet = $this->outlet();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-HTTP-1', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        PosWebhookEvent::create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'provider' => 'petpooja',
            'payload_hash' => hash('sha256', 'x'),
            'event_type' => 'orderdetails',
            'received_at' => now(),
            'processing_status' => 'pending',
            'raw_payload' => ['event' => 'orderdetails'],
            'raw_body' => '{"event":"orderdetails"}',
            'attempts' => 0,
        ]);
        $this->service()->archiveConnection($connection->fresh());

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.restore', $connection))
            ->assertSessionHasNoErrors();

        $fresh = $connection->fresh();
        $this->assertSame(PosConnection::STATUS_PAUSED, $fresh->status);
        $this->assertSame('REST-RESTORE-HTTP-1', $fresh->external_ref);
        $this->assertSame(1, PosConnection::query()->where('external_ref', 'REST-RESTORE-HTTP-1')->count(),
            'Restore must never duplicate the restID/row.');
        $this->assertSame(1, $fresh->webhookEvents()->count(), 'History must survive the restore.');
        $this->assertTrue(PosConnection::verifyToken($fresh, $token),
            'The original token must remain valid — restore must not force rotation.');

        // Resume is the pre-existing, separate activate() action.
        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.activate', $fresh))
            ->assertSessionHasNoErrors();
        $this->assertSame(PosConnection::STATUS_CONNECTED, $fresh->fresh()->status);
    }

    #[Test]
    public function restoring_a_non_archived_connection_is_refused_with_a_friendly_error(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-RESTORE-HTTP-2', null);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.restore', $connection));

        $response->assertSessionHas('error');
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->fresh()->status);
    }

    #[Test]
    public function restoring_is_blocked_when_the_outlet_already_has_a_non_archived_connection(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = $this->outlet();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-HTTP-3', null);
        $this->service()->archiveConnection($connection->fresh());
        $this->service()->createSandboxConnection($outlet->fresh(), 'REST-RESTORE-HTTP-3B', null);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.restore', $connection));

        $response->assertSessionHas('error');
        $this->assertSame(PosConnection::STATUS_ARCHIVED, $connection->fresh()->status);
        $this->assertSame(1, PosConnection::query()->where('outlet_id', $outlet->id)
            ->where('status', '!=', PosConnection::STATUS_ARCHIVED)->count(),
            'The outlet must still have exactly ONE non-archived connection — restore must never create a duplicate.');
    }

    #[Test]
    public function admin_without_permission_cannot_restore_a_connection(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-RESTORE-HTTP-4', null);
        $this->service()->archiveConnection($connection->fresh());

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.connections.restore', $connection))
            ->assertForbidden();

        $this->assertSame(PosConnection::STATUS_ARCHIVED, $connection->fresh()->status);
    }

    // ══ Restore Connection UI flow (detail page) ═════════════════════════

    /**
     * The prop-level proof behind "the archived detail page exposes Restore
     * Connection": `is_restorable` is exactly the flag Show.jsx's
     * `canManage && connection.is_restorable` gates the button on.
     */
    #[Test]
    public function the_archived_connections_detail_page_exposes_restore_eligibility(): void
    {
        $admin = $this->adminWith(['view_pos_connections', 'manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-RESTORE-UI-1', null);
        $this->service()->archiveConnection($connection->fresh());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.connections.show', $connection))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('connection.status', 'archived');
                $page->where('connection.is_restorable', true);
            });
    }

    /**
     * ⚠️ Discovered while visually verifying against real data: an archived
     * connection whose outlet has since acquired a DIFFERENT live connection
     * (e.g. an admin created a replacement, not realizing the old one still
     * existed archived) correctly reports `is_restorable = false` — and the
     * detail page must explain WHY, not just silently omit the button (that
     * silence is indistinguishable from a broken button, which is exactly
     * the complaint that started this section).
     */
    #[Test]
    public function the_archived_connections_detail_page_reports_not_restorable_when_the_outlet_has_a_competing_connection(): void
    {
        $admin = $this->adminWith(['view_pos_connections', 'manage_pos_connections']);
        $outlet = $this->outlet();
        $archived = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-UI-4', null);
        $this->service()->archiveConnection($archived->fresh());
        $this->service()->createSandboxConnection($outlet->fresh(), 'REST-RESTORE-UI-4B', null);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.connections.show', $archived))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('connection.status', 'archived');
                $page->where('connection.is_restorable', false);
            });
    }

    /**
     * "Restored page is Paused and exposes Resume Ingress": once status is
     * 'paused', Show.jsx's paused branch always renders the Resume Ingress
     * button (gated only on the `activate_pos_connections` permission, not
     * on any extra flag) — so proving `status === 'paused'` here is the
     * same proof. `is_restorable` also flips false, since the archived-only
     * Restore button must disappear once restored.
     */
    #[Test]
    public function after_restoring_the_detail_page_shows_paused_and_no_longer_offers_restore(): void
    {
        $admin = $this->adminWith(['view_pos_connections', 'manage_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-RESTORE-UI-2', null);
        $this->service()->archiveConnection($connection->fresh());

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.restore', $connection))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.connections.show', $connection->fresh()))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('connection.status', 'paused');
                $page->where('connection.is_restorable', false);
            });
    }

    /**
     * Resume after a restore reaches CONNECTED using the ORIGINAL token —
     * proven against the real Phase 1B webhook endpoint, not just the
     * model's own verifyToken() check.
     */
    #[Test]
    public function resuming_a_restored_connection_reaches_connected_and_the_original_token_still_accepts_webhooks(): void
    {
        $admin = $this->adminWith(['manage_pos_connections', 'rotate_pos_webhook_secret', 'activate_pos_connections']);
        $connection = $this->service()->createSandboxConnection($this->outlet(), 'REST-RESTORE-UI-3', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        $this->service()->archiveConnection($connection->fresh());
        $this->service()->restoreConnection($connection->fresh());

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.activate', $connection->fresh()))
            ->assertSessionHasNoErrors();

        $fresh = $connection->fresh();
        $this->assertSame(PosConnection::STATUS_CONNECTED, $fresh->status);
        $this->assertTrue(PosConnection::verifyToken($fresh, $token),
            'The original token must still verify after resume — restore/resume never rotates it.');

        $this->postJson('/webhooks/pos/petpooja', [
            'event' => 'orderdetails',
            'orderID' => 'ORD-RESUME-UI-1',
            'properties' => ['Restaurant' => ['restID' => 'REST-RESTORE-UI-3']],
            'token' => $token,
        ])->assertStatus(200);
    }
}
