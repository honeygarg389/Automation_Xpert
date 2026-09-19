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
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 3 — the unified "Sync from Meta" action's single-call summary:
 * "{updated} updated, {imported} imported, {errors} errors". One call does
 * both halves (refresh already-linked Flows, auto-import unmatched ones), so
 * this exercises a genuine mix of all three outcomes in ONE
 * syncAllFromMeta()/syncFromMeta() call — not three separate assertions each
 * against a trivial single-flow fixture.
 */
class WhatsappFlowSyncFromMetaTest extends TestCase
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

    private function screens(string $label = 'Name'): array
    {
        return [[
            'id' => 'contact', 'title' => 'Contact', 'fields' => [[
                'id' => 'name', 'type' => 'text', 'label' => $label, 'name' => 'name',
                'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
            ]],
        ]];
    }

    #[Test]
    public function the_summary_counts_are_accurate_for_a_mix_of_updated_imported_and_failed_flows_in_one_call(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-sfm', 'status' => 'active',
            'credentials' => ['system_user_token' => 'token'],
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-sfm']);

        // One already-linked Flow whose content differs from Meta's -> 'updated'.
        WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Linked, refreshes', 'category' => 'SURVEY', 'status' => 'draft',
            'screens' => $this->screens('Local value'), 'submit_settings' => ['button_text' => 'Submit'],
            'meta_flow_id' => 'meta-linked-ok',
        ]);
        // One already-linked Flow whose Meta-side content fetch fails -> 'errors'.
        WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Linked, fails', 'category' => 'SURVEY', 'status' => 'draft',
            'screens' => $this->screens(), 'submit_settings' => [],
            'meta_flow_id' => 'meta-linked-fail',
        ]);

        $remote = new WhatsappFlow(['name' => 'Remote', 'screens' => $this->screens('Meta value'), 'submit_settings' => ['button_text' => 'Finish']]);
        $remoteJson = app(WhatsappFlowJsonCompiler::class)->compile($remote);

        Http::fake([
            // The picker's candidate list: two Meta Flows with no local record — one importable cleanly, one whose detail fetch fails.
            'https://graph.facebook.com/v20.0/waba-sfm/flows*' => Http::response(['data' => [
                ['id' => 'meta-import-ok', 'name' => 'New Flow OK', 'status' => 'DRAFT', 'categories' => ['OTHER'], 'validation_errors' => []],
                ['id' => 'meta-import-fail', 'name' => 'New Flow Fail', 'status' => 'DRAFT', 'categories' => ['OTHER'], 'validation_errors' => []],
            ]]),
            // Already-linked flow #1 refreshes successfully.
            'https://graph.facebook.com/v20.0/meta-linked-ok/assets' => Http::response(['data' => [
                ['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => 'https://assets.test/meta-linked-ok.json'],
            ]]),
            'https://assets.test/meta-linked-ok.json' => Http::response($remoteJson),
            // Already-linked flow #2 fails to refresh.
            'https://graph.facebook.com/v20.0/meta-linked-fail/assets' => Http::response(['error' => ['message' => 'Temporary failure']], 500),
            // Import candidate #1 succeeds (getFlow ok); its own subsequent
            // content pull failing is irrelevant to whether the IMPORT itself counts as successful.
            'https://graph.facebook.com/v20.0/meta-import-ok?*' => Http::response(['id' => 'meta-import-ok', 'name' => 'New Flow OK', 'status' => 'DRAFT', 'categories' => ['OTHER']]),
            'https://graph.facebook.com/v20.0/meta-import-ok/assets' => Http::response(['error' => ['message' => 'no assets']], 404),
            // Import candidate #2 fails outright (getFlow fails).
            'https://graph.facebook.com/v20.0/meta-import-fail?*' => Http::response(['error' => ['message' => 'Not found']], 404),
        ]);

        $response = $this->actingAs($user)->post(route('client.flows.sync-from-meta'));

        $response->assertRedirect()->assertSessionHas('error', '1 updated, 1 imported, 2 errors.');

        // 1 already-linked flow actually got its content updated.
        $this->assertSame('Meta value', WhatsappFlow::where('meta_flow_id', 'meta-linked-ok')->firstOrFail()->screens[0]['fields'][0]['label']);
        // The one clean candidate really was imported as a new local row.
        $this->assertDatabaseHas('whatsapp_flows', ['meta_flow_id' => 'meta-import-ok', 'workspace_id' => $workspace->id]);
        // The failed candidate never created a row at all.
        $this->assertDatabaseMissing('whatsapp_flows', ['meta_flow_id' => 'meta-import-fail']);
    }

    #[Test]
    public function a_fully_clean_sync_reports_zero_errors_and_flashes_success(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-clean', 'status' => 'active',
            'credentials' => ['system_user_token' => 'token'],
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-clean']);
        Http::fake(['https://graph.facebook.com/v20.0/waba-clean/flows*' => Http::response(['data' => []])]);

        $this->actingAs($user)->post(route('client.flows.sync-from-meta'))
            ->assertRedirect()
            ->assertSessionHas('success', '0 updated, 0 imported, 0 errors.');
    }

    #[Test]
    public function the_service_method_is_directly_correct_for_the_no_waba_case(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $summary = app(WhatsappFlowMetaSyncService::class)->syncAllFromMeta($workspace->id);

        $this->assertSame(['updated' => 0, 'imported' => 0, 'errors' => 0], $summary);
    }
}
