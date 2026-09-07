<?php

namespace Tests\Feature\ProductionHardening;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ═══ CSRF MUST NOT STAND BETWEEN A GATEWAY AND ITS WEBHOOK ══════════════════
 *
 * The billing webhook routes are declared in `routes/web.php`, so they carry the
 * `web` middleware group — CSRF included. A gateway posts no CSRF token and has
 * no session, so any route missing from `bootstrap/app.php`'s
 * `validateCsrfTokens(except: […])` list answers a real callback with **419**.
 *
 * That failure is silent on our side: the request never reaches the controller,
 * so nothing is logged, no signature is checked, and the gateway simply retries
 * into the same rejection until it gives up. Razorpay and Cashfree were both
 * missing from that list and were unreachable in exactly this way.
 *
 * ─── ⚠️ WHY THESE TESTS OVERRIDE THE ENVIRONMENT ────────────────────────────
 *
 * `VerifyCsrfToken::handle()` short-circuits on the SECOND of its four
 * conditions:
 *
 *     $this->isReading($request) ||
 *     $this->runningUnitTests() ||        ← returns true when env === 'testing'
 *     $this->inExceptArray($request) ||
 *     $this->tokensMatch($request)
 *
 * `runningUnitTests()` is evaluated BEFORE `inExceptArray()`, so under the
 * ordinary `testing` environment every route is exempt and the except list is
 * never consulted. A plain feature test posting to `/webhooks/razorpay` would
 * therefore pass identically with or without the fix — it would assert nothing
 * at all.
 *
 * So these tests flip the environment first, the same way `WebhookSignatureTest`
 * does, and restore it in tearDown.
 */
class WebhookCsrfExemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Restore before the next test class inherits a non-testing app.
        app()->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    /**
     * ⚠️ THE POSITIVE CONTROL, AND THE ONLY REASON THE REST MEAN ANYTHING.
     *
     * Every assertion below is "this route is NOT 419". If the environment
     * override silently stopped working, nothing would be 419 and all of them
     * would pass while testing nothing — the exact shape of a vacuous test.
     *
     * This proves CSRF is genuinely active under these conditions by naming a
     * route that is deliberately NOT exempt and requiring it to be rejected.
     */
    #[Test]
    public function csrf_is_actually_enforced_under_these_conditions(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])
            ->assertStatus(419);
    }

    /**
     * The two gateways this fix is about. Kept separate from the regression
     * check below so a failure names which half broke.
     */
    #[Test]
    public function razorpay_and_cashfree_webhooks_are_exempt_from_csrf(): void
    {
        app()->detectEnvironment(fn () => 'production');

        foreach (['razorpay', 'cashfree'] as $gateway) {
            $this->postJson("/webhooks/{$gateway}", [])
                ->assertStatus(503);
        }
    }

    /**
     * The three that already worked. A fix that exempts the new two by
     * loosening something shared would show up here.
     */
    #[Test]
    public function the_previously_exempt_gateway_webhooks_still_are(): void
    {
        app()->detectEnvironment(fn () => 'production');

        foreach (['stripe', 'paypal'] as $gateway) {
            $this->postJson("/webhooks/{$gateway}", [])
                ->assertStatus(503);
        }
    }

    /**
     * ⚠️ Asserted on the router's OWN except list, not by making a request.
     *
     * A request cannot distinguish "exempt" from "exempt for some other
     * reason" — a future middleware reordering, a global CSRF disable, or an
     * environment leak would all keep the assertions above green while the
     * except list itself was empty. This names the list directly.
     */
    #[Test]
    public function the_csrf_except_list_names_every_billing_gateway_webhook(): void
    {
        $middleware = app(VerifyCsrfToken::class);
        $inExceptArray = new \ReflectionMethod($middleware, 'inExceptArray');

        foreach (['stripe', 'paypal', 'razorpay', 'cashfree'] as $gateway) {
            $request = Request::create("/webhooks/{$gateway}", 'POST');

            $this->assertTrue(
                $inExceptArray->invoke($middleware, $request),
                "webhooks/{$gateway} is missing from the CSRF except list in bootstrap/app.php — "
                .'a real gateway callback to it would be rejected with 419 before reaching the controller.'
            );
        }
    }
}
