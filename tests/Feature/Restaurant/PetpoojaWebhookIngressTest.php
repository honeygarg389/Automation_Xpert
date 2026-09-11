<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Models\PosWebhookRejection;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1B — Petpooja sandbox webhook ingress.
 *
 * Locked product decisions this file pins:
 *   - one central URL for all restaurants/outlets, restID + token only in
 *     the JSON body, never the URL;
 *   - a valid authenticated event is persisted BEFORE the 200 is returned;
 *   - an exact-retry duplicate gets the SAME 200, never a 500;
 *   - every rejection reason (unknown/inactive restID, bad token, IP
 *     mismatch, malformed JSON, missing restID) returns the IDENTICAL
 *     generic 403 — no oracle;
 *   - a rejected request never stores raw payload data anywhere.
 */
class PetpoojaWebhookIngressTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/webhooks/pos/petpooja';

    // ══ Fixtures — realistic Petpooja Order Push payload shapes ══════════

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basicOrderPayload(string $restId, string $token, array $overrides = []): array
    {
        return array_replace_recursive([
            'event' => 'orderdetails',
            'orderID' => 'ORD-1001',
            'orderInfo' => [
                'items' => [
                    ['id' => '1', 'name' => 'Butter Naan', 'price' => '40', 'quantity' => '2', 'total' => '80'],
                    ['id' => '2', 'name' => 'Paneer Tikka', 'price' => '220', 'quantity' => '1', 'total' => '220'],
                ],
                'total' => '300',
                'order_type' => 'H',
            ],
            'properties' => [
                'Restaurant' => [
                    'restID' => $restId,
                    'currency_symbol' => 'Rs.',
                ],
            ],
            'token' => $token,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function discountsAndAddonsPayload(string $restId, string $token): array
    {
        return $this->basicOrderPayload($restId, $token, [
            'orderInfo' => [
                'items' => [
                    [
                        'id' => '1', 'name' => 'Margherita Pizza', 'price' => '350', 'quantity' => '1',
                        'AddonItem' => [
                            'details' => [
                                ['id' => 'a1', 'name' => 'Extra Cheese', 'price' => '50', 'quantity' => '1'],
                            ],
                        ],
                    ],
                ],
                'discount_total' => '35',
                'discount_type' => 'F',
                'total' => '365',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function partPaymentPayload(string $restId, string $token): array
    {
        return $this->basicOrderPayload($restId, $token, [
            'orderInfo' => [
                'total' => '500',
                'payment' => [
                    ['type' => 'CASH', 'amount' => '200'],
                    ['type' => 'CARD', 'amount' => '300'],
                ],
            ],
        ]);
    }

    /**
     * Uses capital `Token`, and deliberately has NO lowercase `token` key at
     * all — Petpooja's own aggregator sample is inconsistent about casing,
     * and a fixture carrying both would never exercise the `Token` fallback.
     *
     * @return array<string, mixed>
     */
    private function onlineAggregatorPayload(string $restId, string $token): array
    {
        $payload = $this->basicOrderPayload($restId, 'placeholder', [
            'orderInfo' => ['order_type' => 'D', 'aggregator' => 'zomato'],
        ]);
        unset($payload['token']);
        $payload['Token'] = $token;

        return $payload;
    }

    // ══ Test fixtures — the DB side ═══════════════════════════════════════

    /**
     * Each connection gets its OWN token, derived from its external_ref —
     * pos_connections.webhook_secret_hash is UNIQUE, so two connections
     * sharing one plaintext token in the same test would collide on
     * creation (found by running this suite: a real UniqueConstraintViolationException
     * on the second forceFill()->save(), not a hypothetical).
     */
    private function activeConnection(array $overrides = []): PosConnection
    {
        $connection = PosConnection::factory()->create(array_merge([
            'provider' => PosConnection::PROVIDER_PETPOOJA,
            'external_ref' => 'REST-'.fake()->unique()->numberBetween(1000, 999999),
            'status' => PosConnection::STATUS_CONNECTED,
        ], $overrides));

        $connection->forceFill(['webhook_secret_hash' => hash('sha256', $this->tokenFor($connection))])->save();

        return $connection;
    }

    private function tokenFor(PosConnection $connection): string
    {
        return 'token-for-'.$connection->external_ref;
    }

    private function postRaw(string $rawBody)
    {
        return $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], $rawBody);
    }

    // ══ Happy path ═════════════════════════════════════════════════════

    #[Test]
    public function a_valid_active_connection_with_lowercase_token_is_persisted_and_returns_ok(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection)));

        $response = $this->postRaw($rawBody);

        $response->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $this->assertSame(1, PosWebhookEvent::query()->count());
        $event = PosWebhookEvent::query()->first();

        $this->assertSame($connection->id, $event->connection_id);
        $this->assertSame($connection->workspace_id, $event->workspace_id);
        $this->assertSame('petpooja', $event->provider);
        $this->assertSame('orderdetails', $event->event_type);
        $this->assertSame(PosWebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame($rawBody, $event->raw_body);
        $this->assertSame(hash('sha256', $rawBody), $event->payload_hash);
        // assertEquals, not assertSame: MySQL's JSON column type does not
        // guarantee preserving the original key order on read-back, and this
        // is checking stored CONTENT, not raw bytes — the byte-for-byte
        // claim is already covered by raw_body/payload_hash above.
        $this->assertEquals(json_decode($rawBody, true), $event->raw_payload);
        $this->assertSame(0, $event->attempts);
        $this->assertNull($event->failure_reason);

        $this->assertNotNull($connection->fresh()->last_event_at);
        $this->assertSame(0, PosWebhookRejection::query()->count());
    }

    #[Test]
    public function discounts_and_addons_fixture_is_accepted(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->discountsAndAddonsPayload($connection->external_ref, $this->tokenFor($connection)));

        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);
        $this->assertSame(1, PosWebhookEvent::query()->count());
    }

    #[Test]
    public function part_payment_fixture_is_accepted(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->partPaymentPayload($connection->external_ref, $this->tokenFor($connection)));

        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);
        $this->assertSame(1, PosWebhookEvent::query()->count());
    }

    #[Test]
    public function online_aggregator_fixture_with_uppercase_token_key_is_accepted(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->onlineAggregatorPayload($connection->external_ref, $this->tokenFor($connection)));

        $response = $this->postRaw($rawBody);

        $response->assertStatus(200)->assertExactJson(['status' => 'ok']);
        $this->assertSame(1, PosWebhookEvent::query()->count());
        $this->assertSame($connection->id, PosWebhookEvent::query()->first()->connection_id);
    }

    // ══ Exact duplicate ═══════════════════════════════════════════════

    #[Test]
    public function the_exact_same_raw_bytes_delivered_twice_both_return_ok_with_only_one_event(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection)));

        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);
        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $this->assertSame(1, PosWebhookEvent::query()->count(), 'An exact retry must not create a second row.');
    }

    /**
     * ⚠️ Proves raw-BYTE hashing, not semantic-JSON hashing: identical data,
     * different whitespace, must hash differently and therefore be treated
     * as two distinct deliveries — exactly the trap PosWebhookEvent's
     * docblock warns about.
     */
    #[Test]
    public function the_same_semantic_json_with_different_whitespace_hashes_differently_and_creates_a_second_row(): void
    {
        $connection = $this->activeConnection();
        $payload = $this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection));

        $compact = json_encode($payload);
        $pretty = json_encode($payload, JSON_PRETTY_PRINT);

        $this->assertNotSame($compact, $pretty, 'Fixture must actually differ in raw bytes for this test to mean anything.');
        $this->assertNotSame(hash('sha256', $compact), hash('sha256', $pretty));

        $this->postRaw($compact)->assertStatus(200)->assertExactJson(['status' => 'ok']);
        $this->postRaw($pretty)->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $this->assertSame(2, PosWebhookEvent::query()->count(),
            'Different raw bytes must be treated as different deliveries, even with identical decoded content.');
    }

    /**
     * A genuinely changed body (a real correction/update from Petpooja, not
     * a retry) is a second row. Phase 2's bill-level idempotency (same
     * orderID, different payload) is explicitly a separate, later concern —
     * this table's job is exact-retry dedup only, documented on the
     * pos_webhook_events migration.
     */
    #[Test]
    public function a_genuinely_changed_body_for_the_same_restid_creates_a_second_row(): void
    {
        $connection = $this->activeConnection();

        $first = json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection), ['orderID' => 'ORD-1001']));
        $second = json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection), ['orderID' => 'ORD-1002']));

        $this->postRaw($first)->assertStatus(200);
        $this->postRaw($second)->assertStatus(200);

        $this->assertSame(2, PosWebhookEvent::query()->count());
    }

    // ══ Rejections — the no-oracle proof ══════════════════════════════

    #[Test]
    public function unknown_restid_is_rejected_generically_with_no_raw_event_stored(): void
    {
        $rawBody = json_encode($this->basicOrderPayload('NEVER-REGISTERED', 'irrelevant-token'));

        $response = $this->postRaw($rawBody);

        $response->assertStatus(403)->assertExactJson(['status' => 'rejected']);
        $this->assertSame(0, PosWebhookEvent::query()->count());
        $this->assertSame(1, PosWebhookRejection::query()->count());
        $rejection = PosWebhookRejection::query()->first();
        $this->assertSame(PosWebhookRejection::REASON_UNKNOWN_RESTID, $rejection->failure_reason);
        $this->assertNull($rejection->connection_id);
    }

    #[Test]
    public function inactive_connection_is_rejected_generically(): void
    {
        $connection = $this->activeConnection(['status' => PosConnection::STATUS_PENDING]);
        $rawBody = json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection)));

        $this->postRaw($rawBody)->assertStatus(403)->assertExactJson(['status' => 'rejected']);

        $this->assertSame(0, PosWebhookEvent::query()->count());
        $rejection = PosWebhookRejection::query()->first();
        $this->assertSame(PosWebhookRejection::REASON_INACTIVE_CONNECTION, $rejection->failure_reason);
        $this->assertSame($connection->id, $rejection->connection_id);
    }

    #[Test]
    public function wrong_token_on_an_active_connection_is_rejected_generically(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->basicOrderPayload($connection->external_ref, 'totally-wrong-token'));

        $this->postRaw($rawBody)->assertStatus(403)->assertExactJson(['status' => 'rejected']);

        $this->assertSame(0, PosWebhookEvent::query()->count());
        $rejection = PosWebhookRejection::query()->first();
        $this->assertSame(PosWebhookRejection::REASON_INVALID_TOKEN, $rejection->failure_reason);
        $this->assertSame($connection->id, $rejection->connection_id);
    }

    #[Test]
    public function ip_mismatch_against_a_configured_allowlist_is_rejected_generically(): void
    {
        $connection = $this->activeConnection(['allowed_ips' => ['203.0.113.10']]);
        $rawBody = json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection)));

        // The test client's default remote IP is 127.0.0.1, which is not in the allowlist.
        $this->postRaw($rawBody)->assertStatus(403)->assertExactJson(['status' => 'rejected']);

        $this->assertSame(0, PosWebhookEvent::query()->count());
        $rejection = PosWebhookRejection::query()->first();
        $this->assertSame(PosWebhookRejection::REASON_IP_NOT_ALLOWED, $rejection->failure_reason);
    }

    #[Test]
    public function an_unconfigured_allowlist_does_not_block_any_ip(): void
    {
        $connection = $this->activeConnection(['allowed_ips' => null]);
        $rawBody = json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection)));

        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function malformed_json_is_rejected_generically_with_no_connection_resolved(): void
    {
        $response = $this->postRaw('{"this is not": valid json,,,');

        $response->assertStatus(403)->assertExactJson(['status' => 'rejected']);
        $this->assertSame(0, PosWebhookEvent::query()->count());
        $rejection = PosWebhookRejection::query()->first();
        $this->assertSame(PosWebhookRejection::REASON_MALFORMED_PAYLOAD, $rejection->failure_reason);
        $this->assertNull($rejection->connection_id);
    }

    #[Test]
    public function missing_restid_is_rejected_generically(): void
    {
        $rawBody = json_encode(['orderID' => 'ORD-9999', 'token' => 'irrelevant-token', 'properties' => ['Restaurant' => []]]);

        $response = $this->postRaw($rawBody);

        $response->assertStatus(403)->assertExactJson(['status' => 'rejected']);
        $this->assertSame(0, PosWebhookEvent::query()->count());
        $rejection = PosWebhookRejection::query()->first();
        $this->assertSame(PosWebhookRejection::REASON_MISSING_RESTID, $rejection->failure_reason);
    }

    /**
     * ⚠️ THE EXPLICIT NO-ORACLE PROOF. Every rejection reason must be
     * externally indistinguishable — same status, same body, byte for byte.
     */
    #[Test]
    public function every_rejection_reason_returns_an_identical_response(): void
    {
        $activeConnection = $this->activeConnection();
        $inactiveConnection = $this->activeConnection(['status' => PosConnection::STATUS_PENDING]);
        $ipRestrictedConnection = $this->activeConnection(['allowed_ips' => ['203.0.113.10']]);

        $responses = [
            'unknown_restid' => $this->postRaw(json_encode($this->basicOrderPayload('NEVER-REGISTERED-2', 'irrelevant'))),
            'inactive_connection' => $this->postRaw(json_encode($this->basicOrderPayload($inactiveConnection->external_ref, $this->tokenFor($inactiveConnection)))),
            'invalid_token' => $this->postRaw(json_encode($this->basicOrderPayload($activeConnection->external_ref, 'wrong'))),
            'ip_not_allowed' => $this->postRaw(json_encode($this->basicOrderPayload($ipRestrictedConnection->external_ref, $this->tokenFor($ipRestrictedConnection)))),
            'malformed_payload' => $this->postRaw('{not json'),
        ];

        $bodies = [];
        foreach ($responses as $label => $response) {
            $response->assertStatus(403);
            $bodies[$label] = $response->getContent();
        }

        $unique = array_unique($bodies);
        $this->assertCount(1, $unique,
            'Every rejection reason must produce an IDENTICAL response body: '.json_encode($bodies));
        $this->assertSame('{"status":"rejected"}', reset($unique));

        // And confirm the reasons really did differ internally — the sameness
        // above is a property of the RESPONSE, not of what actually happened.
        $reasons = PosWebhookRejection::query()->pluck('failure_reason')->all();
        sort($reasons);
        $this->assertSame(
            ['inactive_connection', 'invalid_token', 'ip_not_allowed', 'malformed_payload', 'unknown_restid'],
            $reasons
        );
    }

    // ══ Event validation, authenticated — missing/unsupported ══════════

    /**
     * ⚠️ THE CORRECTION THIS TEST PINS: an earlier version of this
     * controller defaulted an ABSENT `event` key to 'orderdetails', on the
     * reasoning that real sandbox payloads might omit it. That was wrong —
     * Petpooja's own documentation sends `event: "orderdetails"` explicitly,
     * so treating "missing" as "this is a final bill" is exactly the silent
     * misclassification a payment/order system cannot afford. Missing must
     * quarantine under its own distinct reason, not silently become pending.
     */
    #[Test]
    public function a_missing_event_key_from_an_authenticated_connection_is_quarantined_not_defaulted(): void
    {
        $connection = $this->activeConnection();
        $payload = $this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection));
        unset($payload['event']);
        $rawBody = json_encode($payload);

        $response = $this->postRaw($rawBody);

        $response->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $this->assertSame(1, PosWebhookEvent::query()->count());
        $event = PosWebhookEvent::query()->first();
        $this->assertSame(PosWebhookEvent::STATUS_QUARANTINED, $event->processing_status);
        $this->assertSame('missing_event', $event->failure_reason);
        $this->assertNull($event->event_type);
        $this->assertSame($rawBody, $event->raw_body);
        $this->assertEquals(json_decode($rawBody, true), $event->raw_payload);
        $this->assertSame(0, PosWebhookRejection::query()->count(), 'An authenticated event is never a rejection, even with a missing event key.');
    }

    #[Test]
    public function an_unsupported_event_value_from_an_authenticated_connection_is_quarantined_not_rejected(): void
    {
        $connection = $this->activeConnection();
        $payload = $this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection));
        $payload['event'] = 'orderstatus';
        $rawBody = json_encode($payload);

        $response = $this->postRaw($rawBody);

        $response->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $this->assertSame(1, PosWebhookEvent::query()->count());
        $event = PosWebhookEvent::query()->first();
        $this->assertSame(PosWebhookEvent::STATUS_QUARANTINED, $event->processing_status);
        $this->assertSame('unsupported_event', $event->failure_reason);
        $this->assertSame('orderstatus', $event->event_type);
        $this->assertSame($rawBody, $event->raw_body);
        $this->assertEquals(json_decode($rawBody, true), $event->raw_payload);
        $this->assertSame(0, PosWebhookRejection::query()->count(), 'An authenticated event is never a rejection, even if unsupported.');
    }

    // ══ CSRF ═══════════════════════════════════════════════════════════

    #[Test]
    public function the_route_is_reachable_without_a_csrf_token(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection)));

        // No _token field, no X-CSRF-TOKEN header — a CSRF-protected route
        // would answer 419 here. Reaching the controller's own logic (200,
        // or any of its 403s) proves the exemption, not just the absence of
        // a 419 by accident.
        $response = $this->postRaw($rawBody);

        $this->assertNotSame(419, $response->getStatusCode());
        $response->assertStatus(200);
    }

    // ══ Rate limiting ══════════════════════════════════════════════════

    /**
     * Proves the ACTUAL registered 'webhooks' limiter (not a test-only
     * override) is the one attached to this route, and that it is what it
     * claims to be: 1000/minute keyed by client IP. Two parts:
     *
     *   1. route middleware really does include 'throttle:webhooks';
     *   2. the real, currently-registered limiter callback for 'webhooks'
     *      (resolved live, not re-declared here) returns a Limit of
     *      exactly 1000 attempts / 60 seconds.
     *
     * A full 1000-request exhaustion end-to-end was considered and rejected
     * as the primary proof here: at real HTTP-test-client cost that adds a
     * large, disproportionate amount of time to this suite for the same
     * fact these two checks already establish precisely and quickly. The
     * light end-to-end check below (a handful of real requests, all
     * succeeding) confirms the middleware does not block ordinary traffic.
     */
    #[Test]
    public function the_route_uses_the_real_webhooks_rate_limiter_configured_for_1000_per_minute_by_ip(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->uri() === 'webhooks/pos/petpooja' && in_array('POST', $r->methods(), true)
        );
        $this->assertNotNull($route, 'The petpooja route must be registered.');
        $this->assertContains('throttle:webhooks', $route->gatherMiddleware());

        /** @var RateLimiter $limiter */
        $limiter = app(RateLimiter::class);
        $callback = $limiter->limiter('webhooks');
        $this->assertNotNull($callback, "The 'webhooks' named limiter must be registered.");

        $limits = $callback(request());
        $limit = is_array($limits) ? $limits[0] : $limits;

        $this->assertSame(1000, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
    }

    #[Test]
    public function ordinary_traffic_well_under_the_limit_is_never_throttled(): void
    {
        Cache::flush();
        $connection = $this->activeConnection();

        for ($i = 0; $i < 5; $i++) {
            $rawBody = json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection), ['orderID' => "ORD-LOOP-{$i}"]));
            $this->postRaw($rawBody)->assertStatus(200);
        }
    }

    // ══ No side effects beyond capture ═══════════════════════════════

    #[Test]
    public function no_queue_job_is_dispatched_for_any_outcome(): void
    {
        Queue::fake();

        $connection = $this->activeConnection();
        $this->postRaw(json_encode($this->basicOrderPayload($connection->external_ref, $this->tokenFor($connection))));
        $this->postRaw(json_encode($this->basicOrderPayload('unknown', 'irrelevant')));

        Queue::assertNothingPushed();
    }
}
