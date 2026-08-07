<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DEEP-03 and its three siblings: four privileged admin actions were gated by
 * READ permissions.
 *
 *   impersonate    view_clients          -> impersonate_clients   (new)
 *   assign-plan    view_clients          -> manage_subscriptions
 *   refund         view_payment_gateways -> manage_payment_gateways
 *   support reply  view_settings         -> manage_settings
 *
 * The delivery mechanism was the role description: SUPPORT shipped as "View
 * clients and subscriptions only", so an operator granting view_clients
 * believed they were granting read access and were in fact granting the ability
 * to log in as any client's administrator.
 *
 * Every negative is paired with a positive control on the same route and verb.
 *
 * ⚠️ THE 403 MUST BE UNAMBIGUOUS. Each target has other early exits, but they
 * are flash-redirects, not 403s — verified by reading every abort path in the
 * four methods. So a 403 can only come from the permission layer. The
 * fixtures below still satisfy each action's other preconditions (an active
 * client user for impersonation, an unrefunded transaction) so that the
 * POSITIVE controls exercise the permission rather than tripping on something
 * else.
 */
class PrivilegedActionPermissionTest extends TestCase
{
    use RefreshDatabase;

    /** An admin holding exactly the given permission keys and nothing else. */
    private function adminWith(array $permissionKeys): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);

        $role = Role::create([
            'key' => 'TEST_ROLE_'.uniqid(),
            'name' => 'Test Role',
            'description' => 'Holds only the permissions under test',
        ]);

        foreach ($permissionKeys as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'test']
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /** A client with an active administrator, so impersonation has a target. */
    private function clientWithAdministrator(): Client
    {
        ['client' => $client] = $this->createWorkspaceContext();

        User::where('client_id', $client->id)->update([
            'status' => User::STATUS_ACTIVE,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
        ]);

        return $client;
    }

    // ── DEEP-03 — impersonation ────────────────────────────────────────────

    #[Test]
    public function an_admin_with_only_view_clients_cannot_impersonate(): void
    {
        $admin = $this->adminWith(['view_clients']);
        $client = $this->clientWithAdministrator();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.clients.impersonate', $client))
            ->assertForbidden();

        // Proof it did not merely fail late: no session was established.
        $this->assertGuest('web');
    }

    #[Test]
    public function an_admin_with_impersonate_clients_can_impersonate(): void
    {
        $admin = $this->adminWith(['view_clients', 'impersonate_clients']);
        $client = $this->clientWithAdministrator();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.impersonate', $client))
            ->assertRedirect(route('client.dashboard'));

        $this->assertAuthenticated('web');
    }

    // ── DEEP-05 — assign plan ──────────────────────────────────────────────

    #[Test]
    public function an_admin_with_only_view_clients_cannot_assign_a_plan(): void
    {
        $admin = $this->adminWith(['view_clients']);
        ['client' => $client] = $this->createWorkspaceContext();
        $plan = \App\Models\Plan::factory()->create();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.clients.assign-plan', $client), ['plan_id' => $plan->id])
            ->assertForbidden();
    }

    #[Test]
    public function an_admin_with_manage_subscriptions_can_assign_a_plan(): void
    {
        $admin = $this->adminWith(['view_clients', 'manage_subscriptions']);
        ['client' => $client] = $this->createWorkspaceContext();
        $plan = \App\Models\Plan::factory()->create();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.assign-plan', $client), ['plan_id' => $plan->id]);

        $this->assertNotSame(403, $response->getStatusCode(),
            'A manage_subscriptions admin was refused.');
    }

    // ── Refund ─────────────────────────────────────────────────────────────

    /** No PaymentTransaction factory exists, so the row is built directly. */
    private function transaction(): \App\Models\PaymentTransaction
    {
        return \App\Models\PaymentTransaction::create([
            'gateway' => 'stripe',
            'amount_cents' => 5000,
            'refunded_at' => null,
        ]);
    }

    #[Test]
    public function an_admin_with_only_view_payment_gateways_cannot_refund(): void
    {
        $admin = $this->adminWith(['view_payment_gateways']);
        $transaction = $this->transaction();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.payments.refund', $transaction), ['reason' => 'test'])
            ->assertForbidden();
    }

    #[Test]
    public function an_admin_with_manage_payment_gateways_reaches_the_refund_action(): void
    {
        $admin = $this->adminWith(['view_payment_gateways', 'manage_payment_gateways']);
        $transaction = $this->transaction();

        // The gateway is not configured in tests, so the refund itself cannot
        // succeed — it returns a flash error, NOT a 403. "Not 403" is the
        // honest assertion: the permission layer let it through.
        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.payments.refund', $transaction), ['reason' => 'test']);

        $this->assertNotSame(403, $response->getStatusCode(),
            'A manage_payment_gateways admin was refused the refund route.');
    }

    // ── Support reply ──────────────────────────────────────────────────────

    private function ticket(): \App\Models\SupportTicket
    {
        return \App\Models\SupportTicket::factory()->create();
    }

    #[Test]
    public function an_admin_with_only_view_settings_cannot_reply_to_a_ticket(): void
    {
        $admin = $this->adminWith(['view_settings']);
        $ticket = $this->ticket();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.support.reply', $ticket), ['message' => 'hello'])
            ->assertForbidden();
    }

    #[Test]
    public function an_admin_with_manage_settings_can_reply_to_a_ticket(): void
    {
        $admin = $this->adminWith(['view_settings', 'manage_settings']);
        $ticket = $this->ticket();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.support.reply', $ticket), ['message' => 'hello']);

        $this->assertNotSame(403, $response->getStatusCode(),
            'A manage_settings admin was refused the support reply route.');
    }

    // ── The refund audit log ───────────────────────────────────────────────

    /**
     * Money left the system with no record of who authorised it: the whole
     * TransactionController had zero audit logging.
     */
    #[Test]
    public function a_refund_records_who_authorised_it(): void
    {
        $admin = $this->adminWith(['view_payment_gateways', 'manage_payment_gateways']);
        $transaction = $this->transaction();

        // Only a SUCCESSFUL refund is logged, so drive the gateway to succeed.
        // BillingGatewayRegistry::get() is typed ?BillingGatewayInterface, so
        // the stub must actually implement it — an anonymous class fails the
        // mock's return type.
        $gateway = \Mockery::mock(\App\Contracts\BillingGatewayInterface::class);
        $gateway->shouldReceive('refund')->andReturn(['ok' => true, 'id' => 're_test_123']);

        $this->mock(\App\Services\Billing\BillingGatewayRegistry::class, function ($mock) use ($gateway) {
            $mock->shouldReceive('get')->andReturn($gateway);
        });

        $this->actingAs($admin, 'admin')
            ->post(route('admin.payments.refund', $transaction), ['reason' => 'duplicate charge']);

        $entry = AuditLog::where('action', 'payment.refunded')->latest('id')->first();

        $this->assertNotNull($entry, 'A refund was processed with no audit entry.');
        $this->assertSame($admin->id, $entry->actor_admin_id, 'The refund did not record who authorised it.');
        $this->assertSame((int) $transaction->id, (int) $entry->auditable_id);
        $this->assertSame('duplicate charge', $entry->meta['reason'] ?? null);
        $this->assertSame('stripe', $entry->meta['gateway'] ?? null);
    }

    // ── The role description that delivered the bug ────────────────────────

    #[Test]
    public function the_support_role_no_longer_promises_that_view_clients_is_read_only(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $support = Role::where('key', Role::KEY_SUPPORT)->first();

        $this->assertNotNull($support);
        $this->assertStringNotContainsString('View clients and subscriptions only', (string) $support->description,
            'The SUPPORT description still promises read-only access, which is how DEEP-03 was granted in the first place.');
    }
}
