<?php

namespace Tests\Feature\Flows;

use App\Models\Plan;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WhatsappFlowJsonCompiler;
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

        // publishOnly() — not the chained publishToMeta() — because this
        // Flow was already synced above; re-running the full chain would
        // sync a second time and break the assertSentCount(3) below.
        $this->assertTrue($service->publishOnly($flow->fresh())['success']);
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
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
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

    /**
     * Task 2 — Graph error 139001, confirmed live against Meta's real API
     * during diagnosis: "Updating attempt failed" / "Flow can only be
     * modified in Draft status", returned when a Flow's Meta-side copy has
     * been deprecated or published directly on Meta. Must produce a
     * specific message (not the generic fallback) AND self-heal the local
     * meta_sync_status via one follow-up getFlow() call.
     */
    #[Test]
    public function flow_not_in_draft_status_produces_a_specific_message_and_reconciles_local_status_to_deprecated(): void
    {
        [$flow] = $this->connectedFlow();
        $flow->update(['meta_flow_id' => 'meta-flow-deprecated']);
        Http::fake([
            'https://graph.facebook.com/v20.0/meta-flow-deprecated/assets' => Http::response([
                'error' => [
                    'message' => 'Updating attempt failed', 'type' => 'OAuthException', 'code' => 139001,
                    'error_subcode' => 4016010, 'error_user_title' => "Flow can't be updated",
                    'error_user_msg' => 'Flow can only be modified in Draft status',
                ],
            ], 400),
            'https://graph.facebook.com/v20.0/meta-flow-deprecated?*' => Http::response(['id' => 'meta-flow-deprecated', 'status' => 'DEPRECATED']),
        ]);

        $result = app(WhatsappFlowMetaSyncService::class)->syncToMeta($flow->fresh());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no longer in Draft status', $result['message']);
        $this->assertNotSame('Meta could not sync this Flow. Please try again or review your WhatsApp connection.', $result['message'], 'Must not fall through to the generic catch-all fallback.');
        $flow = $flow->fresh();
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_DEPRECATED, $flow->meta_sync_status, 'Self-healing must reconcile local status to what Meta actually reports.');
        $this->assertNull($flow->meta_sync_error, 'Deprecated is a normal terminal state (Section G) — not a standing error the dashboard badge should keep flagging red forever.');
    }

    #[Test]
    public function flow_not_in_draft_status_reconciles_to_published_when_thats_metas_real_current_status(): void
    {
        [$flow] = $this->connectedFlow();
        $flow->update(['meta_flow_id' => 'meta-flow-live']);
        Http::fake([
            'https://graph.facebook.com/v20.0/meta-flow-live/assets' => Http::response([
                'error' => ['message' => 'Updating attempt failed', 'type' => 'OAuthException', 'code' => 139001],
            ], 400),
            'https://graph.facebook.com/v20.0/meta-flow-live?*' => Http::response(['id' => 'meta-flow-live', 'status' => 'PUBLISHED']),
        ]);

        $result = app(WhatsappFlowMetaSyncService::class)->syncToMeta($flow->fresh());

        $this->assertFalse($result['success']);
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_PUBLISHED, $flow->fresh()->meta_sync_status);
    }

    /**
     * Task 3 — pullFromMeta()'s 'unchanged' branch used to skip the
     * update() call entirely, so a stale meta_sync_error from an earlier
     * failed pull attempt could sit there indefinitely once local content
     * happened to already match Meta's. Confirmed as the exact mechanism
     * behind the diagnosed flows (id=6/id=10 in that session) showing a
     * stale "Sync Error" banner despite Meta's own validation_errors being
     * empty.
     */
    #[Test]
    public function a_pull_that_finds_nothing_changed_still_clears_a_stale_meta_sync_error(): void
    {
        [$flow] = $this->connectedFlow();
        $flow->update([
            'meta_flow_id' => 'meta-flow-stale',
            'meta_sync_error' => 'Meta Flow JSON could not be read. Review the Flow JSON and try again.',
        ]);
        // Deliberately NOT ->fresh(): MySQL's JSON column storage does not
        // preserve object key insertion order the way PHP's own array
        // literals do, and PHP's `===` on arrays IS order-sensitive — a
        // real, pre-existing quirk of this column type, unrelated to this
        // fix, that would otherwise make an 'unchanged' comparison flicker
        // to 'updated' after any re-fetch. Comparing the same in-memory
        // $flow (never round-tripped through a real SELECT) against a
        // decompile() of its own freshly-compiled content is exactly how
        // pullFromMeta()'s real caller (importFlow()) uses it too.
        $metaJson = app(WhatsappFlowJsonCompiler::class)->compile($flow);
        Http::fake([
            'https://graph.facebook.com/v20.0/meta-flow-stale/assets' => Http::response(['data' => [
                ['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => 'https://assets.test/meta-flow-stale.json'],
            ]]),
            'https://assets.test/meta-flow-stale.json' => Http::response($metaJson),
        ]);

        $outcome = app(WhatsappFlowMetaSyncService::class)->pullFromMeta($flow);

        $this->assertSame('unchanged', $outcome, 'Local content already matches what Meta has — this must be the "unchanged" branch, the one that used to skip clearing the error.');
        $this->assertNull($flow->fresh()->meta_sync_error, 'A successful pull — even one finding nothing to change — is proof the Flow is fine and must clear a stale error.');
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
