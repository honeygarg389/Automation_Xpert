<?php

namespace Tests\Feature\Restaurant;

use App\Events\ContactCreated;
use App\Models\Workspace;
use App\Modules\Restaurant\Exceptions\UnprocessablePosWebhookEventException;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\PetpoojaOrderIngestionService;
use App\Modules\Shared\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2, slice 2. Exercises {@see PetpoojaOrderIngestionService} directly
 * (no queue involved) against the DOCUMENTED Petpooja Global API paths this
 * slice was scoped to: properties.Customer.name/phone, properties.Order.
 * orderID/total/core_total/discount_total/tax_total/created_on,
 * properties.OrderItem, properties.Tax, properties.Discount.
 *
 * `ingest()` reads a `Contact`, which IS `BelongsToWorkspace`-scoped — every
 * test here runs inside `WorkspaceContext::for()` to simulate exactly what
 * `ProcessPosWebhookEventJob`'s middleware does before calling this service.
 * Job-level middleware wiring itself is covered separately in
 * ProcessPosWebhookEventJobTest via a real dispatch.
 */
class PetpoojaOrderIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PetpoojaOrderIngestionService
    {
        return app(PetpoojaOrderIngestionService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderdetailsPayload(string $restId, array $overrides = []): array
    {
        return array_replace_recursive([
            'token' => 'irrelevant-for-this-service',
            'properties' => [
                'Restaurant' => [
                    'res_name' => 'Integration-SakhraniRohan-Demo',
                    'address' => 'Ahmedabad',
                    'contact_information' => '7228956676',
                    'restID' => $restId,
                ],
                'Customer' => [
                    'name' => 'Rohan',
                    'address' => 'Ahmedabad',
                    'phone' => '8630026021',
                    'gstin' => '1234678543345',
                ],
                'Order' => [
                    'orderID' => 114,
                    'order_type' => 'Dine In',
                    'payment_type' => 'Cash',
                    'discount_total' => 0,
                    'tax_total' => 0,
                    'core_total' => 1158,
                    'total' => 1158,
                    'created_on' => '2025-04-04 11:45:35',
                    'status' => 'Success',
                ],
                'Tax' => [],
                'Discount' => [],
                'OrderItem' => [
                    ['name' => 'Butter Naan', 'itemid' => 1, 'price' => 40, 'quantity' => 2, 'total' => 80],
                ],
            ],
            'event' => 'orderdetails',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pendingEvent(PosConnection $connection, array $payload): PosWebhookEvent
    {
        return PosWebhookEvent::factory()->create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'provider' => PosConnection::PROVIDER_PETPOOJA,
            'event_type' => 'orderdetails',
            'processing_status' => PosWebhookEvent::STATUS_PENDING,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'raw_payload' => $payload,
        ]);
    }

    #[Test]
    public function it_ingests_the_documented_order_fields_into_a_restaurant_bill(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $payload = $this->orderdetailsPayload($connection->external_ref);
        $event = $this->pendingEvent($connection, $payload);

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $bill = DB::table('restaurant_bills')->where('id', $billId)->first();

        $this->assertNotNull($bill);
        $this->assertSame($workspace->id, $bill->workspace_id);
        $this->assertSame($connection->id, $bill->connection_id);
        $this->assertSame($event->id, $bill->webhook_event_id);
        $this->assertSame('petpooja', $bill->provider);
        $this->assertSame('114', $bill->external_order_id);
        $this->assertSame('Rohan', $bill->customer_name);
        $this->assertSame('8630026021', $bill->customer_phone_raw);
        $this->assertEquals(1158, $bill->total);
        $this->assertEquals(1158, $bill->core_total);
        $this->assertEquals(0, $bill->discount_total);
        $this->assertEquals(0, $bill->tax_total);
        // No outlet on this connection -> no timezone -> the order time is
        // UNKNOWN. It must not be guessed (as UTC or as "now"); the raw value
        // and the webhook's receive time are what survive.
        $this->assertNull($bill->placed_at);
        $this->assertSame('2025-04-04 11:45:35', $bill->source_created_on_raw);
        $this->assertSame('Success', $bill->source_order_status);
        $this->assertSame($event->received_at->format('Y-m-d H:i:s'), $bill->received_at);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $bill->public_token, 'The raw-query upsert path must generate the same opaque public token as Eloquent creation.');

        $items = json_decode($bill->order_items, true);
        $this->assertCount(1, $items);
        $this->assertSame('Butter Naan', $items[0]['name']);
    }

    #[Test]
    public function it_resolves_and_links_a_contact_using_the_connections_default_phone_country(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref));

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $bill = DB::table('restaurant_bills')->where('id', $billId)->first();
        $this->assertNotNull($bill->contact_id);

        $contact = WorkspaceContext::for($workspace->id, fn () => Contact::find($bill->contact_id));
        $this->assertNotNull($contact);
        $this->assertSame('+918630026021', $contact->phone_e164);
        $this->assertSame('Rohan', $contact->first_name);
        $this->assertSame('petpooja', $contact->source);
    }

    #[Test]
    public function it_never_dispatches_contact_created_when_resolving_a_new_contact(): void
    {
        Event::fake([ContactCreated::class]);

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref));

        WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        Event::assertNotDispatched(ContactCreated::class);
    }

    #[Test]
    public function a_blank_customer_phone_leaves_contact_id_null_and_still_persists_the_bill(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $payload = $this->orderdetailsPayload($connection->external_ref, [
            'properties' => ['Customer' => ['name' => '', 'phone' => '']],
        ]);
        $event = $this->pendingEvent($connection, $payload);

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $bill = DB::table('restaurant_bills')->where('id', $billId)->first();
        $this->assertNull($bill->contact_id);
        $this->assertSame(0, Contact::query()->count());
    }

    #[Test]
    public function a_phone_with_no_default_country_configured_leaves_contact_id_null_without_failing(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => null,
        ]);
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref));

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $bill = DB::table('restaurant_bills')->where('id', $billId)->first();
        $this->assertNotNull($bill, 'A phone that cannot be normalized must never fail bill persistence.');
        $this->assertNull($bill->contact_id);
    }

    // ══ Contact-safety hardening ══════════════════════════════════════════
    //
    // ⚠️ A POS bill must LINK to an existing contact, never REWRITE one.
    // ContactService::upsert() is an updateOrCreate — fine for its own callers
    // (imports, e-commerce sync) but wrong here: a cashier typing "Rohan" at
    // the till must not rename the customer "Priya Sharma" that an import
    // created, nor flip her `source` from 'import' to 'petpooja'.

    #[Test]
    public function an_existing_contact_is_linked_but_never_modified(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $existing = WorkspaceContext::for($workspace->id, fn () => Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+918630026021',
            'first_name' => 'Priya',
            'source' => 'import',
            'opt_in_whatsapp' => true,
        ]));
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref, [
            'properties' => ['Customer' => ['name' => 'Rohan']],
        ]));

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $this->assertSame($existing->id, DB::table('restaurant_bills')->where('id', $billId)->value('contact_id'));

        $fresh = WorkspaceContext::for($workspace->id, fn () => Contact::find($existing->id));
        $this->assertSame('Priya', $fresh->first_name, 'A POS bill must never rename an existing contact.');
        $this->assertSame('import', $fresh->source, 'A POS bill must never overwrite an existing contact\'s source.');
        $this->assertTrue((bool) $fresh->opt_in_whatsapp, 'A POS bill must never touch existing consent.');
        $this->assertSame(1, WorkspaceContext::for($workspace->id, fn () => Contact::withTrashed()->count()));
    }

    /**
     * UNIQUE(workspace_id, phone_e164) includes soft-deleted rows, so a naive
     * create() for a previously-deleted customer would hard-fail the whole
     * bill. It must also not RESURRECT a contact somebody deliberately
     * deleted merely because that person bought lunch again.
     */
    #[Test]
    public function a_soft_deleted_contact_is_linked_not_restored_and_never_fails_the_bill(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $deleted = WorkspaceContext::for($workspace->id, function () use ($workspace) {
            $contact = Contact::factory()->create([
                'workspace_id' => $workspace->id,
                'phone_e164' => '+918630026021',
                'first_name' => 'Deleted Customer',
            ]);
            $contact->delete();

            return $contact;
        });
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref));

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $this->assertNotNull(DB::table('restaurant_bills')->where('id', $billId)->first(), 'A soft-deleted contact must never fail bill persistence.');
        $this->assertSame($deleted->id, DB::table('restaurant_bills')->where('id', $billId)->value('contact_id'));
        $this->assertNotNull(DB::table('contacts')->where('id', $deleted->id)->value('deleted_at'), 'A POS bill must never resurrect a deleted contact.');
        $this->assertSame(1, DB::table('contacts')->where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function a_newly_created_petpooja_contact_enables_all_channel_opt_ins_without_recording_consent(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref));

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $contact = DB::table('contacts')->where('id', DB::table('restaurant_bills')->where('id', $billId)->value('contact_id'))->first();
        $this->assertNotNull($contact);
        $this->assertSame(1, (int) $contact->opt_in_whatsapp);
        $this->assertSame(1, (int) $contact->opt_in_sms);
        $this->assertSame(1, (int) $contact->opt_in_email);
        $this->assertNull($contact->whatsapp_consent_at);
        $this->assertNull($contact->whatsapp_consent_source);
    }

    /**
     * Petpooja's documented phone values are LOCAL DIGITS. "Normalize only
     * through the connection's default_phone_country" means a value that
     * arrives with its own '+' country code (undocumented) is NOT trusted to
     * bypass that setting — the bill persists with the raw value, unlinked.
     */
    #[Test]
    public function a_plus_prefixed_phone_is_never_linked_because_only_local_digits_are_normalized(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref, [
            'properties' => ['Customer' => ['phone' => '+14155550123']],
        ]));

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $bill = DB::table('restaurant_bills')->where('id', $billId)->first();
        $this->assertNotNull($bill);
        $this->assertNull($bill->contact_id);
        $this->assertSame('+14155550123', $bill->customer_phone_raw);
        $this->assertSame(0, DB::table('contacts')->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedPhones(): array
    {
        return [
            'letters' => ['not-a-phone'],
            'too short' => ['12345'],
            'too long' => ['123456789012345678'],
            'punctuation only' => ['---'],
        ];
    }

    #[Test]
    #[DataProvider('malformedPhones')]
    public function a_malformed_phone_never_fails_bill_persistence(string $phone): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref, [
            'properties' => ['Customer' => ['phone' => $phone]],
        ]));

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $bill = DB::table('restaurant_bills')->where('id', $billId)->first();
        $this->assertNotNull($bill, "Phone [{$phone}] must never fail bill persistence.");
        $this->assertNull($bill->contact_id);
    }

    /**
     * Two workers can resolve the same brand-new customer at the same moment;
     * the loser hits UNIQUE(workspace_id, phone_e164) on create. That must
     * recover by linking the winner's row, not fail the bill.
     */
    #[Test]
    public function losing_a_contact_creation_race_links_the_winner_instead_of_failing_the_bill(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref));

        $armed = true;
        $winnerId = null;
        Contact::creating(function () use (&$armed, &$winnerId, $workspace) {
            if (! $armed) {
                return;
            }
            $armed = false;
            // The "other worker" commits its contact between this worker's
            // existence check and its own INSERT.
            $winnerId = DB::table('contacts')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'phone_e164' => '+918630026021',
                'first_name' => 'Winner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $this->assertNotNull($winnerId, 'Test setup: the race hook never fired.');
        $this->assertSame($winnerId, DB::table('restaurant_bills')->where('id', $billId)->value('contact_id'));
        $this->assertSame(1, DB::table('contacts')->where('workspace_id', $workspace->id)->count());
    }

    /**
     * Slice 2 is bill ingestion ONLY: no digital bill, no feedback request,
     * no WhatsApp send, no outbound webhook, no external HTTP of any kind.
     */
    #[Test]
    public function ingestion_makes_no_external_http_request(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref));

        WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        Http::assertNothingSent();
    }

    // ══ Source status and order time: preserved, never guessed ═════════════

    /**
     * @return array{0: Workspace, 1: PosConnection}
     */
    private function connectionWithOutletTimezone(?string $timezone): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id, 'timezone' => $timezone]);
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'outlet_id' => $outlet->id,
            'default_phone_country' => 'IN',
        ]);

        return [$workspace, $connection];
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     */
    private function ingestBill(Workspace $workspace, PosConnection $connection, array $orderOverrides): object
    {
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref, [
            'properties' => ['Order' => $orderOverrides],
        ]));

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        return DB::table('restaurant_bills')->where('id', $billId)->first();
    }

    #[Test]
    public function a_cancelled_order_is_stored_as_a_bill_with_its_cancelled_source_status(): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone('Asia/Kolkata');

        $bill = $this->ingestBill($workspace, $connection, ['status' => 'Cancelled']);

        $this->assertSame('Cancelled', $bill->source_order_status);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function orderStatuses(): array
    {
        return [
            'Success' => [['status' => 'Success'], 'Success'],
            'Cancelled' => [['status' => 'Cancelled'], 'Cancelled'],
            'trimmed' => [['status' => '  Cancelled  '], 'Cancelled'],
            'unknown value survives verbatim' => [['status' => 'Refunded'], 'Refunded'],
            'blank is absent' => [['status' => '   '], null],
            'non-string is absent' => [['status' => ['x']], null],
            'capped to the column width' => [['status' => str_repeat('A', 40)], str_repeat('A', 32)],
        ];
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     */
    #[Test]
    #[DataProvider('orderStatuses')]
    public function the_source_order_status_is_stored_as_petpooja_sent_it(array $orderOverrides, ?string $expected): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone('Asia/Kolkata');

        $this->assertSame($expected, $this->ingestBill($workspace, $connection, $orderOverrides)->source_order_status);
    }

    #[Test]
    public function a_missing_status_is_stored_as_null_not_defaulted_to_success(): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone('Asia/Kolkata');
        $payload = $this->orderdetailsPayload($connection->external_ref);
        unset($payload['properties']['Order']['status']);
        $event = $this->pendingEvent($connection, $payload);

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $this->assertNull(DB::table('restaurant_bills')->where('id', $billId)->value('source_order_status'));
    }

    /** The exact case the requirement names: a timezone-less `created_on`. */
    #[Test]
    public function a_timezone_less_created_on_is_kept_raw_and_never_interpreted_as_utc_when_the_outlet_has_no_timezone(): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone(null);

        $bill = $this->ingestBill($workspace, $connection, ['created_on' => '2025-04-04 11:45:35']);

        $this->assertSame('2025-04-04 11:45:35', $bill->source_created_on_raw);
        $this->assertNull($bill->placed_at, 'Without a valid outlet timezone the parsed timestamp must stay null — not UTC, not now().');
        $this->assertNotNull($bill->received_at, 'The webhook receive time is the ordering key when placed_at is unknown.');
    }

    #[Test]
    public function a_valid_outlet_timezone_converts_created_on_to_utc(): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone('Asia/Kolkata');

        $bill = $this->ingestBill($workspace, $connection, ['created_on' => '2025-04-04 11:45:35']);

        // 11:45:35 IST (UTC+5:30) is 06:15:35 UTC.
        $this->assertSame('2025-04-04 06:15:35', $bill->placed_at);
        $this->assertSame('2025-04-04 11:45:35', $bill->source_created_on_raw, 'The raw value survives even when it IS parsed.');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function timezoneConversions(): array
    {
        return [
            'UTC is itself' => ['UTC', '2025-04-04 11:45:35', '2025-04-04 11:45:35'],
            'no DST in India' => ['Asia/Kolkata', '2025-12-31 23:30:00', '2025-12-31 18:00:00'],
            'New York summer (EDT, -4)' => ['America/New_York', '2025-07-04 12:00:00', '2025-07-04 16:00:00'],
            'New York winter (EST, -5)' => ['America/New_York', '2025-01-04 12:00:00', '2025-01-04 17:00:00'],
            'crosses midnight into the previous UTC day' => ['Asia/Kolkata', '2025-04-05 01:00:00', '2025-04-04 19:30:00'],
        ];
    }

    #[Test]
    #[DataProvider('timezoneConversions')]
    public function the_conversion_honours_the_outlets_own_offset_and_dst(string $timezone, string $createdOn, string $expectedUtc): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone($timezone);

        $this->assertSame($expectedUtc, $this->ingestBill($workspace, $connection, ['created_on' => $createdOn])->placed_at);
    }

    /**
     * `restaurant_outlets.timezone` is only validated as a free string, so it
     * can hold abbreviations, offsets, typos or wrong case. PHP would accept
     * some of these; only a real IANA identifier counts as "valid".
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidOutletTimezones(): array
    {
        return [
            'abbreviation' => ['IST'],
            'fixed offset' => ['+05:30'],
            'typo' => ['Asia/Kolkatta'],
            'not a place' => ['Mars/Phobos'],
            'wrong case' => ['asia/kolkata'],
            'whitespace' => [' '],
        ];
    }

    #[Test]
    #[DataProvider('invalidOutletTimezones')]
    public function an_outlet_timezone_that_is_not_a_real_iana_identifier_is_treated_as_no_timezone(string $timezone): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone($timezone);

        $bill = $this->ingestBill($workspace, $connection, ['created_on' => '2025-04-04 11:45:35']);

        $this->assertNull($bill->placed_at, "Timezone [{$timezone}] is not a valid IANA identifier.");
        $this->assertSame('2025-04-04 11:45:35', $bill->source_created_on_raw);
    }

    /**
     * With a VALID timezone, a value that is not exactly the documented
     * `Y-m-d H:i:s` still must not be guessed at. The raw string is kept
     * byte-for-byte, whitespace included.
     *
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function unparseableCreatedOnValues(): array
    {
        return [
            'free text' => ['yesterday', 'yesterday'],
            'date only' => ['2025-04-04', '2025-04-04'],
            'ISO with its own offset (undocumented shape)' => ['2025-04-04T11:45:35+05:30', '2025-04-04T11:45:35+05:30'],
            'impossible month/day (would overflow)' => ['2025-13-45 11:45:35', '2025-13-45 11:45:35'],
            'leading space' => [' 2025-04-04 11:45:35', ' 2025-04-04 11:45:35'],
            'trailing space' => ['2025-04-04 11:45:35 ', '2025-04-04 11:45:35 '],
            'a bare number' => [1743767135, '1743767135'],
            'null' => [null, null],
            'an array' => [['2025-04-04'], null],
        ];
    }

    #[Test]
    #[DataProvider('unparseableCreatedOnValues')]
    public function an_unparseable_created_on_leaves_placed_at_null_and_preserves_the_raw_value_exactly(mixed $createdOn, ?string $expectedRaw): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone('Asia/Kolkata');

        $bill = $this->ingestBill($workspace, $connection, ['created_on' => $createdOn]);

        $this->assertNull($bill->placed_at);
        $this->assertSame($expectedRaw, $bill->source_created_on_raw);
    }

    #[Test]
    public function received_at_is_the_first_events_receive_time_and_a_correction_never_moves_it(): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone('Asia/Kolkata');

        $first = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref, [
            'properties' => ['Order' => ['status' => 'Success', 'total' => 1158]],
        ]));
        $first->update(['received_at' => now()->subHours(2)]);
        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($first->fresh()));
        $firstReceivedAt = DB::table('restaurant_bills')->where('id', $billId)->value('received_at');

        // The same order is cancelled later: same orderID, new event, new status.
        $second = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref, [
            'properties' => ['Order' => ['status' => 'Cancelled', 'total' => 0]],
        ]));
        WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($second));

        $bill = DB::table('restaurant_bills')->where('id', $billId)->first();
        $this->assertSame(1, DB::table('restaurant_bills')->count());
        $this->assertSame('Cancelled', $bill->source_order_status, 'The correction must update the source status.');
        $this->assertSame($firstReceivedAt, $bill->received_at, 'A correction must not move the bill in operational ordering.');
        $this->assertSame($first->fresh()->received_at->format('Y-m-d H:i:s'), $bill->received_at);
    }

    // ══ orderID normalization ═══════════════════════════════════════════════

    #[Test]
    public function an_integer_order_id_and_a_padded_string_order_id_normalise_to_the_same_key(): void
    {
        [$workspace, $connection] = $this->connectionWithOutletTimezone('Asia/Kolkata');

        $asInt = $this->ingestBill($workspace, $connection, ['orderID' => 114]);
        $asPaddedString = $this->ingestBill($workspace, $connection, ['orderID' => '  114 ', 'total' => 999]);

        $this->assertSame('114', $asInt->external_order_id);
        $this->assertSame($asInt->id, $asPaddedString->id, 'The same order must not become two bills because of type or padding.');
        $this->assertSame(1, DB::table('restaurant_bills')->count());
    }

    #[Test]
    public function workspace_id_and_outlet_id_come_from_the_connection_never_the_payload(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'outlet_id' => $outlet->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref));

        $billId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));

        $bill = DB::table('restaurant_bills')->where('id', $billId)->first();
        $this->assertSame($workspace->id, $bill->workspace_id);
        $this->assertSame($outlet->id, $bill->outlet_id);
    }

    #[Test]
    public function re_ingesting_the_same_order_id_updates_the_row_and_preserves_created_at(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);

        $first = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref, [
            'properties' => ['Order' => ['total' => 1158]],
        ]));
        $firstBillId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($first));
        $originalCreatedAt = DB::table('restaurant_bills')->where('id', $firstBillId)->value('created_at');

        // A genuine correction from Petpooja: same orderID, different total —
        // this is exactly the "bill and its correction" case the
        // pos_webhook_events migration deferred to this table's UNIQUE
        // constraint.
        $second = $this->pendingEvent($connection, $this->orderdetailsPayload($connection->external_ref, [
            'properties' => ['Order' => ['total' => 1200]],
        ]));
        $secondBillId = WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($second));

        $this->assertSame($firstBillId, $secondBillId, 'Same (connection_id, external_order_id) must update in place, not duplicate.');
        $this->assertSame(1, DB::table('restaurant_bills')->where('connection_id', $connection->id)->where('external_order_id', '114')->count());

        $updated = DB::table('restaurant_bills')->where('id', $secondBillId)->first();
        $this->assertEquals(1200, $updated->total);
        $this->assertSame($originalCreatedAt, $updated->created_at, 'A correction must never rewrite the bill\'s original created_at.');
    }

    #[Test]
    public function it_refuses_to_ingest_an_event_type_other_than_orderdetails(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $event = PosWebhookEvent::factory()->create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'event_type' => 'unsupported_event',
            'processing_status' => PosWebhookEvent::STATUS_QUARANTINED,
            'raw_payload' => ['event' => 'something_else'],
        ]);

        $this->expectException(UnprocessablePosWebhookEventException::class);

        WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));
    }

    #[Test]
    public function it_refuses_to_ingest_a_payload_missing_order_id(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $payload = $this->orderdetailsPayload($connection->external_ref);
        unset($payload['properties']['Order']['orderID']);
        $event = $this->pendingEvent($connection, $payload);

        $this->expectException(UnprocessablePosWebhookEventException::class);

        WorkspaceContext::for($workspace->id, fn () => $this->service()->ingest($event));
    }
}
