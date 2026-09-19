<?php

namespace Tests\Feature\Restaurant;

use App\Events\ContactCreated;
use App\Modules\Restaurant\Jobs\ProcessPosWebhookEventJob;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\PetpoojaOrderIngestionService;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Phase 2, slice 2 — the WHOLE path with nothing faked but the outside world:
 * a real HTTP webhook → `PetpoojaWebhookController` → durable event →
 * `ProcessPosWebhookEventJob` (the test suite's QUEUE_CONNECTION=sync runs it
 * inline) → `PetpoojaOrderIngestionService` → `restaurant_bills`.
 *
 * Payloads use the DOCUMENTED Petpooja Global API shape
 * (`properties.Customer/Order/OrderItem/Tax/Discount`, top-level `event` and
 * `token`) taken from the official PDF, not the pre-documentation shape the
 * Phase 1B ingress fixtures guessed at.
 */
class PetpoojaOrderProcessingEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/webhooks/pos/petpooja';

    private function liveConnection(?string $outletTimezone = 'Asia/Kolkata'): PosConnection
    {
        $outlet = RestaurantOutlet::factory()->create(['timezone' => $outletTimezone]);
        $connection = PosConnection::factory()->create([
            'workspace_id' => $outlet->workspace_id,
            'outlet_id' => $outlet->id,
            'provider' => PosConnection::PROVIDER_PETPOOJA,
            'external_ref' => 'cp81ghin',
            'status' => PosConnection::STATUS_CONNECTED,
            'default_phone_country' => 'IN',
        ]);
        $connection->forceFill(['webhook_secret_hash' => hash('sha256', 'e2e-token')])->save();

        return $connection;
    }

    /**
     * The "Order with Discounts & Add-ons" sample from the Global API PDF,
     * with the connection's restID/token and a valid Indian mobile number.
     *
     * @param  array<string, mixed>  $orderOverrides
     * @return array<string, mixed>
     */
    private function documentedPayload(PosConnection $connection, array $orderOverrides = []): array
    {
        return [
            'token' => 'e2e-token',
            'properties' => [
                'Restaurant' => [
                    'res_name' => 'Integration-SakhraniRohan-Demo',
                    'address' => 'Ahmedabad',
                    'contact_information' => '7228956676',
                    'restID' => $connection->external_ref,
                ],
                'Customer' => ['name' => 'Rohan', 'address' => 'Ahmedabad', 'phone' => '8630026021', 'gstin' => '1234678543345'],
                'Order' => array_replace([
                    'orderID' => 115,
                    'customer_invoice_id' => '115',
                    'delivery_charges' => 0,
                    'order_type' => 'Dine In',
                    'payment_type' => 'Card',
                    'table_no' => 'A15',
                    'discount_total' => 253.4,
                    'tax_total' => 0,
                    'round_off' => '0.40',
                    'core_total' => 1267,
                    'total' => 1034,
                    'created_on' => '2025-04-04 11:49:20',
                    'order_from' => 'POS',
                    'status' => 'Success',
                ], $orderOverrides),
                'Tax' => [],
                'Discount' => [['title' => 'Special Discount', 'type' => 'P', 'rate' => 20, 'amount' => 253.4]],
                'OrderItem' => [
                    ['name' => 'Chicken Tikka (3 Pieces)', 'itemid' => 136978234, 'price' => 100, 'quantity' => 1, 'total' => 100,
                        'addon' => [['group_name' => 'Chicken', 'name' => 'Some Chutny', 'price' => 5, 'quantity' => '1']]],
                ],
            ],
            'event' => 'orderdetails',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function pushOrder(array $payload): TestResponse
    {
        return $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));
    }

    #[Test]
    public function a_documented_order_push_becomes_a_processed_bill_with_the_full_lifecycle_recorded(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $connection = $this->liveConnection();

        $this->pushOrder($this->documentedPayload($connection))->assertOk()->assertExactJson(['status' => 'ok']);

        $event = DB::table('pos_webhook_events')->where('connection_id', $connection->id)->first();
        $this->assertSame('processed', $event->processing_status);
        $this->assertSame(1, (int) $event->attempts);
        $this->assertNotNull($event->processing_started_at);
        $this->assertNotNull($event->processed_at);
        $this->assertNull($event->failed_at);
        $this->assertNull($event->failure_reason);

        $bill = DB::table('restaurant_bills')->where('connection_id', $connection->id)->first();
        $this->assertNotNull($bill);
        $this->assertSame('115', $bill->external_order_id);
        $this->assertSame($connection->workspace_id, $bill->workspace_id, 'workspace_id must come from the authenticated connection.');
        $this->assertSame($connection->outlet_id, $bill->outlet_id, 'outlet_id must come from the authenticated connection.');
        $this->assertSame($event->id, $bill->webhook_event_id);
        $this->assertSame('Rohan', $bill->customer_name);
        $this->assertSame('8630026021', $bill->customer_phone_raw);
        $this->assertEquals(1034, $bill->total);
        $this->assertEquals(1267, $bill->core_total);
        $this->assertEquals(253.4, $bill->discount_total);
        $this->assertEquals(0, $bill->tax_total);
        // The outlet is Asia/Kolkata (UTC+5:30), so Petpooja's timezone-less
        // "11:49:20" is 06:19:20 UTC — and the raw value is kept verbatim.
        $this->assertSame('2025-04-04 06:19:20', $bill->placed_at);
        $this->assertSame('2025-04-04 11:49:20', $bill->source_created_on_raw);
        $this->assertSame('Success', $bill->source_order_status);
        $this->assertSame($event->received_at, $bill->received_at);
        $this->assertSame('Special Discount', json_decode($bill->discounts, true)[0]['title']);
        $this->assertSame('Chicken Tikka (3 Pieces)', json_decode($bill->order_items, true)[0]['name']);

        $contact = DB::table('contacts')->where('id', $bill->contact_id)->first();
        $this->assertSame('+918630026021', $contact->phone_e164);

        $audit = DB::table('audit_logs')->where('action', 'restaurant.bill.processed')->first();
        $this->assertNotNull($audit, 'Worker-originated processing must be audited via logSystem().');
        $this->assertSame($connection->workspace_id, $audit->workspace_id);
        $this->assertNull($audit->actor_admin_id);
        $this->assertNull($audit->ip);

        // Slice 2 is bill ingestion only.
        Http::assertNothingSent();
    }

    /**
     * Petpooja redelivers the exact same bytes on a retry: no second event,
     * no second bill, and — critically — the job is NOT run again.
     * A genuine correction (same orderID, changed total) is a different
     * payload hash: a new event, but it UPDATES the one bill in place.
     */
    #[Test]
    public function an_exact_retry_changes_nothing_and_a_correction_updates_the_same_bill(): void
    {
        $connection = $this->liveConnection();
        $original = $this->documentedPayload($connection);

        $this->pushOrder($original)->assertOk();
        $firstEvent = DB::table('pos_webhook_events')->where('connection_id', $connection->id)->first();

        // Exact retry.
        $this->pushOrder($original)->assertOk();

        $this->assertSame(1, DB::table('pos_webhook_events')->count());
        $this->assertSame(1, DB::table('restaurant_bills')->count());
        $this->assertSame(1, (int) DB::table('pos_webhook_events')->where('id', $firstEvent->id)->value('attempts'), 'An exact retry must not re-run the job.');

        // Correction.
        $this->pushOrder($this->documentedPayload($connection, ['total' => 1200]))->assertOk();

        $this->assertSame(2, DB::table('pos_webhook_events')->count());
        $this->assertSame(2, DB::table('pos_webhook_events')->where('processing_status', 'processed')->count());
        $this->assertSame(1, DB::table('restaurant_bills')->count(), 'A correction must update the bill, never duplicate it.');

        $bill = DB::table('restaurant_bills')->first();
        $this->assertEquals(1200, $bill->total);
        $this->assertNotSame($firstEvent->id, $bill->webhook_event_id, 'The bill should now point at the corrected event.');
    }

    /**
     * ⚠️ The webhook's HTTP contract depends ONLY on the event being durably
     * captured. A structurally invalid payload is a PERMANENT failure: the job
     * marks the event `failed` on its first execution, without throwing, so
     * there is no retry and (trivially) no 500.
     */
    #[Test]
    public function a_structurally_invalid_payload_fails_permanently_after_one_execution_and_still_returns_ok(): void
    {
        $connection = $this->liveConnection();
        $payload = $this->documentedPayload($connection);
        unset($payload['properties']['Order']['orderID']);

        $this->pushOrder($payload)->assertOk()->assertExactJson(['status' => 'ok']);

        $event = DB::table('pos_webhook_events')->where('connection_id', $connection->id)->first();
        $this->assertNotNull($event, 'The event must be durably captured even though it can never be processed.');
        $this->assertSame('failed', $event->processing_status, 'A deterministic defect must not be left retryable.');
        $this->assertSame(1, (int) $event->attempts, 'Exactly one execution — no retries.');
        $this->assertNotNull($event->failed_at);
        $this->assertStringContainsString('properties.Order.orderID', (string) $event->failure_reason);
        $this->assertSame(0, DB::table('restaurant_bills')->count());
    }

    /**
     * The other half of the contract: a TRANSIENT failure (here a simulated
     * database outage). Under `sync` the job runs inline and throws; that must
     * never become a 500 either, and the event must stay retryable.
     */
    #[Test]
    public function a_transient_processing_failure_never_turns_an_accepted_webhook_into_a_500(): void
    {
        $connection = $this->liveConnection();
        $this->mock(PetpoojaOrderIngestionService::class, function ($mock) {
            $mock->shouldReceive('ingest')->andThrow(new \RuntimeException('database unavailable'));
        });

        $this->pushOrder($this->documentedPayload($connection))->assertOk()->assertExactJson(['status' => 'ok']);

        $event = DB::table('pos_webhook_events')->where('connection_id', $connection->id)->first();
        $this->assertSame('pending', $event->processing_status, 'A transient failure must leave the event retryable.');
        $this->assertSame(1, (int) $event->attempts);
        $this->assertNull($event->failed_at);
        $this->assertSame('database unavailable', $event->failure_reason);
    }

    /** Positive control for both: same route, valid payload, succeeds. */
    #[Test]
    public function the_same_route_with_a_valid_payload_still_processes(): void
    {
        $connection = $this->liveConnection();

        $this->pushOrder($this->documentedPayload($connection))->assertOk();

        $this->assertSame(1, DB::table('restaurant_bills')->count());
    }

    #[Test]
    public function a_cancelled_order_push_is_stored_with_its_cancelled_source_status(): void
    {
        $connection = $this->liveConnection();

        $this->pushOrder($this->documentedPayload($connection, ['status' => 'Cancelled']))->assertOk();

        $bill = DB::table('restaurant_bills')->where('connection_id', $connection->id)->first();
        $this->assertNotNull($bill, 'A cancelled order is still a stored bill.');
        $this->assertSame('Cancelled', $bill->source_order_status);
    }

    /**
     * The documented `created_on` carries no timezone. With an outlet that has
     * none configured it must not be read as UTC: the raw value is preserved,
     * `placed_at` stays null, and `received_at` orders the bill.
     */
    #[Test]
    public function a_timezone_less_created_on_on_an_outlet_without_a_timezone_is_never_read_as_utc(): void
    {
        $connection = $this->liveConnection(outletTimezone: null);

        $this->pushOrder($this->documentedPayload($connection))->assertOk();

        $bill = DB::table('restaurant_bills')->where('connection_id', $connection->id)->first();
        $event = DB::table('pos_webhook_events')->where('connection_id', $connection->id)->first();
        $this->assertSame('2025-04-04 11:49:20', $bill->source_created_on_raw);
        $this->assertNull($bill->placed_at);
        $this->assertSame($event->received_at, $bill->received_at);
    }

    /**
     * ⚠️ SLICE 2 IS OUTBOUND-FREE. Processing an order may write a bill, a
     * contact and audit entries — and NOTHING that leaves the platform or
     * schedules something that will: no WhatsApp, digital-bill, feedback,
     * automation or webhook-delivery job, no HTTP request, no mail, no
     * notification, no `ContactCreated` (which would trigger `contact.created`
     * automations and outbound webhooks).
     *
     * The queue is faked so every job pushed is recorded, then the ONE job the
     * controller legitimately pushed is run for real. If processing pushed
     * anything else — including a queued event listener — the count moves.
     */
    #[Test]
    public function processing_an_order_dispatches_no_whatsapp_digital_bill_feedback_automation_or_delivery_work(): void
    {
        $queue = Queue::fake();
        Http::fake();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Event::fake([ContactCreated::class]);

        $connection = $this->liveConnection();

        $this->pushOrder($this->documentedPayload($connection))->assertOk();

        Queue::assertPushed(ProcessPosWebhookEventJob::class, 1);
        /** @var ProcessPosWebhookEventJob $job */
        $job = $queue->pushed(ProcessPosWebhookEventJob::class)->first();

        WorkspaceContext::for($connection->workspace_id, fn () => app()->call([$job, 'handle']));

        // The order really was processed…
        $this->assertSame(1, DB::table('restaurant_bills')->count());
        $this->assertSame('processed', DB::table('pos_webhook_events')->value('processing_status'));
        $this->assertSame(1, DB::table('contacts')->count(), 'Test setup: a contact was resolved, which is the path most likely to trigger side effects.');

        // …and nothing else was scheduled or sent.
        Queue::assertCount(1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Event::assertNotDispatched(ContactCreated::class);

        foreach (['messages', 'conversations', 'automation_runs', 'campaign_recipients', 'webhook_deliveries'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Processing an order must not create any `{$table}` row.");
        }
    }
}
