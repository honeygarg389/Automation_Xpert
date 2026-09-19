<?php

namespace Tests\Feature\Flows;

use App\Models\Workspace;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationRunLog;
use App\Modules\Flows\Events\WhatsappFlowSubmitted;
use App\Modules\Flows\Models\FormSubmission;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappFlowSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private string $verifyToken = 'flow-submission-verify-token';

    private string $phoneNumberId = 'flow-submission-phone-id';

    /** @return array{workspace: Workspace, waba: WhatsappBusinessAccount, contact: Contact} */
    private function inboundFixture(): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'webhook_verify_token' => $this->verifyToken,
            'status' => 'active',
        ]);
        WorkspaceContext::for($workspace->id, function () use ($workspace, $waba): void {
            ChannelAccount::create([
                'workspace_id' => $workspace->id,
                'channel' => 'whatsapp',
                'display_name' => 'Flow line',
                'phone_number_id' => $this->phoneNumberId,
                'business_account_id' => $waba->waba_id,
                'status' => 'active',
            ]);
        });
        $contact = WorkspaceContext::for($workspace->id, fn (): Contact => Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+15555550101',
            'first_name' => 'Established',
            'email' => null,
            'source' => 'manual',
        ]));

        return compact('workspace', 'waba', 'contact');
    }

    private function postInteractive(WhatsappBusinessAccount $waba, array $nfmReply, string $messageId): void
    {
        $this->postJson("/webhooks/whatsapp/{$this->verifyToken}", [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $waba->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $this->phoneNumberId],
                        'messages' => [[
                            'from' => '15555550101',
                            'id' => $messageId,
                            'timestamp' => now()->timestamp,
                            'type' => 'interactive',
                            'interactive' => ['nfm_reply' => $nfmReply],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    #[Test]
    public function an_inbound_flow_reply_persists_correlates_enriches_and_triggers_automations(): void
    {
        ['workspace' => $workspace, 'waba' => $waba, 'contact' => $contact] = $this->inboundFixture();

        [$flow, $sourceRun] = WorkspaceContext::for($workspace->id, function () use ($workspace, $contact): array {
            $flow = WhatsappFlow::create([
                'workspace_id' => $workspace->id,
                'name' => 'Lead qualification',
                'category' => 'LEAD_GENERATION',
                'status' => WhatsappFlow::STATUS_DRAFT,
                'screens' => [['id' => 'START', 'fields' => []]],
                'meta_flow_id' => 'meta-flow-123',
            ]);
            $sourceAutomation = Automation::create([
                'workspace_id' => $workspace->id,
                'name' => 'Send lead form',
                'status' => 'active',
                'nodes' => [['id' => 'form-node', 'type' => 'whatsapp_form', 'data' => ['flow_id' => 'meta-flow-123']]],
                'edges' => [],
            ]);
            $run = AutomationRun::create([
                'automation_id' => $sourceAutomation->id,
                'contact_id' => $contact->id,
                'status' => 'completed',
                'context' => [],
                'started_at' => now(),
                'completed_at' => now(),
            ]);
            AutomationRunLog::create(['run_id' => $run->id, 'node_id' => 'form-node', 'node_type' => 'whatsapp_form', 'result' => 'ok']);

            Automation::create([
                'workspace_id' => $workspace->id,
                'name' => 'Follow up on form',
                'status' => 'active',
                'trigger_type' => 'form.submitted',
                'nodes' => [],
                'edges' => [],
            ]);

            return [$flow, $run];
        });

        // Meta's real nfm_reply never carries flow_token as a sibling key —
        // it is nested inside response_json alongside the answer fields.
        $this->postInteractive($waba, [
            'name' => 'flow',
            'response_json' => json_encode([
                'flow_token' => 'flow_'.$sourceRun->id,
                'phone' => '+15555550101',
                'first_name' => 'Overwriting attempt',
                'email' => 'captured@example.test',
                'company' => 'AutomationXpert',
                'interests' => ['restaurants', 'retail'],
            ], JSON_THROW_ON_ERROR),
        ], 'wamid.flow.completed');

        $submission = WorkspaceContext::for($workspace->id, fn (): FormSubmission => FormSubmission::firstOrFail());
        $this->assertSame($flow->id, $submission->whatsapp_flow_id);
        $this->assertSame($contact->id, $submission->contact_id);
        $this->assertSame($sourceRun->id, $submission->automation_run_id);
        $this->assertSame('flow_'.$sourceRun->id, $submission->flow_token);
        $this->assertSame('captured@example.test', $contact->fresh()->email);
        $this->assertSame('Established', $contact->fresh()->first_name);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'wamid.flow.completed', 'body' => 'Flow completed']);

        $followUp = Automation::where('workspace_id', $workspace->id)->where('trigger_type', 'form.submitted')->firstOrFail();
        $triggeredRun = AutomationRun::where('automation_id', $followUp->id)->firstOrFail();
        $this->assertSame('Lead qualification', $triggeredRun->context['flow_name']);
        $this->assertSame('AutomationXpert', $triggeredRun->context['company']);
        $this->assertSame('["restaurants","retail"]', $triggeredRun->context['interests']);
        $this->assertIsString($triggeredRun->context['company']);
        $this->assertIsString($triggeredRun->context['interests']);
        $this->assertArrayHasKey('submitted_at', $triggeredRun->context);
        $this->assertIsString($triggeredRun->context['submitted_at']);
    }

    #[Test]
    public function a_contactless_submission_is_retained_without_creating_a_blank_contact(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        WhatsappFlowSubmitted::dispatch($workspace->id, null, 'custom-form-token', ['first_name' => 'No identity']);

        $submission = WorkspaceContext::for($workspace->id, fn (): FormSubmission => FormSubmission::firstOrFail());
        $this->assertNull($submission->contact_id);
        $this->assertSame(['first_name' => 'No identity'], $submission->answers);
        $this->assertSame(0, Contact::withoutWorkspaceScope('reason: test assertion')->count());
    }

    #[Test]
    public function a_custom_flow_token_correlates_through_the_persisted_run_context(): void
    {
        ['workspace' => $workspace, 'contact' => $contact] = $this->inboundFixture();

        $run = WorkspaceContext::for($workspace->id, function () use ($workspace, $contact): AutomationRun {
            $automation = Automation::create([
                'workspace_id' => $workspace->id,
                'name' => 'Custom token form',
                'status' => 'active',
                'nodes' => [],
                'edges' => [],
            ]);

            return AutomationRun::create([
                'automation_id' => $automation->id,
                'contact_id' => $contact->id,
                'status' => 'completed',
                'context' => ['_whatsapp_flow_token' => 'partner-supplied-token'],
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        });

        WhatsappFlowSubmitted::dispatch($workspace->id, $contact->id, 'partner-supplied-token', ['email' => 'custom@example.test']);

        $submission = WorkspaceContext::for($workspace->id, fn (): FormSubmission => FormSubmission::firstOrFail());
        $this->assertSame($run->id, $submission->automation_run_id);
        $this->assertSame('custom@example.test', $contact->fresh()->email);
    }

    #[Test]
    public function a_custom_flow_token_correlation_must_be_scoped_to_the_inbound_contact(): void
    {
        ['workspace' => $workspace, 'contact' => $firstContact] = $this->inboundFixture();

        [$firstRun, $secondRun] = WorkspaceContext::for($workspace->id, function () use ($workspace, $firstContact): array {
            $secondContact = Contact::create([
                'workspace_id' => $workspace->id,
                'phone_e164' => '+15555550102',
                'first_name' => 'Second contact',
                'source' => 'manual',
            ]);
            $firstAutomation = Automation::create([
                'workspace_id' => $workspace->id,
                'name' => 'First custom-token form',
                'status' => 'active',
                'nodes' => [],
                'edges' => [],
            ]);
            $secondAutomation = Automation::create([
                'workspace_id' => $workspace->id,
                'name' => 'Second custom-token form',
                'status' => 'active',
                'nodes' => [],
                'edges' => [],
            ]);

            $firstRun = AutomationRun::create([
                'automation_id' => $firstAutomation->id,
                'contact_id' => $firstContact->id,
                'status' => 'completed',
                'context' => ['_whatsapp_flow_token' => 'reused-custom-token'],
                'started_at' => now(),
                'completed_at' => now(),
            ]);
            $secondRun = AutomationRun::create([
                'automation_id' => $secondAutomation->id,
                'contact_id' => $secondContact->id,
                'status' => 'completed',
                'context' => ['_whatsapp_flow_token' => 'reused-custom-token'],
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            return [$firstRun, $secondRun];
        });

        WhatsappFlowSubmitted::dispatch($workspace->id, $firstContact->id, 'reused-custom-token', ['email' => 'first@example.test']);

        $submission = WorkspaceContext::for($workspace->id, fn (): FormSubmission => FormSubmission::firstOrFail());
        $this->assertSame(
            $firstRun->id,
            $submission->automation_run_id,
            'A custom token must never associate the inbound contact with another contact\'s automation run.'
        );
        $this->assertNotSame($secondRun->id, $submission->automation_run_id);
    }

    #[Test]
    public function an_existing_poll_nfm_reply_does_not_create_a_flow_submission(): void
    {
        ['workspace' => $workspace, 'waba' => $waba] = $this->inboundFixture();

        $this->postInteractive($waba, [
            'name' => 'vote',
            'response_json' => json_encode(['poll_name' => 'Lunch', 'selected_options' => []], JSON_THROW_ON_ERROR),
        ], 'wamid.poll.reply');

        $this->assertSame(0, WorkspaceContext::for($workspace->id, fn (): int => FormSubmission::count()));
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'wamid.poll.reply', 'body' => '']);
    }
}
