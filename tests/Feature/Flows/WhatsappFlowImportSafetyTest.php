<?php

namespace Tests\Feature\Flows;

use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
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

/**
 * Import safety for Flows Meta already holds.
 *
 * A Flow whose Meta content decompile() cannot represent is left holding
 * PLACEHOLDER screens locally. Two hazards followed from that:
 *   - the failure was reported as the generic "could not be read", telling the
 *     user to "try again" at something deterministic; and
 *   - nothing stopped "Sync Draft to Meta" / "Publish" from compiling that
 *     placeholder and uploading it OVER the real content on Meta.
 *
 * These tests assert the STORED classification and that Meta is never
 * contacted for a blocked Flow — not merely what the controller flashed.
 */
class WhatsappFlowImportSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD_MESSAGE = 'cannot be synced until support is added';

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

    /** @return array{user:User, workspace:Workspace, client:Client} */
    private function connectedWorkspace(): array
    {
        $ctx = $this->createWorkspaceContext();
        $this->attachPlanToClient($ctx['client'], Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $ctx['workspace']->id, 'waba_id' => 'waba-safety', 'status' => 'active',
            'credentials' => ['system_user_token' => 'meta-test-token'],
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-safety']);

        return $ctx;
    }

    /**
     * The placeholder a failed import leaves behind (WhatsappFlowMetaSyncService::placeholderScreens()).
     *
     * @return list<array<string, mixed>>
     */
    private function placeholderScreens(): array
    {
        return [[
            'id' => 'step_1', 'title' => 'Imported from Meta', 'fields' => [[
                'id' => 'field', 'type' => 'text', 'label' => 'Field', 'name' => 'field',
                'required' => false, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
            ]],
        ]];
    }

    /** @param  array<string, mixed>  $overrides */
    private function linkedFlow(int $workspaceId, array $overrides = []): WhatsappFlow
    {
        return WorkspaceContext::for($workspaceId, fn (): WhatsappFlow => WhatsappFlow::create(array_merge([
            'workspace_id' => $workspaceId, 'name' => 'Imported', 'category' => 'OTHER', 'status' => WhatsappFlow::STATUS_DRAFT,
            'screens' => $this->placeholderScreens(), 'meta_flow_id' => 'meta-lossy',
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT,
        ], $overrides)));
    }

    private function fixtureBody(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/MetaFlows/{$name}.json"));
    }

    private function fakeMetaAssets(string $metaFlowId, mixed $downloadResponse): void
    {
        Http::fake([
            "https://graph.facebook.com/v20.0/{$metaFlowId}/assets" => Http::response(['data' => [
                ['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => "https://assets.test/{$metaFlowId}.json"],
            ]]),
            "https://assets.test/{$metaFlowId}.json" => $downloadResponse,
        ]);
    }

    // ── Task 1: a specific, honest classification ──────────────────────────

    #[Test]
    public function an_unsupported_shape_stores_the_specific_reason_not_the_generic_read_failure(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, [
            'meta_sync_error' => 'Meta Flow JSON could not be read. Review the Flow JSON and try again.',
        ]);
        $this->fakeMetaAssets('meta-lossy', Http::response($this->fixtureBody('terminal_screen_with_inputs_no_form'), 200, ['Content-Type' => 'application/json']));

        $outcome = app(WhatsappFlowMetaSyncService::class)->pullFromMeta($flow);

        $stored = WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::find($flow->id));
        $this->assertSame('failed', $outcome);
        $this->assertNotNull($stored->import_unsupported_reason, 'The lossy classification must be stored, not just flashed.');
        $this->assertStringContainsString('FORM_SCREEN', $stored->import_unsupported_reason);
        $this->assertStringContainsString('client_name', $stored->import_unsupported_reason);
        $this->assertSame($stored->import_unsupported_reason, $stored->meta_sync_error,
            'The specific reason replaces the old generic message in meta_sync_error too.');
        $this->assertStringNotContainsString('could not be read', (string) $stored->meta_sync_error);
        // Nothing local was overwritten by the failed decompile.
        $this->assertSame('step_1', $stored->screens[0]['id']);
    }

    /** POSITIVE CONTROL: a transport failure is still a plain read failure and must NOT be classified lossy. */
    #[Test]
    public function a_download_failure_is_still_a_read_failure_and_is_not_classified_as_unsupported(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id);
        $this->fakeMetaAssets('meta-lossy', Http::response('boom', 500));

        app(WhatsappFlowMetaSyncService::class)->pullFromMeta($flow);

        $stored = WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::find($flow->id));
        $this->assertNotNull($stored->meta_sync_error);
        $this->assertNull($stored->import_unsupported_reason, 'A network failure says nothing about the Flow\'s shape and must not block it.');
    }

    #[Test]
    public function a_malformed_payload_is_a_read_failure_not_an_unsupported_shape(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id);
        $this->fakeMetaAssets('meta-lossy', Http::response('{this is not json', 200));

        app(WhatsappFlowMetaSyncService::class)->pullFromMeta($flow);

        $stored = WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::find($flow->id));
        $this->assertNull($stored->import_unsupported_reason);
        $this->assertNotNull($stored->meta_sync_error);
    }

    #[Test]
    public function the_classification_survives_a_later_transient_failure(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, ['import_unsupported_reason' => 'Screen "X" is unsupported.']);
        $this->fakeMetaAssets('meta-lossy', Http::response('boom', 500));

        app(WhatsappFlowMetaSyncService::class)->pullFromMeta($flow);

        $stored = WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::find($flow->id));
        $this->assertSame('Screen "X" is unsupported.', $stored->import_unsupported_reason,
            'A network blip must not silently lift the guard and re-expose the overwrite hazard.');
    }

    #[Test]
    public function a_later_successful_pull_of_supported_content_clears_the_classification(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, [
            'import_unsupported_reason' => 'Screen "X" is unsupported.',
            'meta_sync_error' => 'Screen "X" is unsupported.',
        ]);
        $supported = json_decode($this->fixtureBody('own_compiler_shape_control'), true);
        $this->fakeMetaAssets('meta-lossy', Http::response($supported));

        $outcome = app(WhatsappFlowMetaSyncService::class)->pullFromMeta($flow);

        $stored = WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::find($flow->id));
        $this->assertSame('updated', $outcome);
        $this->assertNull($stored->import_unsupported_reason);
        $this->assertNull($stored->meta_sync_error);
    }

    #[Test]
    public function the_unchanged_branch_also_clears_the_classification(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, [
            'screens' => [[
                'id' => 'contact', 'title' => 'Contact', 'fields' => [[
                    'id' => 'name', 'type' => 'text', 'label' => 'Name', 'name' => 'name',
                    'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
                ]],
            ]],
            'submit_settings' => ['button_text' => 'Submit', 'success_message' => 'Thanks'],
            'import_unsupported_reason' => 'Stale classification.',
        ]);
        // Same in-memory instance on purpose (JSON column key order — see WhatsappFlowMetaSyncTest).
        $this->fakeMetaAssets('meta-lossy', Http::response(app(WhatsappFlowJsonCompiler::class)->compile($flow)));

        $outcome = app(WhatsappFlowMetaSyncService::class)->pullFromMeta($flow);

        $this->assertSame('unchanged', $outcome);
        $this->assertNull(WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::find($flow->id))->import_unsupported_reason);
    }

    // ── Task 3: server-side guard against the placeholder overwrite ─────────

    #[Test]
    public function sync_draft_to_meta_is_refused_for_a_lossy_import_and_meta_is_never_contacted(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, ['import_unsupported_reason' => 'Screen "FORM_SCREEN" is unsupported.']);
        Http::fake();

        $this->actingAs($user)->from(route('client.flows.index'))
            ->post(route('client.flows.sync', $flow->uuid))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, self::GUARD_MESSAGE));

        Http::assertNothingSent();
        $stored = WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::find($flow->id));
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT, $stored->meta_sync_status,
            'The refusal must not touch the row: not "syncing", not "failed".');
    }

    #[Test]
    public function publish_to_meta_is_refused_for_a_lossy_import_and_meta_is_never_contacted(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, ['import_unsupported_reason' => 'Screen "FORM_SCREEN" is unsupported.']);
        Http::fake();

        $this->actingAs($user)->from(route('client.flows.index'))
            ->post(route('client.flows.publish-to-meta', $flow->uuid))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, self::GUARD_MESSAGE));

        Http::assertNothingSent();
    }

    #[Test]
    public function the_publish_only_route_is_refused_for_a_lossy_import_too(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, ['import_unsupported_reason' => 'Screen "FORM_SCREEN" is unsupported.']);
        Http::fake();

        $this->actingAs($user)->from(route('client.flows.index'))
            ->post(route('client.flows.publish', $flow->uuid))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, self::GUARD_MESSAGE));

        Http::assertNothingSent();
        $this->assertSame(WhatsappFlow::STATUS_DRAFT, WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::find($flow->id))->status);
    }

    #[Test]
    public function the_guard_lives_in_the_service_not_only_the_controller(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, ['import_unsupported_reason' => 'Screen "FORM_SCREEN" is unsupported.']);
        Http::fake();
        $service = app(WhatsappFlowMetaSyncService::class);

        foreach ([$service->syncToMeta($flow), $service->publishToMeta($flow), $service->publishOnly($flow)] as $result) {
            $this->assertFalse($result['success']);
            $this->assertStringContainsString(self::GUARD_MESSAGE, $result['message']);
        }
        Http::assertNothingSent();
    }

    /**
     * POSITIVE CONTROL — the SAME route, verb and user for an otherwise identical
     * Flow WITHOUT the classification must still reach Meta. Without this, every
     * refusal above is equally consistent with the endpoint refusing everyone.
     */
    #[Test]
    public function the_same_sync_for_a_normal_flow_still_reaches_meta(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, [
            'meta_flow_id' => 'existing-meta-flow',
            'screens' => [[
                'id' => 'contact', 'title' => 'Contact', 'fields' => [[
                    'id' => 'name', 'type' => 'text', 'label' => 'Name', 'name' => 'name',
                    'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
                ]],
            ]],
        ]);
        Http::fake(['https://graph.facebook.com/v20.0/existing-meta-flow/assets' => Http::response(['success' => true, 'validation_errors' => []])]);

        $this->actingAs($user)->from(route('client.flows.index'))
            ->post(route('client.flows.sync', $flow->uuid))
            ->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/existing-meta-flow/assets'));
    }

    // ── The Duplicate route must not launder a lossy Flow into a clean one ──

    #[Test]
    public function duplicating_a_lossy_import_carries_the_classification_so_the_copy_is_guarded_too(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, ['import_unsupported_reason' => 'Screen "FORM_SCREEN" is unsupported.']);
        Http::fake();

        $this->actingAs($user)->post(route('client.flows.duplicate', $flow->uuid));

        $copy = WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::where('name', 'Imported (Copy)')->firstOrFail());
        $this->assertSame('Screen "FORM_SCREEN" is unsupported.', $copy->import_unsupported_reason,
            'A copy holding the same placeholder, next to a Meta clone of the real content, would otherwise be syncable.');

        $this->actingAs($user)->from(route('client.flows.index'))
            ->post(route('client.flows.sync', $copy->uuid))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, self::GUARD_MESSAGE));
    }

    /** POSITIVE CONTROL: duplicating an ordinary Flow must not invent a classification. */
    #[Test]
    public function duplicating_a_normal_flow_leaves_the_copy_unclassified(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id);
        Http::fake();

        $this->actingAs($user)->post(route('client.flows.duplicate', $flow->uuid));

        $copy = WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::where('name', 'Imported (Copy)')->firstOrFail());
        $this->assertNull($copy->import_unsupported_reason);
    }

    // ── The frontend needs the classification ───────────────────────────────

    #[Test]
    public function the_dashboard_and_builder_receive_the_classification(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->connectedWorkspace();
        $flow = $this->linkedFlow($workspace->id, ['import_unsupported_reason' => 'Screen "FORM_SCREEN" is unsupported.']);

        $this->actingAs($user)->get(route('client.flows.index'))
            ->assertInertia(fn ($page) => $page
                ->where('flows.0.import_unsupported_reason', 'Screen "FORM_SCREEN" is unsupported.')
                // The refusal wording is supplied by the server's single definition, not copied into the pages.
                ->where('flows.0.import_guard_message', WhatsappFlowMetaSyncService::LOSSY_IMPORT_MESSAGE));
        $this->actingAs($user)->get(route('client.flows.edit', $flow->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('flow.import_unsupported_reason', 'Screen "FORM_SCREEN" is unsupported.')
                ->where('flow.import_guard_message', WhatsappFlowMetaSyncService::LOSSY_IMPORT_MESSAGE));
    }

    /** POSITIVE CONTROL: an ordinary Flow must carry no classification and no guard message. */
    #[Test]
    public function a_normal_flow_carries_neither_the_classification_nor_the_guard_message(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->connectedWorkspace();
        $this->linkedFlow($workspace->id);

        $this->actingAs($user)->get(route('client.flows.index'))
            ->assertInertia(fn ($page) => $page
                ->where('flows.0.import_unsupported_reason', null)
                ->where('flows.0.import_guard_message', null));
    }
}
