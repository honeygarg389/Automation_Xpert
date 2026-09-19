<?php

namespace Tests\Feature\Flows;

use App\Models\Plan;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WhatsappFlowMetaSyncService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Section A — the smart "Publish to Meta" chain: validate -> sync -> stop at
 * Meta's validation errors -> publish -> refresh status. These tests exercise
 * WhatsappFlowMetaSyncService::publishToMeta() directly (unit-level, same
 * style as WhatsappFlowMetaSyncTest) since that is where the chain itself
 * lives; the controller route (publish-to-meta) is a one-line passthrough
 * already covered indirectly by these.
 */
class WhatsappFlowPublishToMetaTest extends TestCase
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

    private function screens(): array
    {
        return [[
            'id' => 'contact', 'title' => 'Contact', 'fields' => [[
                'id' => 'name', 'type' => 'text', 'label' => 'Name', 'name' => 'name',
                'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
            ]],
        ]];
    }

    /** @return array{0: WhatsappFlow, 1: string} */
    private function connectedFlow(array $overrides = []): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'waba-pm',
            'credentials' => ['system_user_token' => 'meta-test-token'],
            'status' => 'active',
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-pm']);

        return [WhatsappFlow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Chain flow',
            'category' => 'LEAD_GENERATION',
            'status' => WhatsappFlow::STATUS_DRAFT,
            'screens' => $this->screens(),
            'submit_settings' => ['button_text' => 'Submit', 'success_message' => 'Thanks'],
            ...$overrides,
        ]), $waba->waba_id];
    }

    #[Test]
    public function the_chain_stops_at_validation_errors_without_ever_calling_publish(): void
    {
        [$flow, $wabaId] = $this->connectedFlow();
        $errors = [[
            'error' => 'INVALID_PROPERTY', 'error_type' => 'JSON_SCHEMA_ERROR',
            'message' => 'The property "initial-text" cannot be specified.', 'line_start' => 12,
        ]];
        Http::fake([
            "https://graph.facebook.com/v20.0/{$wabaId}/flows" => Http::response(['id' => 'meta-flow-chain-1']),
            'https://graph.facebook.com/v20.0/meta-flow-chain-1/assets' => Http::response(['success' => true, 'validation_errors' => $errors]),
            'https://graph.facebook.com/v20.0/meta-flow-chain-1/publish' => Http::response(['success' => true]),
        ]);

        $result = app(WhatsappFlowMetaSyncService::class)->publishToMeta($flow);

        $this->assertFalse($result['success']);
        $this->assertSame($errors, $result['validation_errors']);
        Http::assertNotSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/publish'));
        $flow = $flow->fresh();
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_FAILED, $flow->meta_sync_status);
        $this->assertSame(WhatsappFlow::STATUS_DRAFT, $flow->status, 'A Flow that failed Meta validation must never flip to published.');
    }

    #[Test]
    public function the_chain_stops_before_syncing_when_the_flow_has_no_content(): void
    {
        [$flow, $wabaId] = $this->connectedFlow([
            'screens' => [['id' => 'empty', 'title' => 'Empty', 'fields' => []]],
        ]);
        Http::fake();

        $result = app(WhatsappFlowMetaSyncService::class)->publishToMeta($flow);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
        $this->assertNull($flow->fresh()->meta_flow_id, 'The compiler validation failure must happen before any Graph API call, including create.');
    }

    #[Test]
    public function a_fully_valid_flow_completes_every_step_of_the_chain_and_ends_published(): void
    {
        [$flow, $wabaId] = $this->connectedFlow();
        Http::fake([
            "https://graph.facebook.com/v20.0/{$wabaId}/flows" => Http::response(['id' => 'meta-flow-chain-2']),
            'https://graph.facebook.com/v20.0/meta-flow-chain-2/assets' => Http::response(['success' => true, 'validation_errors' => []]),
            'https://graph.facebook.com/v20.0/meta-flow-chain-2/publish' => Http::response(['success' => true]),
        ]);

        $result = app(WhatsappFlowMetaSyncService::class)->publishToMeta($flow);

        $this->assertTrue($result['success']);
        $flow = $flow->fresh();
        $this->assertSame('meta-flow-chain-2', $flow->meta_flow_id);
        $this->assertSame(WhatsappFlow::STATUS_PUBLISHED, $flow->status);
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_PUBLISHED, $flow->meta_sync_status);
        Http::assertSentCount(3);
    }

    #[Test]
    public function the_separate_sync_only_action_still_works_unchanged_and_never_publishes(): void
    {
        [$flow, $wabaId] = $this->connectedFlow();
        Http::fake([
            "https://graph.facebook.com/v20.0/{$wabaId}/flows" => Http::response(['id' => 'meta-flow-chain-3']),
            'https://graph.facebook.com/v20.0/meta-flow-chain-3/assets' => Http::response(['success' => true, 'validation_errors' => []]),
        ]);

        $result = app(WhatsappFlowMetaSyncService::class)->syncToMeta($flow);

        $this->assertTrue($result['success']);
        $flow = $flow->fresh();
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT, $flow->meta_sync_status);
        $this->assertSame(WhatsappFlow::STATUS_DRAFT, $flow->status);
        Http::assertNotSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/publish'));
    }

    #[Test]
    public function the_publish_to_meta_route_is_reachable_and_gated_by_the_flows_entitlement(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Route check', 'category' => 'OTHER', 'status' => 'draft',
            'screens' => [['id' => 'empty', 'title' => 'Empty', 'fields' => []]], 'submit_settings' => [],
        ]);
        Http::fake();

        // No content -> the chain fails at the compiler step, but the important
        // thing here is that the route itself resolves and responds, not 403s.
        $this->actingAs($user)->post(route('client.flows.publish-to-meta', $flow->uuid))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
