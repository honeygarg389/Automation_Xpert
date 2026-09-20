<?php

namespace Tests\Feature\Restaurant;

use App\Events\ContactCreated;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantOutboundPolicy;
use App\Modules\Restaurant\Support\RestaurantOutboundDecision;
use App\Modules\Restaurant\Support\RestaurantOutboundPurpose;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestaurantOutboundPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_valid_same_workspace_digital_bill_decision_allows(): void
    {
        $records = $this->eligibleRecords();

        $decision = $this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reasonCode);
        $this->assertSame($records['workspace']->id, $decision->workspaceId);
        $this->assertSame($records['bill']->id, $decision->billId);
        $this->assertSame($records['outlet']->id, $decision->outletId);
        $this->assertSame($records['contact']->id, $decision->contactId);
    }

    #[Test]
    public function a_valid_same_workspace_feedback_decision_allows(): void
    {
        $records = $this->eligibleRecords();

        $decision = $this->evaluate($records, RestaurantOutboundPurpose::FEEDBACK_REQUEST);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reasonCode);
    }

    #[Test]
    public function outlet_toggles_are_independent(): void
    {
        $records = $this->eligibleRecords(['digital_bill_enabled' => false, 'feedback_request_enabled' => true]);

        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_DIGITAL_BILL_DISABLED);
        $this->assertTrue($this->evaluate($records, RestaurantOutboundPurpose::FEEDBACK_REQUEST)->allowed);

        $records['outlet']->update(['digital_bill_enabled' => true, 'feedback_request_enabled' => false]);

        $this->assertTrue($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL)->allowed);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::FEEDBACK_REQUEST), RestaurantOutboundPolicy::REASON_FEEDBACK_REQUEST_DISABLED);
    }

    #[Test]
    public function a_broad_whatsapp_stop_blocks_both_purposes(): void
    {
        $records = $this->eligibleRecords(contactOverrides: ['whatsapp_opted_out_at' => now()]);

        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_WHATSAPP_OPTED_OUT);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::FEEDBACK_REQUEST), RestaurantOutboundPolicy::REASON_WHATSAPP_OPTED_OUT);
    }

    #[Test]
    public function digital_bill_opt_out_blocks_only_digital_bill(): void
    {
        $records = $this->eligibleRecords(contactOverrides: ['digital_bill_opted_out_at' => now()]);

        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_DIGITAL_BILL_OPTED_OUT);
        $this->assertTrue($this->evaluate($records, RestaurantOutboundPurpose::FEEDBACK_REQUEST)->allowed);
    }

    #[Test]
    public function marketing_opt_in_and_consent_fields_do_not_change_transactional_or_feedback_eligibility(): void
    {
        $records = $this->eligibleRecords(contactOverrides: [
            'opt_in_whatsapp' => false,
            'whatsapp_consent_at' => null,
            'whatsapp_consent_source' => null,
            'whatsapp_consent_purpose' => null,
        ]);

        $this->assertTrue($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL)->allowed);
        $this->assertTrue($this->evaluate($records, RestaurantOutboundPurpose::FEEDBACK_REQUEST)->allowed);
    }

    #[Test]
    public function unknown_purpose_and_missing_workspace_context_fail_closed(): void
    {
        $records = $this->eligibleRecords();

        $this->assertBlocked(
            app(RestaurantOutboundPolicy::class)->evaluate('marketing', $records['bill']->id, $records['sender']->phone_number_id, $records['template']->id),
            RestaurantOutboundPolicy::REASON_UNKNOWN_PURPOSE,
        );
        $this->assertBlocked(
            app(RestaurantOutboundPolicy::class)->evaluate(RestaurantOutboundPurpose::DIGITAL_BILL, $records['bill']->id, $records['sender']->phone_number_id, $records['template']->id),
            RestaurantOutboundPolicy::REASON_WORKSPACE_CONTEXT_MISSING,
        );
    }

    #[Test]
    public function missing_resources_and_every_required_gate_fail_closed_with_explicit_codes(): void
    {
        $records = $this->eligibleRecords();

        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL, billId: $records['bill']->id + 999), RestaurantOutboundPolicy::REASON_BILL_MISSING);

        $records['bill']->update(['outlet_id' => null]);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_BILL_OUTLET_MISSING);
        $records['bill']->update(['outlet_id' => $records['outlet']->id]);

        $records['bill']->update(['contact_id' => null]);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_BILL_CONTACT_MISSING);
        $records['bill']->update(['contact_id' => $records['contact']->id]);

        $records['contact']->update(['phone_e164' => null]);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_CONTACT_PHONE_MISSING);
        $records['contact']->update(['phone_e164' => '+919876543210']);

        $records['outlet']->update(['status' => RestaurantOutlet::STATUS_ARCHIVED]);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_OUTLET_INACTIVE);
        $records['outlet']->update(['status' => RestaurantOutlet::STATUS_ACTIVE]);

        $records['bill']->update(['source_order_status' => 'Cancelled']);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_BILL_NOT_SUCCESSFUL);
        $records['bill']->update(['source_order_status' => 'Unknown']);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_BILL_NOT_SUCCESSFUL);
        $records['bill']->update(['source_order_status' => 'Success']);

        $records['outlet']->update(['digital_bill_enabled' => false]);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_DIGITAL_BILL_DISABLED);
        $records['outlet']->update(['digital_bill_enabled' => true, 'feedback_request_enabled' => false]);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::FEEDBACK_REQUEST), RestaurantOutboundPolicy::REASON_FEEDBACK_REQUEST_DISABLED);
        $records['outlet']->update(['feedback_request_enabled' => true]);

        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL, senderId: 'unready'), RestaurantOutboundPolicy::REASON_WHATSAPP_SENDER_NOT_READY);
        $senderAccount = $records['sender']->businessAccount()->firstOrFail();
        $senderAccount->update(['credentials' => []]);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_WHATSAPP_SENDER_NOT_READY);
        $senderAccount->update(['credentials' => ['system_user_token' => 'restored-policy-test-token']]);
        $records['template']->update(['status' => 'PENDING']);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL), RestaurantOutboundPolicy::REASON_TEMPLATE_NOT_APPROVED);
    }

    #[Test]
    public function foreign_and_deliberately_mismatched_record_identities_never_allow(): void
    {
        $records = $this->eligibleRecords();
        $foreign = $this->eligibleRecords();

        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL, billId: $foreign['bill']->id), RestaurantOutboundPolicy::REASON_BILL_MISSING);

        $records['bill']->update(['outlet_id' => $foreign['outlet']->id]);
        $this->assertFalse($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL)->allowed);
        $records['bill']->update(['outlet_id' => $records['outlet']->id, 'contact_id' => $foreign['contact']->id]);
        $this->assertFalse($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL)->allowed);
        $records['bill']->update(['contact_id' => $records['contact']->id]);

        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL, senderId: $foreign['sender']->phone_number_id), RestaurantOutboundPolicy::REASON_WHATSAPP_SENDER_NOT_READY);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL, templateId: $foreign['template']->id), RestaurantOutboundPolicy::REASON_TEMPLATE_NOT_APPROVED);

        $otherWaba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $records['workspace']->id,
            'credentials' => ['system_user_token' => 'other-token'],
        ]);
        $mismatchedTemplate = WhatsappTemplate::query()->create([
            'workspace_id' => $records['workspace']->id,
            'waba_id' => $otherWaba->waba_id,
            'name' => 'wrong-sender-template',
            'language' => 'en',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
        ]);
        $this->assertBlocked($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL, templateId: $mismatchedTemplate->id), RestaurantOutboundPolicy::REASON_TEMPLATE_NOT_APPROVED);
    }

    #[Test]
    public function evaluation_is_strictly_read_only_and_emits_no_outbound_or_audit_side_effects(): void
    {
        $records = $this->eligibleRecords();
        $tables = ['audit_logs', 'messages', 'conversations', 'automation_runs', 'campaign_recipients', 'webhook_deliveries', 'restaurant_bills', 'contacts'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);

        Queue::fake();
        Bus::fake();
        Event::fake([ContactCreated::class]);
        Http::fake();
        Mail::fake();
        Notification::fake();

        $this->assertTrue($this->evaluate($records, RestaurantOutboundPurpose::DIGITAL_BILL)->allowed);

        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Event::assertNotDispatched(ContactCreated::class);
        Http::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::count());
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} must not change during policy evaluation.");
        }
    }

    /**
     * @param  array<string, mixed>  $outletOverrides
     * @param  array<string, mixed>  $contactOverrides
     * @return array{workspace:Workspace,outlet:RestaurantOutlet,contact:Contact,bill:RestaurantBill,sender:WhatsappPhoneNumber,template:WhatsappTemplate}
     */
    private function eligibleRecords(array $outletOverrides = [], array $contactOverrides = []): array
    {
        $workspace = Workspace::factory()->create();

        return WorkspaceContext::for((int) $workspace->id, function () use ($workspace, $outletOverrides, $contactOverrides): array {
            $outlet = RestaurantOutlet::factory()->create(array_merge([
                'workspace_id' => $workspace->id,
                'digital_bill_enabled' => true,
                'feedback_request_enabled' => true,
            ], $outletOverrides));
            $connection = PosConnection::factory()->create([
                'workspace_id' => $workspace->id,
                'outlet_id' => $outlet->id,
            ]);
            $contact = Contact::factory()->create(array_merge([
                'workspace_id' => $workspace->id,
                'phone_e164' => '+919876543210',
            ], $contactOverrides));
            $bill = RestaurantBill::factory()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'outlet_id' => $outlet->id,
                'contact_id' => $contact->id,
                'source_order_status' => 'Success',
            ]);
            $waba = WhatsappBusinessAccount::factory()->create([
                'workspace_id' => $workspace->id,
                'credentials' => ['system_user_token' => 'policy-test-token'],
            ]);
            $sender = WhatsappPhoneNumber::query()->create([
                'waba_id_fk' => $waba->id,
                'phone_number_id' => 'phone-'.$workspace->id,
            ]);
            $template = WhatsappTemplate::query()->create([
                'workspace_id' => $workspace->id,
                'waba_id' => $waba->waba_id,
                'name' => 'policy-template-'.$workspace->id,
                'language' => 'en',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
            ]);

            return compact('workspace', 'outlet', 'contact', 'bill', 'sender', 'template');
        });
    }

    /**
     * @param  array{workspace:Workspace,outlet:RestaurantOutlet,contact:Contact,bill:RestaurantBill,sender:WhatsappPhoneNumber,template:WhatsappTemplate}  $records
     */
    private function evaluate(
        array $records,
        string $purpose,
        ?int $billId = null,
        ?string $senderId = null,
        ?int $templateId = null,
    ): RestaurantOutboundDecision {
        return WorkspaceContext::for((int) $records['workspace']->id, fn () => app(RestaurantOutboundPolicy::class)->evaluate(
            $purpose,
            $billId ?? $records['bill']->id,
            $senderId ?? $records['sender']->phone_number_id,
            $templateId ?? $records['template']->id,
        ));
    }

    private function assertBlocked(RestaurantOutboundDecision $decision, string $reason): void
    {
        $this->assertFalse($decision->allowed);
        $this->assertSame($reason, $decision->reasonCode);
    }
}
