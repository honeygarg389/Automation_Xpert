<?php

namespace Tests\Feature\Flows;

use App\Models\Client;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Plan;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappFlowCrudTest extends TestCase
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

    private function grantFlowsToClient(Client $client): void
    {
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
    }

    #[Test]
    public function model_is_workspace_scoped_and_the_coverage_guard_needs_no_pending_entry(): void
    {
        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(WhatsappFlow::class));
        $this->assertTrue(class_exists(WhatsappFlow::class));
    }

    #[Test]
    public function a_flow_is_created_in_the_active_workspace_and_screens_round_trip(): void
    {
        ['user' => $user, 'client' => $client, 'other' => $workspace] = $this->createTwoWorkspaceUser();
        $this->grantFlowsToClient($client);
        $screens = $this->screens();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('client.flows.store'), [
                'name' => 'Lead capture', 'description' => 'A simple form', 'category' => 'LEAD_GENERATION',
                'status' => 'draft', 'screens' => $screens,
                'submit_settings' => ['button_text' => 'Send', 'success_message' => 'Thanks'],
            ])->assertRedirect();

        $flow = WhatsappFlow::withoutWorkspaceScope('reason: test assertion')->where('name', 'Lead capture')->firstOrFail();
        $this->assertSame($workspace->id, $flow->workspace_id);
        $this->assertEquals($screens, $flow->screens, 'The persisted JSON must retain every screen and field value; JSON object-key order is not semantically meaningful.');
        $this->assertSame('Send', $flow->submit_settings['button_text']);
    }

    #[Test]
    public function category_is_validated_against_the_meta_category_constant(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);

        $this->actingAs($user)->post(route('client.flows.store'), [
            'name' => 'Bad category', 'category' => 'MARKETING', 'status' => 'draft',
        ])->assertSessionHasErrors('category');

        $this->assertDatabaseMissing('whatsapp_flows', ['name' => 'Bad category']);
    }

    #[Test]
    public function update_round_trips_screens_and_another_workspace_cannot_update_or_delete(): void
    {
        ['user' => $owner, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        ['user' => $otherUser, 'client' => $otherClient] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $this->grantFlowsToClient($otherClient);
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Original', 'category' => 'SURVEY', 'status' => 'draft',
            'screens' => $this->screens(), 'submit_settings' => ['button_text' => 'Submit'],
        ]);
        $replacement = $this->screens('Full name');

        $this->actingAs($owner)->put(route('client.flows.update', $flow->uuid), [
            'name' => 'Updated', 'category' => 'SURVEY', 'status' => 'published', 'screens' => $replacement,
            'submit_settings' => ['button_text' => 'Finish', 'success_message' => 'Done'],
        ])->assertRedirect();
        $this->assertEquals($replacement, $flow->fresh()->screens, 'The update must round-trip the complete shared screens contract.');
        $this->assertSame('published', $flow->fresh()->status);

        $this->actingAs($otherUser)->put(route('client.flows.update', $flow->uuid), [
            'name' => 'Stolen', 'category' => 'SURVEY', 'status' => 'draft', 'screens' => $replacement,
        ])->assertNotFound();
        $this->actingAs($otherUser)->delete(route('client.flows.destroy', $flow->uuid))->assertNotFound();
        $this->assertNotSoftDeleted('whatsapp_flows', ['id' => $flow->id]);
    }

    /**
     * Positive control for the test above: a 404 on another workspace's
     * delete attempt proves nothing about the route actually working — it is
     * equally consistent with the endpoint refusing everyone. This proves
     * the SAME route, same verb, same user type succeeds for the legitimate
     * owner, per this suite's own working-agreement rule.
     */
    #[Test]
    public function the_owning_workspace_can_delete_its_own_flow(): void
    {
        ['user' => $owner, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlowsToClient($client);
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Disposable', 'category' => 'SURVEY', 'status' => 'draft',
            'screens' => $this->screens(), 'submit_settings' => ['button_text' => 'Submit'],
        ]);

        $this->actingAs($owner)->delete(route('client.flows.destroy', $flow->uuid))->assertRedirect(route('client.flows.index'));

        $this->assertSoftDeleted('whatsapp_flows', ['id' => $flow->id]);
    }
}
