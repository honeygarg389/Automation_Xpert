<?php

namespace Tests\Feature\Restaurant;

use App\Events\MessageSent;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Modules\Restaurant\Jobs\SendRestaurantDigitalBillJob;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantDigitalBillDelivery;
use App\Modules\Restaurant\Models\RestaurantDigitalBillDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantDigitalBillDeliveryService;
use App\Modules\Restaurant\Services\RestaurantOutboundPolicy;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestaurantDigitalBillDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_eligible_bill_creates_one_pending_delivery_and_one_job_despite_duplicate_scheduling(): void
    {
        $records = $this->records();
        Queue::fake();

        WorkspaceContext::for($records['workspace']->id, function () use ($records): void {
            app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id);
            app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id);
        });

        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => RestaurantDigitalBillDelivery::query()->firstOrFail());
        $this->assertSame(RestaurantDigitalBillDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame(1, WorkspaceContext::for($records['workspace']->id, fn () => RestaurantDigitalBillDelivery::query()->count()));
        // RefreshDatabase holds the outer transaction open until teardown, so
        // deliberately execute the callback Laravel has deferred on that
        // transaction rather than weakening production's after-commit rule.
        $this->executeDeferredAfterCommitCallbacks();
        Queue::assertPushed(SendRestaurantDigitalBillJob::class, 1);
        Queue::assertPushedOn('restaurant', SendRestaurantDigitalBillJob::class);
    }

    #[Test]
    public function it_sends_only_the_opaque_token_as_the_configured_url_button_parameter(): void
    {
        $records = $this->records();
        Queue::fake();
        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id));
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.safe']]], 200)]);

        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->send($delivery->id));

        $fresh = WorkspaceContext::for($records['workspace']->id, fn () => $delivery->fresh());
        $this->assertSame(RestaurantDigitalBillDelivery::STATUS_SENT, $fresh->status);
        $this->assertSame('wamid.safe', $fresh->provider_message_id);
        $sentAudit = AuditLog::query()->where('action', 'restaurant.digital_bill.sent')->sole();
        $this->assertSame($fresh->id, $sentAudit->meta['delivery_id']);
        $this->assertArrayNotHasKey('public_token', $sentAudit->meta);
        $this->assertArrayNotHasKey('phone', $sentAudit->meta);
        $this->assertArrayNotHasKey('components', $sentAudit->meta);
        Http::assertSent(function (Request $request) use ($records): bool {
            $component = data_get($request->data(), 'template.components.0');

            return data_get($request->data(), 'template.name') === $records['template']->name
                && data_get($request->data(), 'template.language.code') === $records['template']->language
                && data_get($component, 'type') === 'button'
                && data_get($component, 'sub_type') === 'url'
                && data_get($component, 'index') === '0'
                && data_get($component, 'parameters.0.text') === $records['bill']->public_token
                && ! str_contains(json_encode($component) ?: '', $records['contact']->phone_e164);
        });
    }

    #[Test]
    public function body_variables_or_an_invalid_url_button_are_suppressed_without_a_job_or_provider_call(): void
    {
        $records = $this->records(components: [
            ['type' => 'BODY', 'text' => 'Hello {{1}}'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'View', 'url' => 'https://automationxpert.in/b/{{1}}']]],
        ]);
        Queue::fake();
        Http::fake();

        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id));

        $this->assertSame(RestaurantDigitalBillDelivery::STATUS_SUPPRESSED, $delivery->status);
        $this->assertSame('template_body_variables', $delivery->reason_code);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function static_multiple_or_malformed_url_button_definitions_fail_closed_before_dispatch(): void
    {
        $invalidTemplates = [
            [
                'reason' => 'template_url_button_static',
                'components' => [['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://automationxpert.in/b/static']]]],
            ],
            [
                'reason' => 'template_url_button_multiple',
                'components' => [['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'url' => 'https://automationxpert.in/b/{{1}}'],
                    ['type' => 'URL', 'url' => 'https://automationxpert.in/b/{{1}}'],
                ]]],
            ],
            [
                'reason' => 'template_url_button_missing',
                'components' => ['not-a-meta-component'],
            ],
        ];

        foreach ($invalidTemplates as $invalid) {
            $records = $this->records($invalid['components']);
            Queue::fake();
            Http::fake();

            $delivery = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id));

            $this->assertSame(RestaurantDigitalBillDelivery::STATUS_SUPPRESSED, $delivery->status);
            $this->assertSame($invalid['reason'], $delivery->reason_code);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function disabling_digital_bills_after_enqueue_suppresses_before_any_provider_call(): void
    {
        $records = $this->records();
        Queue::fake();
        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id));
        $records['outlet']->update(['digital_bill_enabled' => false]);
        Http::fake();

        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->send($delivery->id));

        $fresh = WorkspaceContext::for($records['workspace']->id, fn () => $delivery->fresh());
        $this->assertSame(RestaurantDigitalBillDelivery::STATUS_SUPPRESSED, $fresh->status);
        $this->assertSame('digital_bill_disabled', $fresh->reason_code);
        Http::assertNothingSent();
    }

    #[Test]
    public function any_attempt_without_a_provider_message_id_becomes_outcome_unknown_and_is_never_resent(): void
    {
        $records = $this->records();
        Queue::fake();
        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id));
        Http::fake(['*' => Http::response([], 200)]);

        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->send($delivery->id));
        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->send($delivery->id));

        $fresh = WorkspaceContext::for($records['workspace']->id, fn () => $delivery->fresh());
        $this->assertSame(RestaurantDigitalBillDelivery::STATUS_OUTCOME_UNKNOWN, $fresh->status);
        $this->assertSame(1, $fresh->attempt_count);
        $audit = AuditLog::query()->where('action', 'restaurant.digital_bill.outcome_unknown')->sole();
        $this->assertSame($delivery->id, $audit->meta['delivery_id']);
        $this->assertArrayNotHasKey('public_token', $audit->meta);
        $this->assertArrayNotHasKey('phone', $audit->meta);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_transport_exception_after_the_provider_boundary_is_outcome_unknown_and_is_never_retried(): void
    {
        $records = $this->records();
        Queue::fake();
        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id));
        // ⚠️ Http::failedConnection(), NOT `fn () => throw new ConnectionException(...)`.
        // A real transport failure reaches PendingRequest::send() as a GUZZLE
        // ConnectException, which it records as a [request, null] pair and then
        // converts into Illuminate's ConnectionException. Throwing the already-
        // converted Illuminate exception from the fake callback skips that
        // catch (it is not a Guzzle TransferException), so nothing was ever
        // recorded and `assertSentCount(1)` could never pass — a test bug, not
        // a service bug. failedConnection() is Laravel's own faithful stand-in.
        Http::fake(['*' => Http::failedConnection('simulated timeout')]);

        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->send($delivery->id));
        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->send($delivery->id));

        $fresh = WorkspaceContext::for($records['workspace']->id, fn () => $delivery->fresh());
        $this->assertSame(RestaurantDigitalBillDelivery::STATUS_OUTCOME_UNKNOWN, $fresh->status);
        $this->assertSame(RestaurantDigitalBillDeliveryService::REASON_PROVIDER_OUTCOME_UNKNOWN, $fresh->reason_code);
        $this->assertSame(1, $fresh->attempt_count);
        // Exactly one provider attempt reached the transport across BOTH send()
        // calls: the second must not have re-sent the ambiguous attempt.
        Http::assertSentCount(1);
    }

    #[Test]
    public function deleted_or_incomplete_configuration_after_enqueue_fails_closed_without_a_provider_call(): void
    {
        $records = $this->records();
        Queue::fake();
        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id));
        WorkspaceContext::for($records['workspace']->id, fn () => RestaurantDigitalBillDeliveryConfig::query()->where('outlet_id', $records['outlet']->id)->update(['whatsapp_template_id' => null]));
        Http::fake();

        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->send($delivery->id));

        $fresh = WorkspaceContext::for($records['workspace']->id, fn () => $delivery->fresh());
        $this->assertSame(RestaurantDigitalBillDelivery::STATUS_SUPPRESSED, $fresh->status);
        $this->assertSame(RestaurantDigitalBillDeliveryService::REASON_CONFIG_INCOMPLETE, $fresh->reason_code);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_sender_that_becomes_inactive_after_enqueue_fails_closed_without_a_provider_call(): void
    {
        $records = $this->records();
        Queue::fake();
        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id));
        $records['sender']->businessAccount()->update(['status' => 'inactive']);
        Http::fake();

        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->send($delivery->id));

        $fresh = WorkspaceContext::for($records['workspace']->id, fn () => $delivery->fresh());
        $this->assertSame(RestaurantDigitalBillDelivery::STATUS_SUPPRESSED, $fresh->status);
        $this->assertSame(RestaurantOutboundPolicy::REASON_WHATSAPP_SENDER_NOT_READY, $fresh->reason_code);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_workspace_cannot_schedule_another_workspaces_bill(): void
    {
        $records = $this->records();
        $foreign = $this->records();
        Queue::fake();

        $result = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($foreign['bill']->id));

        $this->assertNull($result);
        $this->assertSame(0, WorkspaceContext::for($records['workspace']->id, fn () => RestaurantDigitalBillDelivery::query()->count()));
        $this->assertSame(0, WorkspaceContext::for($foreign['workspace']->id, fn () => RestaurantDigitalBillDelivery::query()->count()));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_stalled_attempt_is_marked_outcome_unknown_without_any_resend(): void
    {
        $records = $this->records();
        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => RestaurantDigitalBillDelivery::query()->create([
            'workspace_id' => $records['workspace']->id,
            'restaurant_bill_id' => $records['bill']->id,
            'outlet_id' => $records['outlet']->id,
            'purpose' => RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL,
            'status' => RestaurantDigitalBillDelivery::STATUS_SENDING,
            'provider_attempt_started_at' => now()->subMinutes(11),
        ]));
        Queue::fake();
        Http::fake();

        $this->assertSame(1, app(RestaurantDigitalBillDeliveryService::class)->markStalledAttemptsOutcomeUnknown(10));

        $fresh = WorkspaceContext::for($records['workspace']->id, fn () => $delivery->fresh());
        $this->assertSame(RestaurantDigitalBillDelivery::STATUS_OUTCOME_UNKNOWN, $fresh->status);
        $this->assertSame(RestaurantDigitalBillDeliveryService::REASON_PROVIDER_OUTCOME_UNKNOWN, $fresh->reason_code);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function suppressed_delivery_audit_is_safe_and_does_not_dispatch_any_customer_work(): void
    {
        $records = $this->records(components: [['type' => 'BODY', 'text' => 'Hello {{1}}']]);
        $customerPersistenceBefore = collect(['messages', 'conversations', 'automation_runs', 'campaign_recipients', 'webhook_deliveries'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        Queue::fake();
        Bus::fake();
        // Scoped to the ONE business event that must not exist here. A blanket
        // Event::fake()/assertNothingDispatched() also intercepts Eloquent's own
        // lifecycle events (eloquent.creating/saved/… for the ledger row and the
        // AuditLog row), which a suppressed delivery legitimately produces — and
        // faking them would silently disable every model hook during the test.
        Event::fake([MessageSent::class]);
        Http::fake();
        Mail::fake();
        Notification::fake();

        $delivery = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantDigitalBillDeliveryService::class)->schedule($records['bill']->id));

        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        // MessageSent is the platform's outbound-customer-message event (a
        // ShouldBroadcast pushed to the workspace inbox; raised by the inbox,
        // campaign, automation and auto-reply send paths). A suppressed delivery
        // sent nothing, so announcing a sent message would be a false claim. The
        // service dispatches no events of its own — its only outputs are the
        // ledger row, the AuditLog row (asserted below) and, ONLY when not
        // suppressed, the send job (asserted above via Queue/Bus).
        Event::assertNotDispatched(MessageSent::class);
        Http::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        $audit = AuditLog::query()->where('action', 'restaurant.digital_bill.suppressed')->sole();
        $meta = $audit->meta;
        $this->assertSame($delivery->id, $meta['delivery_id']);
        $this->assertSame($records['bill']->id, $meta['restaurant_bill_id']);
        $this->assertSame('template_body_variables', $meta['reason_code']);
        $this->assertArrayNotHasKey('public_token', $meta);
        $this->assertArrayNotHasKey('phone', $meta);
        $this->assertArrayNotHasKey('template_components', $meta);
        foreach ($customerPersistenceBefore as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} must not change while a delivery is suppressed.");
        }
    }

    /**
     * @param  array<int, mixed>|null  $components
     * @return array{workspace:Workspace,outlet:RestaurantOutlet,contact:Contact,bill:RestaurantBill,sender:WhatsappPhoneNumber,template:WhatsappTemplate}
     */
    private function records(?array $components = null): array
    {
        $workspace = Workspace::factory()->create();

        return WorkspaceContext::for($workspace->id, function () use ($workspace, $components): array {
            $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id, 'digital_bill_enabled' => true]);
            $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone_e164' => '+919876543210']);
            $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id, 'outlet_id' => $outlet->id]);
            $bill = RestaurantBill::factory()->create(['workspace_id' => $workspace->id, 'outlet_id' => $outlet->id, 'contact_id' => $contact->id, 'connection_id' => $connection->id, 'source_order_status' => 'Success']);
            $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $workspace->id, 'credentials' => ['system_user_token' => 'token'], 'status' => 'active']);
            $sender = WhatsappPhoneNumber::query()->create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'sender-'.uniqid()]);
            $template = WhatsappTemplate::query()->create(['workspace_id' => $workspace->id, 'waba_id' => $waba->waba_id, 'name' => 'any-approved-utility-name', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => $components ?? [
                ['type' => 'BODY', 'text' => 'Your bill is ready.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'View bill', 'url' => 'https://automationxpert.in/b/{{1}}']]],
            ]]);
            RestaurantDigitalBillDeliveryConfig::query()->create(['workspace_id' => $workspace->id, 'outlet_id' => $outlet->id, 'whatsapp_phone_number_id' => $sender->id, 'whatsapp_template_id' => $template->id]);

            return compact('workspace', 'outlet', 'contact', 'bill', 'sender', 'template');
        });
    }

    private function executeDeferredAfterCommitCallbacks(): void
    {
        foreach ($this->app->make('db.transactions')->getCommittedTransactions() as $transaction) {
            foreach ($transaction->getCallbacks() as $callback) {
                $callback();
            }
        }
    }
}
