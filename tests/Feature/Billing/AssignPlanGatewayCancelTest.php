<?php

namespace Tests\Feature\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * BUG-031 — assigning a plan cancelled only the client_subscriptions row and
 * never told the payment gateway, so the customer kept being charged for the
 * old plan while receiving the newly assigned one.
 */
class AssignPlanGatewayCancelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'R_'.uniqid(), 'name' => 'R', 'description' => '']);
        foreach (['view_clients', 'manage_subscriptions'] as $key) {
            $perm = Permission::firstOrCreate(['key' => $key],
                ['name' => $key, 'category' => 'test']);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /**
     * A gateway subscription belonging to one of the client's users.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function gatewaySub(Client $client, string $gateway, array $attrs = []): Subscription
    {
        $user = User::query()->where('client_id', $client->id)->firstOrFail();

        // ⚠️ No SubscriptionFactory exists in this codebase — created directly.
        return Subscription::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => Plan::factory()->create()->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'ends_at' => null,
            'gateway' => $gateway,
            'gateway_subscription_id' => 'sub_'.uniqid(),
        ], $attrs));
    }

    /**
     * ⚠️ A REAL FAKE, not a mock. The registry is a concrete class, and a
     * hand-written double keeps the cancel() contract visible: it returns a
     * bool and — like every one of the thirteen real drivers — writes the
     * local row itself on success. A mock that only returned true would let
     * the rollback tests pass without proving anything.
     *
     * @param  array<string, bool>  $map  gateway key => what cancel() returns
     */
    private function fakeGateways(array $map): void
    {
        $registry = new class($map) extends BillingGatewayRegistry
        {
            /** @param array<string, bool> $map */
            public function __construct(private array $map) {}

            public function get(string $key): ?BillingGatewayInterface
            {
                if (! array_key_exists($key, $this->map)) {
                    return null;                      // unknown registry key
                }

                return new class($this->map[$key]) implements BillingGatewayInterface
                {
                    public function __construct(private bool $result) {}

                    public function cancel(Subscription $subscription): bool
                    {
                        if ($this->result) {
                            // Every real driver does this itself on success.
                            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);
                        }

                        return $this->result;
                    }

                    public function name(): string
                    {
                        return 'fake';
                    }

                    public function isConfigured(): bool
                    {
                        return true;
                    }

                    /** @return array<string, mixed> */
                    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
                    {
                        return [];
                    }

                    public function handleWebhook(Request $request): Response
                    {
                        return new Response;
                    }

                    public function sync(Subscription $subscription): bool
                    {
                        return true;
                    }

                    /** @return array<string, mixed> */
                    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
                    {
                        return [];
                    }

                    /** @return array<string, mixed> */
                    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
                    {
                        return [];
                    }

                    /** @return array<string, mixed> */
                    public function fulfillCheckoutSession(string $sessionId): array
                    {
                        return [];
                    }
                };
            }
        };

        $this->app->instance(BillingGatewayRegistry::class, $registry);
    }

    /** @return TestResponse<JsonResponse> */
    private function assign(AdminUser $admin, Client $client, Plan $plan, string $cycle = 'monthly'): TestResponse
    {
        return $this->actingAs($admin, 'admin')->postJson(
            route('admin.clients.assign-plan', $client),
            ['plan_id' => $plan->id, 'billing_cycle' => $cycle]
        );
    }

    /**
     * The newest audit entry's meta, decoded from the raw column.
     *
     * ⚠️ Read via getRawOriginal(): AuditLog casts `meta` to array, but that
     * cast is invisible to static analysis, which sees the string column.
     *
     * @return array<string, mixed>
     */
    private function auditMeta(string $action): array
    {
        $log = AuditLog::query()->where('action', $action)->latest('id')->first();
        $this->assertNotNull($log, "No audit entry was written for `{$action}`.");

        $decoded = json_decode((string) $log->getRawOriginal('meta'), true);

        return is_array($decoded) ? $decoded : [];
    }

    // ══ the fix ════════════════════════════════════════════════════════════

    #[Test]
    public function a_successful_cancel_lets_the_assignment_through(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $gwSub = $this->gatewaySub($client, 'stripe');
        $plan = Plan::factory()->create();
        $this->fakeGateways(['stripe' => true]);

        $this->assign($this->admin(), $client, $plan)->assertOk();

        $this->assertSame('canceled', $gwSub->fresh()->status,
            'The gateway subscription was not cancelled — BUG-031.');
        $this->assertSame($plan->id, $client->fresh()->activeSubscription->plan_id);
    }

    /** ⚠️ The DB state must be genuinely unchanged, not merely exception-free. */
    #[Test]
    public function a_failed_cancel_blocks_and_rolls_everything_back(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $gwSub = $this->gatewaySub($client, 'stripe');
        $old = Plan::factory()->create();
        $existing = $client->clientSubscriptions()->create([
            'plan_id' => $old->id, 'billing_cycle' => 'monthly',
            'starts_at' => now(), 'status' => ClientSubscription::STATUS_ACTIVE,
        ]);
        $rowsBefore = ClientSubscription::count();
        $plan = Plan::factory()->create();
        $this->fakeGateways(['stripe' => false]);

        $this->assign($this->admin(), $client, $plan)->assertStatus(422);

        $this->assertSame('active', $gwSub->fresh()->status);
        $this->assertSame(ClientSubscription::STATUS_ACTIVE, $existing->fresh()->status,
            'The old client_subscriptions row was cancelled despite the block.');
        $this->assertSame($old->id, $client->fresh()->activeSubscription->plan_id);
        $this->assertSame($rowsBefore, ClientSubscription::count(),
            'A new client_subscriptions row was created despite the block.');
    }

    #[Test]
    public function every_active_gateway_subscription_is_cancelled(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $a = $this->gatewaySub($client, 'stripe');
        $b = $this->gatewaySub($client, 'razorpay');
        $this->fakeGateways(['stripe' => true, 'razorpay' => true]);

        $this->assign($this->admin(), $client, Plan::factory()->create())->assertOk();

        $this->assertSame('canceled', $a->fresh()->status);
        $this->assertSame('canceled', $b->fresh()->status);
    }

    /** ⚠️ Partial failure: the first cancel's own DB write must roll back too. */
    #[Test]
    public function a_partial_multi_gateway_failure_leaves_nothing_half_applied(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $ok = $this->gatewaySub($client, 'stripe');
        $bad = $this->gatewaySub($client, 'razorpay');
        $rowsBefore = ClientSubscription::count();
        $this->fakeGateways(['stripe' => true, 'razorpay' => false]);

        $this->assign($this->admin(), $client, Plan::factory()->create())->assertStatus(422);

        $this->assertSame('active', $ok->fresh()->status,
            'The first gateway cancel was committed even though a later one failed.');
        $this->assertSame('active', $bad->fresh()->status);
        $this->assertSame($rowsBefore, ClientSubscription::count());
    }

    #[Test]
    public function an_unknown_gateway_key_is_guarded_and_blocks(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $gwSub = $this->gatewaySub($client, 'not_a_real_gateway');
        $this->fakeGateways([]);                    // get() returns null

        $this->assign($this->admin(), $client, Plan::factory()->create())->assertStatus(422);

        $this->assertSame('active', $gwSub->fresh()->status);
    }

    #[Test]
    public function a_client_with_no_gateway_subscription_assigns_normally(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $plan = Plan::factory()->create();
        $this->fakeGateways([]);

        $this->assign($this->admin(), $client, $plan)->assertOk();

        $this->assertSame($plan->id, $client->fresh()->activeSubscription->plan_id);
    }

    /**
     * ⚠️ isActive(), not a raw status check. A row left 'active' past its
     * ends_at by a missed webhook is NOT live and must not be cancelled —
     * if it were targeted, the null gateway would block the assignment.
     */
    #[Test]
    public function an_expired_but_status_active_row_is_not_targeted(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $stale = $this->gatewaySub($client, 'not_a_real_gateway', [
            'status' => 'active',
            'ends_at' => now()->subDay(),
        ]);
        $plan = Plan::factory()->create();
        $this->fakeGateways([]);

        $this->assign($this->admin(), $client, $plan)->assertOk();

        $this->assertSame('active', $stale->fresh()->status, 'The expired row was touched.');
        $this->assertSame($plan->id, $client->fresh()->activeSubscription->plan_id);
    }

    /**
     * ⚠️ THE DOUBLE-SUBMIT TRAP. Without the no-op guard the second call tries
     * to cancel the already-cancelled gateway subscription, gets false, and
     * block-on-failure refuses an assignment that changes nothing.
     */
    #[Test]
    public function reassigning_the_identical_plan_is_a_no_op(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $gwSub = $this->gatewaySub($client, 'stripe');
        $plan = Plan::factory()->create();
        $admin = $this->admin();

        $this->fakeGateways(['stripe' => true]);
        $this->assign($admin, $client, $plan)->assertOk();
        $rowsAfterFirst = ClientSubscription::count();

        // Second submit: the gateway would now fail, so a missing guard blocks.
        $this->fakeGateways(['stripe' => false]);
        $this->assign($admin, $client, $plan)->assertOk();

        $this->assertSame($rowsAfterFirst, ClientSubscription::count(),
            'The no-op re-assignment stacked another client_subscriptions row.');
        $this->assertSame('canceled', $gwSub->fresh()->status);
    }

    // ══ audit ══════════════════════════════════════════════════════════════

    #[Test]
    public function the_audit_entry_records_each_gateway_outcome(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $this->gatewaySub($client, 'stripe');
        $this->fakeGateways(['stripe' => true]);

        $this->assign($this->admin(), $client, Plan::factory()->create())->assertOk();

        $meta = $this->auditMeta('client.plan_assigned');
        $this->assertArrayHasKey('gateway_cancellations', $meta);
        $this->assertSame('stripe', $meta['gateway_cancellations'][0]['gateway']);
        $this->assertTrue($meta['gateway_cancellations'][0]['cancelled']);
    }

    /** ⚠️ A blocked assignment must still leave a trace. */
    #[Test]
    public function a_blocked_assignment_is_audit_logged_as_a_failure(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();
        $this->gatewaySub($client, 'stripe');
        $this->fakeGateways(['stripe' => false]);

        $this->assign($this->admin(), $client, Plan::factory()->create())->assertStatus(422);

        $meta = $this->auditMeta('client.plan_assign_failed');
        $this->assertFalse($meta['gateway_cancellations'][0]['cancelled']);
    }
}
