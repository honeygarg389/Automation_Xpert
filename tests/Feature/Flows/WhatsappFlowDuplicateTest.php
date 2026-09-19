<?php

namespace Tests\Feature\Flows;

use App\Models\Plan;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Section H — Duplicate: the only path to modifying a Published Flow
 * (Section F makes a Published Builder read-only), and generically available
 * on any Flow. A duplicate must be a genuinely independent local Draft — it
 * must NOT carry over meta_flow_id/meta_sync_status, so editing it can never
 * touch the original's Meta-side Flow.
 */
class WhatsappFlowDuplicateTest extends TestCase
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
    public function duplicating_a_published_flow_creates_an_independent_unsynced_draft_copy(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $original = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Lead capture', 'description' => 'Original description',
            'category' => 'LEAD_GENERATION', 'status' => WhatsappFlow::STATUS_PUBLISHED,
            'meta_flow_id' => 'meta-original-1', 'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_PUBLISHED,
            'screens' => $this->screens(), 'submit_settings' => ['button_text' => 'Submit', 'success_message' => 'Thanks'],
        ]);

        $response = $this->actingAs($user)->post(route('client.flows.duplicate', $original->uuid));

        $copy = WhatsappFlow::withoutWorkspaceScope('reason: test assertion')->where('name', 'Lead capture (Copy)')->firstOrFail();
        $response->assertRedirect(route('client.flows.edit', $copy));

        $this->assertNotSame($original->id, $copy->id);
        $this->assertSame($workspace->id, $copy->workspace_id);
        $this->assertSame('Lead capture (Copy)', $copy->name);
        $this->assertSame('Original description', $copy->description);
        $this->assertSame('LEAD_GENERATION', $copy->category);
        $this->assertEquals($original->screens, $copy->screens);
        $this->assertEquals($original->submit_settings, $copy->submit_settings);

        // The whole point of Section H: the copy is NOT connected to Meta at all.
        $this->assertSame(WhatsappFlow::STATUS_DRAFT, $copy->status);
        $this->assertNull($copy->meta_flow_id);
        $this->assertNull($copy->meta_sync_status);

        // The original itself must be completely unaffected by having been duplicated.
        $original = $original->fresh();
        $this->assertSame(WhatsappFlow::STATUS_PUBLISHED, $original->status);
        $this->assertSame('meta-original-1', $original->meta_flow_id);
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_PUBLISHED, $original->meta_sync_status);
    }

    #[Test]
    public function editing_the_duplicate_afterwards_never_affects_the_original(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $original = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Survey', 'category' => 'SURVEY',
            'status' => WhatsappFlow::STATUS_DRAFT, 'screens' => $this->screens(),
            'submit_settings' => ['button_text' => 'Submit'],
        ]);
        $this->actingAs($user)->post(route('client.flows.duplicate', $original->uuid));
        $copy = WhatsappFlow::withoutWorkspaceScope('reason: test assertion')->where('name', 'Survey (Copy)')->firstOrFail();

        $this->actingAs($user)->put(route('client.flows.update', $copy->uuid), [
            'name' => 'Survey (Copy) — edited', 'category' => 'SURVEY', 'status' => 'draft',
            'screens' => $this->screens('Full name'),
            'submit_settings' => ['button_text' => 'Go'],
        ])->assertRedirect();

        $this->assertEquals($this->screens('Full name'), $copy->fresh()->screens);
        $this->assertEquals($this->screens(), $original->fresh()->screens, 'Editing the duplicate must never mutate the original\'s own screens.');
        $this->assertSame('Survey', $original->fresh()->name);
    }

    #[Test]
    public function duplicating_across_workspaces_keeps_the_copy_in_the_acting_users_own_workspace(): void
    {
        ['user' => $ownerA, 'workspace' => $workspaceA, 'client' => $clientA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();
        $this->attachPlanToClient($clientA, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspaceA->id, 'name' => 'Isolation check', 'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_DRAFT, 'screens' => $this->screens(),
            'submit_settings' => [],
        ]);

        $this->actingAs($ownerA)->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('client.flows.duplicate', $flow->uuid))
            ->assertRedirect();

        $copy = WhatsappFlow::withoutWorkspaceScope('reason: test assertion')->where('name', 'Isolation check (Copy)')->firstOrFail();
        $this->assertSame($workspaceA->id, $copy->workspace_id);
        $this->assertNotSame($workspaceB->id, $copy->workspace_id);
    }

    /**
     * Task 2 (refinement) — duplicating a PUBLISHED flow with an active Meta
     * connection available must call CloudApiClient::createFlow() with
     * clone_flow_id set to the SOURCE's own meta_flow_id (Meta's
     * Create-Flow-with-clone pattern), and the new copy gets its own,
     * different meta_flow_id from Meta's response plus a Draft-on-Meta
     * sync status — never the source's own meta_flow_id, and never
     * flipped straight to published.
     */
    #[Test]
    public function duplicating_a_published_flow_with_an_active_meta_connection_clones_it_on_meta(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-clone', 'credentials' => ['system_user_token' => 'tok'], 'status' => 'active',
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-clone']);
        $original = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Lead capture', 'category' => 'LEAD_GENERATION',
            'status' => WhatsappFlow::STATUS_PUBLISHED, 'meta_flow_id' => 'meta-original-2',
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_PUBLISHED,
            'screens' => $this->screens(), 'submit_settings' => ['button_text' => 'Submit'],
        ]);
        Http::fake(['https://graph.facebook.com/v20.0/waba-clone/flows' => Http::response(['id' => 'meta-cloned-1'])]);

        $this->actingAs($user)->post(route('client.flows.duplicate', $original->uuid))->assertRedirect();

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://graph.facebook.com/v20.0/waba-clone/flows'
            && $request['clone_flow_id'] === 'meta-original-2');

        $copy = WhatsappFlow::withoutWorkspaceScope('reason: test assertion')->where('name', 'Lead capture (Copy)')->firstOrFail();
        $this->assertSame('meta-cloned-1', $copy->meta_flow_id);
        $this->assertNotSame($original->meta_flow_id, $copy->meta_flow_id);
        $this->assertSame(WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT, $copy->meta_sync_status, 'The clone must start as Draft on Meta\'s side, not published.');
        $this->assertSame(WhatsappFlow::STATUS_DRAFT, $copy->status);
    }

    /**
     * Task 2 (refinement) — a Draft (never-published) source has no
     * meaningful Meta-side identity to clone from, so duplicating it must
     * never attempt any Graph API call at all, even with an active Meta
     * connection sitting right there ready to use.
     */
    #[Test]
    public function duplicating_a_draft_flow_never_attempts_any_meta_call_even_with_an_active_connection(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-draft', 'credentials' => ['system_user_token' => 'tok'], 'status' => 'active',
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-draft']);
        $original = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Draft only', 'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_DRAFT, 'screens' => $this->screens(),
            'submit_settings' => [],
        ]);
        Http::fake();

        $this->actingAs($user)->post(route('client.flows.duplicate', $original->uuid))->assertRedirect();

        Http::assertNothingSent();
        $copy = WhatsappFlow::withoutWorkspaceScope('reason: test assertion')->where('name', 'Draft only (Copy)')->firstOrFail();
        $this->assertNull($copy->meta_flow_id);
        $this->assertNull($copy->meta_sync_status);
    }

    /**
     * A synced-but-still-DRAFT-on-Meta source (never published) is likewise
     * left as a pure local copy — the clone path is gated specifically on
     * "currently PUBLISHED", not merely "has ever synced".
     */
    #[Test]
    public function duplicating_a_synced_but_unpublished_flow_also_never_attempts_a_meta_clone(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-synced-draft', 'credentials' => ['system_user_token' => 'tok'], 'status' => 'active',
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-synced-draft']);
        $original = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Synced draft', 'category' => 'OTHER',
            'status' => WhatsappFlow::STATUS_DRAFT, 'meta_flow_id' => 'meta-synced-draft-1',
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT,
            'screens' => $this->screens(), 'submit_settings' => [],
        ]);
        Http::fake();

        $this->actingAs($user)->post(route('client.flows.duplicate', $original->uuid))->assertRedirect();

        Http::assertNothingSent();
    }
}
