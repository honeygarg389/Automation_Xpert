<?php

namespace Tests\Feature\Flows;

use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WhatsappFlowMetaSyncService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappFlowMetaSyncTest extends TestCase
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
    private function connectedFlow(): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'waba-123',
            'credentials' => ['system_user_token' => 'meta-test-token'],
            'status' => 'active',
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-123']);

        return [WhatsappFlow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Lead capture!',
            'category' => 'LEAD_GENERATION',
            'status' => WhatsappFlow::STATUS_DRAFT,
            'screens' => $this->screens(),
            'submit_settings' => ['button_text' => 'Submit', 'success_message' => 'Thanks'],
        ]), $waba->waba_id];
    }

    #[Test]
    public function successful_create_upload_and_publish_updates_the_local_flow(): void
    {
        [$flow, $wabaId] = $this->connectedFlow();
        Http::fake([
            "https://graph.facebook.com/v20.0/{$wabaId}/flows" => Http::response(['id' => 'meta-flow-1']),
            'https://graph.facebook.com/v20.0/meta-flow-1/assets' => Http::response(['success' => true, 'validation_errors' => []]),
            'https://graph.facebook.com/v20.0/meta-flow-1/publish' => Http::response(['success' => true]),
        ]);

        $service = app(WhatsappFlowMetaSyncService::class);
        $this->assertTrue($service->syncToMeta($flow)['success']);
        $this->assertSame('meta-flow-1', $flow->fresh()->meta_flow_id);
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT, $flow->fresh()->meta_sync_status);

        $this->assertTrue($service->publishToMeta($flow->fresh())['success']);
        $flow = $flow->fresh();
        $this->assertSame(WhatsappFlow::STATUS_PUBLISHED, $flow->status);
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_PUBLISHED, $flow->meta_sync_status);
        Http::assertSentCount(3);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === "https://graph.facebook.com/v20.0/{$wabaId}/flows" && $request['name'] === app(WhatsappFlowMetaSyncService::class)->metaNameFor($flow));
    }

    #[Test]
    public function upload_validation_errors_are_stored_and_returned_to_the_client(): void
    {
        [$flow, $wabaId] = $this->connectedFlow();
        $errors = [[
            'error' => 'INVALID_PROPERTY', 'error_type' => 'JSON_SCHEMA_ERROR',
            'message' => 'The property "initial-text" cannot be specified.', 'line_start' => 46,
        ]];
        Http::fake([
            "https://graph.facebook.com/v20.0/{$wabaId}/flows" => Http::response(['id' => 'meta-flow-2']),
            'https://graph.facebook.com/v20.0/meta-flow-2/assets' => Http::response(['success' => true, 'validation_errors' => $errors]),
        ]);

        $result = app(WhatsappFlowMetaSyncService::class)->syncToMeta($flow);

        $this->assertFalse($result['success']);
        $this->assertSame($errors, $result['validation_errors']);
        $this->assertEquals($errors, $flow->fresh()->meta_validation_errors, 'JSON object-key order is not semantically meaningful after MySQL round-trip.');
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_FAILED, $flow->fresh()->meta_sync_status);
        $this->assertSame('Meta found validation errors in this Flow JSON.', $flow->fresh()->meta_sync_error);
    }

    #[Test]
    public function missing_management_permission_is_a_specific_actionable_failure(): void
    {
        [$flow, $wabaId] = $this->connectedFlow();
        Http::fake([
            "https://graph.facebook.com/v20.0/{$wabaId}/flows" => Http::response([
                'error' => ['message' => 'Application does not have permission for this action', 'type' => 'OAuthException', 'code' => 10],
            ], 403),
        ]);

        $result = app(WhatsappFlowMetaSyncService::class)->syncToMeta($flow);

        $this->assertFalse($result['success']);
        $this->assertSame(WhatsappFlowMetaSyncService::MISSING_MANAGEMENT_PERMISSION_MESSAGE, $result['message']);
        $this->assertSame(WhatsappFlowMetaSyncService::MISSING_MANAGEMENT_PERMISSION_MESSAGE, $flow->fresh()->meta_sync_error);
        $this->assertNull($flow->fresh()->meta_flow_id);
    }

    #[Test]
    public function re_syncing_an_existing_meta_flow_only_uploads_fresh_json(): void
    {
        [$flow] = $this->connectedFlow();
        $flow->update(['meta_flow_id' => 'existing-meta-flow']);
        Http::fake([
            'https://graph.facebook.com/v20.0/existing-meta-flow/assets' => Http::response(['success' => true, 'validation_errors' => []]),
        ]);

        $this->assertTrue(app(WhatsappFlowMetaSyncService::class)->syncToMeta($flow)['success']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (HttpRequest $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/flows'));
        Http::assertSent(fn (HttpRequest $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/existing-meta-flow/assets'));
    }

    #[Test]
    public function publish_is_blocked_before_any_meta_sync_has_happened(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Not synced', 'category' => 'OTHER', 'status' => 'draft',
            'screens' => $this->screens(), 'submit_settings' => [],
        ]);
        Http::fake();

        $this->actingAs($user)->post(route('client.flows.publish', $flow->uuid))
            ->assertRedirect()
            ->assertSessionHas('error', 'Sync this Flow to Meta before publishing it.');
        Http::assertNothingSent();
    }

    #[Test]
    public function meta_name_is_ascii_safe_capped_and_unique_across_workspaces(): void
    {
        [$flow] = $this->connectedFlow();
        $other = $flow->replicate();
        $other->workspace_id = $flow->workspace_id + 1;
        $other->uuid = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
        $other->name = str_repeat('Nämé with spaces!', 12);

        $service = app(WhatsappFlowMetaSyncService::class);
        $name = $service->metaNameFor($other);
        $this->assertLessThanOrEqual(64, strlen($name));
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name);
        $this->assertNotSame($service->metaNameFor($flow), $name);
    }
}
