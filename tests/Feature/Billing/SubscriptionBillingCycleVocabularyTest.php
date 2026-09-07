<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ═══ TWO TABLES, TWO VOCABULARIES, AND THEY MUST NOT BE MIXED ═══════════════
 *
 *   subscriptions.billing_cycle         'month' | 'year'
 *   client_subscriptions.billing_cycle  'monthly' | 'yearly'
 *
 * Both are correct for their own table. `subscriptions` is enforced by
 * `Rule::in(['month','year'])` in three controllers — CheckoutController:26,
 * Client\SubscriptionController:107, Admin\SubscriptionController:92 — while
 * `client_subscriptions` is enforced by `in:monthly,yearly` at
 * Admin\ClientController:270 and has `ClientSubscription::BILLING_MONTHLY`
 * constants for the purpose.
 *
 * ⚠️ WHY THIS TEST EXISTS. `DemoSeeder` wrote 'monthly' into a **subscriptions**
 * row three lines below a correct `ClientSubscription::BILLING_MONTHLY` — one
 * block copied onto the other table. Nothing caught it, because nothing compares
 * the two vocabularies, and the consequence was silent rather than loud:
 *
 *   Client\SubscriptionController:123 refuses a no-op plan change by comparing
 *   the submitted cycle against the stored one. Submitted values are validated
 *   'month'|'year'; the stored value was 'monthly'. The comparison could never
 *   be true, so the guard never fired and every redundant change was forwarded
 *   to the payment gateway as a real plan change.
 *
 * A guard that cannot fire looks exactly like a guard that is never needed.
 */
class SubscriptionBillingCycleVocabularyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ A SOURCE SCAN, and deliberately so.
     *
     * A database assertion cannot catch this: under RefreshDatabase the table
     * starts empty, so "no row holds 'monthly'" passes trivially and forever.
     * The defect lived in a WRITE SITE, so the write sites are what is scanned —
     * the same approach, and the same zero-inventory shape, as
     * WorkspaceScopeBypassGuardTest.
     *
     * The literal is what is banned. `client_subscriptions` writes should use
     * `ClientSubscription::BILLING_MONTHLY` / `BILLING_YEARLY`, which is why the
     * sanctioned count is zero rather than a list of exceptions.
     */
    #[Test]
    public function no_source_file_writes_the_long_vocabulary_as_a_billing_cycle_literal(): void
    {
        $offenders = [];

        foreach (['app', 'database'] as $dir) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $lines = file($file->getPathname());

                foreach ($lines as $i => $line) {
                    if (preg_match("/'billing_cycle'\s*=>\s*'(monthly|yearly)'/", $line, $m)) {
                        $rel = str_replace(base_path().'/', '', $file->getPathname());
                        $offenders[] = "{$rel}:".($i + 1)." writes '{$m[1]}'";
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "A billing_cycle literal from the client_subscriptions vocabulary was found.\n"
            ."If the target is `subscriptions`, use 'month'/'year'.\n"
            ."If the target is `client_subscriptions`, use ClientSubscription::BILLING_MONTHLY/BILLING_YEARLY.\n"
            .implode("\n", $offenders));
    }

    /** The validator that defines the vocabulary for `subscriptions`. */
    #[Test]
    public function the_change_plan_endpoint_rejects_the_long_form(): void
    {
        [$user, $plan] = $this->subscribedUser();

        $this->actingAs($user)
            ->from(route('client.subscription.show'))
            ->post(route('client.subscription.change-plan'), [
                'plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertSessionHasErrors('billing_cycle');
    }

    /**
     * ⚠️ THE CONSEQUENCE, not just the spelling.
     *
     * This is the assertion that would have failed while the seeded row said
     * 'monthly': the no-op guard at Client\SubscriptionController:123 only fires
     * when the stored cycle is comparable to the submitted one.
     */
    #[Test]
    public function a_no_op_plan_change_is_refused_before_reaching_any_gateway(): void
    {
        [$user, $plan] = $this->subscribedUser();

        $this->actingAs($user)
            ->from(route('client.subscription.show'))
            ->post(route('client.subscription.change-plan'), [
                'plan_id' => $plan->id,          // same plan
                'billing_cycle' => 'month',      // same cycle as stored
            ])
            ->assertSessionHas('error');
    }

    /** @return array{0: User, 1: Plan} */
    private function subscribedUser(): array
    {
        $plan = Plan::factory()->create([
            'monthly_price_cents' => 2900,
            'yearly_price_cents' => 29000,
            'enabled' => true,
        ]);

        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_test_vocab',
            'starts_at' => now()->subMonth(),
            'renews_at' => now()->addMonth(),
        ]);

        return [$user->fresh(), $plan];
    }
}
