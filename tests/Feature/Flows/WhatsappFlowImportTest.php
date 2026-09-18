<?php

namespace Tests\Feature\Flows;

use App\Models\Plan;
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
use RuntimeException;
use Tests\TestCase;

/**
 * Slice 8. The import-picker feature — the thing correctly named "Sync Meta
 * Flows" now that the bulk reconcile action has been renamed to "Sync
 * Status". Two directions, two tests of the same underlying claim:
 * listImportableFlows() finds what Meta has and we don't; importFlow()
 * creates it here.
 */
class WhatsappFlowImportTest extends TestCase
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

    /** @return array{id:string,title:string,fields:list<array<string,mixed>>}[] */
    private function screens(string $label = 'Name'): array
    {
        return [[
            'id' => 'contact', 'title' => 'Contact', 'fields' => [[
                'id' => 'name', 'type' => 'text', 'label' => $label, 'name' => 'name',
                'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
            ]],
        ]];
    }

    /** @return array{0: Workspace, 1: string} workspace and its WABA id */
    private function connectedWorkspace(): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'waba-'.$workspace->id,
            'credentials' => ['system_user_token' => 'meta-test-token'],
            'status' => 'active',
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-'.$workspace->id]);

        return [$workspace, $waba->waba_id];
    }

    #[Test]
    public function the_picker_excludes_meta_flows_that_already_have_a_matching_local_record(): void
    {
        [$workspace, $wabaId] = $this->connectedWorkspace();
        WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Already linked',
            'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_DRAFT,
            'screens' => $this->screens(),
            'meta_flow_id' => 'already-linked-1',
        ]));

        Http::fake([
            "https://graph.facebook.com/v20.0/{$wabaId}/flows*" => Http::response(['data' => [
                ['id' => 'already-linked-1', 'name' => 'Already linked', 'status' => 'DRAFT', 'categories' => ['OTHER'], 'validation_errors' => []],
                ['id' => 'not-linked-1', 'name' => 'New on Meta', 'status' => 'DRAFT', 'categories' => ['LEAD_GENERATION'], 'validation_errors' => []],
            ]]),
        ]);

        $importable = WorkspaceContext::for(
            $workspace->id,
            fn () => app(WhatsappFlowMetaSyncService::class)->listImportableFlows($workspace->id)
        );

        $this->assertCount(1, $importable, 'The already-linked Meta flow must not appear as importable.');
        $this->assertSame('not-linked-1', $importable[0]['meta_flow_id']);
        $this->assertSame('New on Meta', $importable[0]['name']);
        $this->assertSame('DRAFT', $importable[0]['status']);
        $this->assertSame(['LEAD_GENERATION'], $importable[0]['categories']);
    }

    #[Test]
    public function importing_creates_a_new_local_flow_via_decompile_with_metas_name_category_and_status(): void
    {
        [$workspace, $wabaId] = $this->connectedWorkspace();
        $remote = new WhatsappFlow([
            'name' => 'Imported source', 'screens' => $this->screens('Remote label'),
            'submit_settings' => ['button_text' => 'Go', 'success_message' => 'Done'],
        ]);
        $metaJson = app(WhatsappFlowJsonCompiler::class)->compile($remote);

        Http::fake([
            'https://graph.facebook.com/v20.0/meta-new-1?*' => Http::response([
                'id' => 'meta-new-1', 'name' => 'Meta-Side Name', 'status' => 'PUBLISHED', 'categories' => ['SURVEY'],
            ]),
            'https://graph.facebook.com/v20.0/meta-new-1/assets' => Http::response([
                'data' => [['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => 'https://assets.test/meta-new-1.json']],
            ]),
            'https://assets.test/meta-new-1.json' => Http::response($metaJson),
        ]);

        $flow = WorkspaceContext::for(
            $workspace->id,
            fn () => app(WhatsappFlowMetaSyncService::class)->importFlow($workspace->id, 'meta-new-1')
        );

        $this->assertSame($workspace->id, $flow->workspace_id);
        $this->assertSame('meta-new-1', $flow->meta_flow_id);
        $this->assertSame('Meta-Side Name', $flow->name);
        $this->assertSame('SURVEY', $flow->category);
        $this->assertSame(WhatsappFlow::STATUS_PUBLISHED, $flow->status, 'Meta reported PUBLISHED — the local status should reflect it.');
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_PUBLISHED, $flow->meta_sync_status);
        $this->assertSame('Remote label', $flow->screens[0]['fields'][0]['label'], 'The real content must come from decompile(), not the placeholder.');
        $this->assertSame('Go', $flow->submit_settings['button_text']);

        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://graph.facebook.com/v20.0/meta-new-1?'));
    }

    #[Test]
    public function a_failed_content_fetch_keeps_the_imported_row_with_the_placeholder_and_a_recorded_error(): void
    {
        [$workspace, $wabaId] = $this->connectedWorkspace();

        Http::fake([
            'https://graph.facebook.com/v20.0/meta-broken-1?*' => Http::response([
                'id' => 'meta-broken-1', 'name' => 'Broken Flow', 'status' => 'DRAFT', 'categories' => ['OTHER'],
            ]),
            'https://graph.facebook.com/v20.0/meta-broken-1/assets' => Http::response([], 500),
        ]);

        $flow = WorkspaceContext::for(
            $workspace->id,
            fn () => app(WhatsappFlowMetaSyncService::class)->importFlow($workspace->id, 'meta-broken-1')
        );

        $this->assertSame('meta-broken-1', $flow->meta_flow_id, 'The row must still exist — retryable via Sync Status, not silently discarded.');
        $this->assertNotNull($flow->meta_sync_error);
        $this->assertSame('step_1', $flow->screens[0]['id'], 'Content fetch failed — the placeholder screens must remain, not corrupt/empty data.');
    }

    #[Test]
    public function importing_into_workspace_a_never_creates_or_leaks_into_workspace_b(): void
    {
        [$workspaceA, $wabaIdA] = $this->connectedWorkspace();
        [$workspaceB] = $this->connectedWorkspace();
        $remote = new WhatsappFlow(['name' => 'x', 'screens' => $this->screens()]);
        $metaJson = app(WhatsappFlowJsonCompiler::class)->compile($remote);

        Http::fake([
            'https://graph.facebook.com/v20.0/meta-iso-1?*' => Http::response([
                'id' => 'meta-iso-1', 'name' => 'Isolation Test Flow', 'status' => 'DRAFT', 'categories' => ['OTHER'],
            ]),
            'https://graph.facebook.com/v20.0/meta-iso-1/assets' => Http::response([
                'data' => [['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => 'https://assets.test/meta-iso-1.json']],
            ]),
            'https://assets.test/meta-iso-1.json' => Http::response($metaJson),
        ]);

        $flow = WorkspaceContext::for(
            $workspaceA->id,
            fn () => app(WhatsappFlowMetaSyncService::class)->importFlow($workspaceA->id, 'meta-iso-1')
        );

        $this->assertSame($workspaceA->id, $flow->workspace_id);

        // Positive control: workspace A's own scoped query finds it.
        $foundInA = WorkspaceContext::for($workspaceA->id, fn () => WhatsappFlow::query()->where('meta_flow_id', 'meta-iso-1')->count());
        $this->assertSame(1, $foundInA);

        // Negative: workspace B's scoped query must see nothing.
        $foundInB = WorkspaceContext::for($workspaceB->id, fn () => WhatsappFlow::query()->where('meta_flow_id', 'meta-iso-1')->count());
        $this->assertSame(0, $foundInB, 'Importing into workspace A must never be visible to workspace B.');
    }

    #[Test]
    public function listing_importable_flows_without_a_connected_waba_refuses_rather_than_guessing(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $this->expectException(RuntimeException::class);

        WorkspaceContext::for(
            $workspace->id,
            fn () => app(WhatsappFlowMetaSyncService::class)->listImportableFlows($workspace->id)
        );
    }

    #[Test]
    public function the_http_endpoints_list_and_import_end_to_end(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'waba-http-'.$workspace->id,
            'credentials' => ['system_user_token' => 'meta-test-token'],
            'status' => 'active',
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-http-'.$workspace->id]);
        $remote = new WhatsappFlow(['name' => 'x', 'screens' => $this->screens()]);
        $metaJson = app(WhatsappFlowJsonCompiler::class)->compile($remote);

        Http::fake([
            "https://graph.facebook.com/v20.0/{$waba->waba_id}/flows*" => Http::response(['data' => [
                ['id' => 'http-1', 'name' => 'Via HTTP', 'status' => 'DRAFT', 'categories' => ['OTHER'], 'validation_errors' => []],
            ]]),
            'https://graph.facebook.com/v20.0/http-1?*' => Http::response(['id' => 'http-1', 'name' => 'Via HTTP', 'status' => 'DRAFT', 'categories' => ['OTHER']]),
            'https://graph.facebook.com/v20.0/http-1/assets' => Http::response([
                'data' => [['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => 'https://assets.test/http-1.json']],
            ]),
            'https://assets.test/http-1.json' => Http::response($metaJson),
        ]);

        $this->actingAs($user)
            ->getJson(route('client.flows.import.picker'))
            ->assertOk()
            ->assertJson(['flows' => [['meta_flow_id' => 'http-1', 'name' => 'Via HTTP']]]);

        $this->actingAs($user)
            ->post(route('client.flows.import.store'), ['meta_flow_ids' => ['http-1']])
            ->assertRedirect()
            ->assertSessionHas('success', '1 Flow imported.');

        $imported = WorkspaceContext::for($workspace->id, fn () => WhatsappFlow::query()->where('meta_flow_id', 'http-1')->first());
        $this->assertNotNull($imported);
        $this->assertSame($workspace->id, $imported->workspace_id);
    }
}
