<?php

namespace Tests\Feature\Flows;

use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Modules\Flows\Models\WhatsappFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappFlowWebFormSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{flow: WhatsappFlow, user: User, client: Client} */
    private function fixture(): array
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Website form',
            'category' => 'CONTACT_US',
            'status' => WhatsappFlow::STATUS_DRAFT,
            'screens' => [['id' => 'contact', 'title' => 'Contact', 'fields' => [[
                'id' => 'email', 'type' => 'email', 'label' => 'Email', 'name' => 'email',
                'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
            ]]]],
            'submit_settings' => [],
        ]);

        return compact('flow', 'user', 'client');
    }

    #[Test]
    public function enabling_generates_an_opaque_slug_and_reenabling_preserves_it_until_explicit_regeneration(): void
    {
        ['flow' => $flow, 'user' => $user] = $this->fixture();

        $this->actingAs($user)->post(route('client.flows.web-form.enable', $flow->uuid))
            ->assertRedirect()
            ->assertSessionHas('success');
        $slug = $flow->fresh()->public_slug;
        $this->assertTrue($flow->fresh()->web_form_enabled);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $slug);

        $this->actingAs($user)->post(route('client.flows.web-form.disable', $flow->uuid))->assertRedirect();
        $this->assertFalse($flow->fresh()->web_form_enabled);
        $this->assertSame($slug, $flow->fresh()->public_slug);

        $this->actingAs($user)->post(route('client.flows.web-form.enable', $flow->uuid))->assertRedirect();
        $this->assertSame($slug, $flow->fresh()->public_slug);

        $this->actingAs($user)->post(route('client.flows.web-form.regenerate', $flow->uuid))->assertRedirect();
        $this->assertNotSame($slug, $flow->fresh()->public_slug);
    }

    #[Test]
    public function an_owner_can_toggle_recaptcha_and_cannot_change_another_workspaces_flow(): void
    {
        ['flow' => $flow, 'user' => $user] = $this->fixture();

        $this->actingAs($user)->put(route('client.flows.web-form.recaptcha', $flow->uuid), ['recaptcha_enabled' => false])
            ->assertRedirect();
        $this->assertFalse($flow->fresh()->recaptcha_enabled);

        ['user' => $other] = $this->createWorkspaceContext();
        $this->attachPlanToClient($other->client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $this->actingAs($other)->post(route('client.flows.web-form.enable', $flow->uuid))->assertNotFound();
    }
}
