<?php

namespace Tests\Feature\Whatsapp;

use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantFeedbackDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantFeedbackRequest;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantFeedbackDeliveryService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappInboundStopOptOutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function trusted_stop_is_idempotent_preserves_unrelated_contact_data_and_blocks_feedback_without_provider_calls(): void
    {
        Event::fake([ContactCreated::class, MessageReceived::class]);
        Http::fake();

        $primary = $this->workspaceWithInboundChannel('PRIMARY_PHONE_ID');
        $other = $this->workspaceWithInboundChannel('OTHER_PHONE_ID');
        $consentAt = now()->subDay()->startOfSecond();

        $primaryContact = Contact::factory()->create([
            'workspace_id' => $primary['workspace']->id,
            'phone_e164' => '+919876543210',
            'source' => 'import',
            'opt_in_whatsapp' => false,
            'whatsapp_consent_at' => $consentAt,
            'whatsapp_consent_source' => 'import',
            'whatsapp_consent_purpose' => Contact::CONSENT_PURPOSE_MARKETING,
            'whatsapp_consent_text_version' => 'v1',
            'whatsapp_consent_evidence' => ['source' => 'import'],
            'digital_bill_opted_out_at' => now()->subHour()->startOfSecond(),
            'digital_bill_opt_out_source' => 'digital_bill_customer_request',
        ]);
        $otherContact = Contact::factory()->create([
            'workspace_id' => $other['workspace']->id,
            'phone_e164' => '+919876543210',
            'source' => 'manual',
        ]);

        $driver = app(WhatsappDriver::class);
        $driver->processWebhookPayload($this->textPayload($primary['waba'], 'PRIMARY_PHONE_ID', 'wamid.stop.first', '  sToP  '));

        Event::assertNotDispatched(MessageReceived::class);
        $primaryContact->refresh();
        $otherContact->refresh();
        $this->assertNotNull($primaryContact->whatsapp_opted_out_at);
        $firstOptOutAt = $primaryContact->whatsapp_opted_out_at->toDateTimeString();
        $this->assertSame('whatsapp_inbound_stop', $primaryContact->whatsapp_opt_out_source);
        $this->assertSame('import', $primaryContact->source);
        $this->assertFalse((bool) $primaryContact->opt_in_whatsapp);
        $this->assertSame($consentAt->toDateTimeString(), $primaryContact->whatsapp_consent_at->toDateTimeString());
        $this->assertSame('import', $primaryContact->whatsapp_consent_source);
        $this->assertSame(Contact::CONSENT_PURPOSE_MARKETING, $primaryContact->whatsapp_consent_purpose);
        $this->assertSame('v1', $primaryContact->whatsapp_consent_text_version);
        $this->assertSame(['source' => 'import'], $primaryContact->whatsapp_consent_evidence);
        $this->assertNotNull($primaryContact->digital_bill_opted_out_at);
        $this->assertSame('digital_bill_customer_request', $primaryContact->digital_bill_opt_out_source);
        $this->assertNull($otherContact->whatsapp_opted_out_at);

        // A first-ever STOP must not leak into ContactCreated automation either.
        $driver->processWebhookPayload($this->textPayload($primary['waba'], 'PRIMARY_PHONE_ID', 'wamid.stop.new-contact', 'STOP', '919876543211'));
        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $primary['workspace']->id,
            'phone_e164' => '+919876543211',
            'whatsapp_opt_out_source' => 'whatsapp_inbound_stop',
        ]);
        Event::assertNotDispatched(ContactCreated::class);

        $audit = AuditLog::query()
            ->where('action', 'contact.whatsapp_opted_out')
            ->where('auditable_id', $primaryContact->id)
            ->sole();
        $this->assertSame($primary['workspace']->id, $audit->workspace_id);
        $this->assertSame($primaryContact->id, $audit->auditable_id);
        $this->assertSame(['source' => 'whatsapp_inbound_stop'], $audit->meta);

        $this->travel(1)->seconds();
        $driver->processWebhookPayload($this->textPayload($primary['waba'], 'PRIMARY_PHONE_ID', 'wamid.stop.second', 'STOP'));
        $primaryContact->refresh();
        $this->assertSame($firstOptOutAt, $primaryContact->whatsapp_opted_out_at->toDateTimeString());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'contact.whatsapp_opted_out')
            ->where('auditable_id', $primaryContact->id)
            ->count());

        $bill = $this->feedbackBill($primary['workspace'], $primaryContact);
        $feedback = app(RestaurantFeedbackDeliveryService::class);
        WorkspaceContext::for($primary['workspace']->id, fn () => $feedback->schedule($bill->id));
        $request = WorkspaceContext::for($primary['workspace']->id, fn () => RestaurantFeedbackRequest::query()->sole());
        WorkspaceContext::for($primary['workspace']->id, fn () => $request->update(['status' => RestaurantFeedbackRequest::STATUS_PENDING]));
        WorkspaceContext::for($primary['workspace']->id, fn () => $feedback->send($request->id));

        $this->assertSame(RestaurantFeedbackRequest::STATUS_SUPPRESSED, WorkspaceContext::for($primary['workspace']->id, fn () => $request->fresh()->status));
        $this->assertSame('whatsapp_opted_out', WorkspaceContext::for($primary['workspace']->id, fn () => $request->fresh()->reason_code));
        Http::assertNothingSent();
    }

    /** @return array{workspace: Workspace, waba: WhatsappBusinessAccount} */
    private function workspaceWithInboundChannel(string $phoneNumberId): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'active',
        ]);
        ChannelAccount::query()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Inbound test',
            'phone_number_id' => $phoneNumberId,
            'business_account_id' => $waba->waba_id,
            'status' => 'active',
        ]);

        return compact('workspace', 'waba');
    }

    /** @return array<string, mixed> */
    private function textPayload(WhatsappBusinessAccount $waba, string $phoneNumberId, string $messageId, string $body, string $from = '919876543210'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $waba->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'from' => $from,
                            'id' => $messageId,
                            'timestamp' => now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function feedbackBill(Workspace $workspace, Contact $contact): RestaurantBill
    {
        $outlet = RestaurantOutlet::factory()->create([
            'workspace_id' => $workspace->id,
            'feedback_request_enabled' => true,
            'timezone' => 'Asia/Kolkata',
        ]);
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id, 'outlet_id' => $outlet->id]);
        $bill = RestaurantBill::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'outlet_id' => $outlet->id,
            'contact_id' => $contact->id,
            'source_order_status' => 'Success',
            'placed_at' => now(),
        ]);
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'credentials' => ['system_user_token' => 'test-token'],
            'status' => 'active',
        ]);
        $sender = WhatsappPhoneNumber::query()->create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => 'feedback-'.uniqid(),
        ]);
        $template = WhatsappTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => $waba->waba_id,
            'name' => 'feedback-'.uniqid(),
            'language' => 'en',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [
                ['type' => 'BODY', 'text' => 'Please share feedback'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://automationxpert.in/f/{{1}}']]],
            ],
        ]);
        RestaurantFeedbackDeliveryConfig::query()->create([
            'workspace_id' => $workspace->id,
            'outlet_id' => $outlet->id,
            'whatsapp_phone_number_id' => $sender->id,
            'whatsapp_template_id' => $template->id,
            'timing_preference' => RestaurantFeedbackDeliveryConfig::TIMING_IMMEDIATELY,
        ]);

        return $bill;
    }
}
