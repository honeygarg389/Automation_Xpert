<?php

namespace Tests\Feature\Flows;

use App\Models\Client;
use App\Models\Plan;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Flow builder's "Send Test" action — WhatsappFlowController::testSend().
 *
 * Deliberately mocks WhatsappDriver (the same technique
 * AutomationNodeBehaviourTest already uses) rather than faking the Graph API
 * over HTTP — the claim under test is "this reuses AutomationEngine's own
 * payload construction and send path", which is a claim about the Message
 * row AutomationEngine writes, not about a particular HTTP client.
 */
class WhatsappFlowTestSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    /** @return array{0:array{id:string,title:string,fields:list<array<string,mixed>>}} */
    private function screens(): array
    {
        return [[
            'id' => 'contact', 'title' => 'Contact', 'fields' => [[
                'id' => 'name', 'type' => 'text', 'label' => 'Name', 'name' => 'name',
                'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
            ]],
        ]];
    }

    private function grantFlowsToClient(Client $client): void
    {
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
    }

    private function fakeDriver(): void
    {
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturn('wamid.test.123');
        $this->app->instance(WhatsappDriver::class, $driver);
    }

    private function connectWaba(int $workspaceId): void
    {
        WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspaceId,
            'status' => 'active',
        ]);
        ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'Test WA',
            'phone_number_id' => 'phone-'.$workspaceId,
        ]);
    }

    /**
     * Unlike connectWaba() above, this also creates a WhatsappPhoneNumber —
     * required by CloudApiClient::forWorkspace() to return a real client at
     * all. The draft-mode fix's fresh getFlow() check needs exactly that;
     * connectWaba() alone (no phone number) is what every OTHER test in
     * this file relies on to keep CloudApiClient::forWorkspace() returning
     * null, so flowSendModeFor() short-circuits before any HTTP call —
     * this variant is only for the tests that need the check to actually run.
     */
    private function connectWabaWithPhoneNumber(int $workspaceId): void
    {
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspaceId,
            'status' => 'active',
            'credentials' => ['system_user_token' => 'test-token'],
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-'.$workspaceId]);
        ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'Test WA',
            'phone_number_id' => 'phone-'.$workspaceId,
        ]);
    }

    private function syncedFlow(int $workspaceId, string $metaFlowId = 'meta-flow-1', string $name = 'Synced Flow'): WhatsappFlow
    {
        return WorkspaceContext::for($workspaceId, fn (): WhatsappFlow => WhatsappFlow::create([
            'workspace_id' => $workspaceId, 'name' => $name, 'category' => 'OTHER', 'status' => 'draft',
            'screens' => $this->screens(), 'meta_flow_id' => $metaFlowId,
        ]));
    }

    #[Test]
    public function it_rejects_sending_an_unsynced_flow(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $flow = WorkspaceContext::for($workspace->id, fn (): WhatsappFlow => WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Unsynced', 'category' => 'OTHER', 'status' => 'draft',
            'screens' => $this->screens(), 'meta_flow_id' => null,
        ]));

        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Sync this Flow to Meta before sending a test.']);
    }

    #[Test]
    public function it_rejects_when_no_waba_is_connected(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $flow = $this->syncedFlow($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Connect an active WhatsApp Business Account before sending a test message.']);
    }

    #[Test]
    public function it_rejects_an_invalid_phone_number(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $this->connectWaba($workspace->id);
        $flow = $this->syncedFlow($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => 'not-a-number'])
            ->assertStatus(422);
    }

    #[Test]
    public function it_creates_a_new_contact_and_reuses_an_existing_one_without_duplicating_or_overwriting_it(): void
    {
        $this->fakeDriver();
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $this->connectWaba($workspace->id);
        $flow = $this->syncedFlow($workspace->id);

        // First send — no contact yet, must create one, tagged for traceability.
        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertOk();

        $contact = WorkspaceContext::for($workspace->id, fn () => Contact::where('phone_e164', '+919690309316')->first());
        $this->assertNotNull($contact, 'A new contact must be created for a first-time test-send number.');
        $this->assertSame('flow_test_send', $contact->source);
        $this->assertSame(1, WorkspaceContext::for($workspace->id, fn (): int => Contact::where('phone_e164', '+919690309316')->count()));

        // Simulate it having since become a real, organically-sourced contact.
        WorkspaceContext::for($workspace->id, fn () => $contact->update(['first_name' => 'Real Person', 'source' => 'manual']));

        // A second test-send to the same number must find, not duplicate it —
        // and must not clobber its now-real name/source.
        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertOk();

        $this->assertSame(1, WorkspaceContext::for($workspace->id, fn (): int => Contact::where('phone_e164', '+919690309316')->count()), 'A second test-send to the same number must not create a duplicate contact.');
        $reloaded = WorkspaceContext::for($workspace->id, fn () => $contact->fresh());
        $this->assertSame('Real Person', $reloaded->first_name, 'Reusing an existing contact must not overwrite its real name.');
        $this->assertSame('manual', $reloaded->source, 'Reusing an existing contact must not overwrite its real source.');
    }

    #[Test]
    public function the_send_reuses_the_exact_payload_shape_the_automation_node_produces(): void
    {
        $this->fakeDriver();
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $this->connectWaba($workspace->id);
        $flow = $this->syncedFlow($workspace->id, 'meta-flow-77', 'Order Form');

        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertOk();

        $message = WorkspaceContext::for($workspace->id, fn () => Message::where('direction', 'out')->latest('id')->first());
        $this->assertNotNull($message);
        $this->assertSame('interactive', $message->type);
        // Same shape AutomationNodeBehaviourTest::test_whatsapp_form_builds_flow_interactive()
        // asserts for the automation-triggered send — proving sendFlowTestMessage()
        // reuses buildFlowInteractivePayload(), not a second, parallel payload builder.
        $this->assertSame('flow', $message->payload['interactive']['type']);
        $this->assertSame('meta-flow-77', $message->payload['interactive']['action']['parameters']['flow_id']);
        $this->assertSame('navigate', $message->payload['interactive']['action']['parameters']['flow_action']);
        $this->assertSame('3', $message->payload['interactive']['action']['parameters']['flow_message_version']);
        $this->assertArrayHasKey('flow_token', $message->payload['interactive']['action']['parameters']);
        $this->assertStringStartsWith('flow_test_', $message->payload['interactive']['action']['parameters']['flow_token']);
    }

    /**
     * The draft-mode fix — confirmed live against Meta's real API during
     * this session's investigation: sending a Flow that is currently
     * DRAFT on Meta without `mode: 'draft'` is rejected outright
     * ("Sending a flow in a draft state requires setting the mode to
     * 'draft'.", error 131009).
     */
    #[Test]
    public function sending_a_test_for_a_draft_flow_includes_mode_draft_in_the_payload(): void
    {
        $this->fakeDriver();
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $this->connectWabaWithPhoneNumber($workspace->id);
        $flow = $this->syncedFlow($workspace->id, 'meta-flow-draft', 'Draft Flow');
        Http::fake(['https://graph.facebook.com/v20.0/meta-flow-draft?*' => Http::response(['id' => 'meta-flow-draft', 'status' => 'DRAFT'])]);

        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertOk();

        $message = WorkspaceContext::for($workspace->id, fn () => Message::where('direction', 'out')->latest('id')->first());
        $this->assertSame('draft', $message->payload['interactive']['action']['parameters']['mode'] ?? null);
    }

    /**
     * The symmetric case — also confirmed live: `mode: 'draft'` present on
     * a Flow that ISN'T Draft is REJECTED just as hard ("The flow is not
     * in a draft state, but the mode is set to 'draft'.", the same error
     * code) — there is no safe universal default, it has to be correct in
     * both directions.
     */
    #[Test]
    public function sending_a_test_for_a_published_flow_omits_the_mode_parameter(): void
    {
        $this->fakeDriver();
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $this->connectWabaWithPhoneNumber($workspace->id);
        $flow = $this->syncedFlow($workspace->id, 'meta-flow-published', 'Published Flow');
        Http::fake(['https://graph.facebook.com/v20.0/meta-flow-published?*' => Http::response(['id' => 'meta-flow-published', 'status' => 'PUBLISHED'])]);

        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertOk();

        $message = WorkspaceContext::for($workspace->id, fn () => Message::where('direction', 'out')->latest('id')->first());
        $this->assertArrayNotHasKey('mode', $message->payload['interactive']['action']['parameters']);
    }

    /**
     * If the fresh status check itself is inconclusive (here: no active
     * WABA/phone number, so CloudApiClient::forWorkspace() returns null
     * before any HTTP call is even attempted), the fix must fall back to
     * omitting `mode` — the behavior that already works for the common
     * (published) case — rather than guessing a status that could just as
     * easily break a currently-working send.
     */
    #[Test]
    public function an_inconclusive_status_check_falls_back_to_omitting_mode_rather_than_guessing(): void
    {
        $this->fakeDriver();
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $this->connectWaba($workspace->id);
        $flow = $this->syncedFlow($workspace->id, 'meta-flow-nocheck', 'No Check Flow');

        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertOk();

        $message = WorkspaceContext::for($workspace->id, fn () => Message::where('direction', 'out')->latest('id')->first());
        $this->assertArrayNotHasKey('mode', $message->payload['interactive']['action']['parameters']);
    }

    #[Test]
    public function a_flow_in_another_workspace_cannot_be_test_sent(): void
    {
        ['user' => $otherUser] = $this->createWorkspaceContext();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->connectWaba($workspace->id);
        $flow = $this->syncedFlow($workspace->id, 'meta-flow-1', 'Not yours');

        $this->actingAs($otherUser)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertNotFound();
    }

    /**
     * Positive control for the isolation test above: the SAME route, verb,
     * and a legitimate owner must succeed — a 404 alone is equally
     * consistent with the endpoint rejecting everyone.
     */
    #[Test]
    public function the_owning_workspace_can_test_send_its_own_flow(): void
    {
        $this->fakeDriver();
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $this->connectWaba($workspace->id);
        $flow = $this->syncedFlow($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.flows.test-send', $flow->uuid), ['phone_number' => '919690309316'])
            ->assertOk()
            ->assertJson(['message' => 'Test message sent to +919690309316.']);
    }
}
