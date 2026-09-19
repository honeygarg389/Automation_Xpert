<?php

namespace Tests\Feature\Flows;

use App\Models\Plan;
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

/**
 * Section G — the safety-critical Delete/Deprecate state-machine fix. Meta's
 * error 139004 ("Can't delete published Flow... deprecate instead") means
 * exactly one of three Graph calls (or none) is correct depending on the
 * Flow's real state, and a failed Meta-side call must never be masked by a
 * local soft-delete that leaves Meta and the local row disagreeing.
 */
class WhatsappFlowRemoveFromMetaTest extends TestCase
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

    private function connectedWorkspace(): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'waba-rm',
            'credentials' => ['system_user_token' => 'meta-test-token'],
            'status' => 'active',
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-rm']);

        return ['workspace' => $workspace, 'waba' => $waba];
    }

    #[Test]
    public function a_never_synced_flow_is_removed_locally_with_no_meta_call_at_all(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Never synced', 'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_DRAFT, 'screens' => $this->screens(), 'submit_settings' => [],
        ]);
        Http::fake();

        $result = app(WhatsappFlowMetaSyncService::class)->removeFromMeta($flow);

        $this->assertTrue($result['success']);
        $this->assertSame('local_only', $result['action']);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_synced_but_still_draft_flow_calls_metas_real_delete_endpoint(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Draft on Meta', 'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_DRAFT, 'meta_flow_id' => 'meta-draft-1',
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT,
            'screens' => $this->screens(), 'submit_settings' => [],
        ]);
        Http::fake(['https://graph.facebook.com/v20.0/meta-draft-1' => Http::response(['success' => true])]);

        $result = app(WhatsappFlowMetaSyncService::class)->removeFromMeta($flow);

        $this->assertTrue($result['success']);
        $this->assertSame('meta_delete', $result['action']);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://graph.facebook.com/v20.0/meta-draft-1' && $request->method() === 'DELETE');
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_published_flow_is_never_deleted_but_deprecated_instead(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Live on Meta', 'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_PUBLISHED, 'meta_flow_id' => 'meta-published-1',
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_PUBLISHED,
            'screens' => $this->screens(), 'submit_settings' => [],
        ]);
        Http::fake(['https://graph.facebook.com/v20.0/meta-published-1/deprecate' => Http::response(['success' => true])]);

        $result = app(WhatsappFlowMetaSyncService::class)->removeFromMeta($flow);

        $this->assertTrue($result['success']);
        $this->assertSame('meta_deprecate', $result['action']);
        $this->assertStringContainsString('cannot be undone', $result['message'], 'The irreversibility of deprecate must be reflected in the message surfaced to the UI.');
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://graph.facebook.com/v20.0/meta-published-1/deprecate' && $request->method() === 'POST');
        // Never a DELETE call for a published Flow — that is exactly the call Meta's error 139004 rejects.
        Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'DELETE');
        $flow = $flow->fresh();
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_DEPRECATED, $flow->meta_sync_status);
        // Deprecating is NOT a delete — the row and its submission history must stay.
        $this->assertNotSoftDeleted('whatsapp_flows', ['id' => $flow->id]);
    }

    #[Test]
    public function a_failed_meta_delete_call_leaves_the_local_flow_completely_untouched(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Delete fails', 'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_DRAFT, 'meta_flow_id' => 'meta-delete-fail',
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT,
            'screens' => $this->screens(), 'submit_settings' => [],
        ]);
        Http::fake(['https://graph.facebook.com/v20.0/meta-delete-fail' => Http::response(['error' => ['message' => 'Something went wrong', 'code' => 1]], 500)]);

        $result = app(WhatsappFlowMetaSyncService::class)->removeFromMeta($flow);

        $this->assertFalse($result['success']);
        $this->assertSame('meta_delete', $result['action']);
        $this->assertNotSoftDeleted('whatsapp_flows', ['id' => $flow->id]);
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT, $flow->fresh()->meta_sync_status, 'A failed Meta delete must not silently flip local state either.');
    }

    #[Test]
    public function a_failed_meta_deprecate_call_leaves_the_local_flow_still_published_and_not_soft_deleted(): void
    {
        ['workspace' => $workspace] = $this->connectedWorkspace();
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Deprecate fails', 'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_PUBLISHED, 'meta_flow_id' => 'meta-deprecate-fail',
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_PUBLISHED,
            'screens' => $this->screens(), 'submit_settings' => [],
        ]);
        Http::fake(['https://graph.facebook.com/v20.0/meta-deprecate-fail/deprecate' => Http::response(['error' => ['message' => 'Something went wrong', 'code' => 1]], 500)]);

        $result = app(WhatsappFlowMetaSyncService::class)->removeFromMeta($flow);

        $this->assertFalse($result['success']);
        $this->assertSame('meta_deprecate', $result['action']);
        $flow = $flow->fresh();
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_PUBLISHED, $flow->meta_sync_status, 'A failed deprecate must leave the Flow exactly as published as it was before the attempt — no silent status flip.');
        $this->assertNotSoftDeleted('whatsapp_flows', ['id' => $flow->id]);
    }

    #[Test]
    public function the_destroy_route_branches_correctly_end_to_end_for_a_published_flow(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-route', 'credentials' => ['system_user_token' => 'tok'], 'status' => 'active',
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-route']);
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Route published', 'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_PUBLISHED, 'meta_flow_id' => 'meta-route-1',
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_PUBLISHED,
            'screens' => $this->screens(), 'submit_settings' => [],
        ]);
        Http::fake(['https://graph.facebook.com/v20.0/meta-route-1/deprecate' => Http::response(['success' => true])]);

        // The Published branch redirects BACK (stays on the page — there is
        // nothing to navigate away from, the row still exists), unlike the
        // local_only/meta_delete branches which redirect to the index because
        // the row is actually gone.
        $this->actingAs($user)->delete(route('client.flows.destroy', $flow->uuid))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotSoftDeleted('whatsapp_flows', ['id' => $flow->id]);
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_DEPRECATED, $flow->fresh()->meta_sync_status);
    }
}
