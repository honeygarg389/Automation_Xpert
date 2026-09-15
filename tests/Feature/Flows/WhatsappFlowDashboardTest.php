<?php

namespace Tests\Feature\Flows;

use App\Models\Client;
use App\Models\Plan;
use App\Modules\Flows\Models\FormSubmission;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WhatsappFlowJsonCompiler;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappFlowDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function grantFlows(Client $client): void
    {
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
    }

    /**
     * @return list<array{id:string,title:string,fields:list<array{id:string,type:string,label:string,name:string,required:bool,helper_text:null,options:list<never>,step:int,order:int}>}>
     */
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
    public function meta_pull_overwrites_saved_local_screens_and_reports_its_summary(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlows($client);
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-pull', 'status' => 'active',
            'credentials' => ['system_user_token' => 'token'],
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-pull']);
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Pull me', 'category' => 'SURVEY', 'status' => 'draft',
            'screens' => $this->screens('Local value'), 'submit_settings' => ['button_text' => 'Submit', 'success_message' => 'Local'],
            'meta_flow_id' => 'meta-pull',
        ]);
        $remote = new WhatsappFlow([
            'name' => 'Remote', 'screens' => $this->screens('Meta value'),
            'submit_settings' => ['button_text' => 'Finish', 'success_message' => 'From Meta'],
        ]);
        $metaJson = app(WhatsappFlowJsonCompiler::class)->compile($remote);

        Http::fake([
            'https://graph.facebook.com/v20.0/meta-pull/assets' => Http::response([
                'data' => [['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => 'https://assets.test/meta-pull.json']],
            ]),
            'https://assets.test/meta-pull.json' => Http::response($metaJson),
        ]);

        $this->actingAs($user)->post(route('client.flows.pull-meta'))
            ->assertRedirect()
            ->assertSessionHas('success', 'Meta Flow pull complete: 1 updated, 0 unchanged, 0 failed.');

        $this->assertSame('Meta value', $flow->fresh()->screens[0]['fields'][0]['label']);
        $this->assertSame('Finish', $flow->fresh()->submit_settings['button_text']);
    }

    #[Test]
    public function submissions_view_is_workspace_scoped_and_exposes_linked_contact_and_answers(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlows($client);
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Lead form', 'category' => 'LEAD_GENERATION', 'status' => 'draft',
            'screens' => $this->screens(), 'submit_settings' => [],
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id, 'phone_e164' => '+15555550101', 'first_name' => 'Jamie', 'source' => 'manual',
        ]);
        FormSubmission::create([
            'workspace_id' => $workspace->id, 'whatsapp_flow_id' => $flow->id, 'contact_id' => $contact->id,
            'source' => FormSubmission::SOURCE_WHATSAPP_FLOW, 'answers' => ['company' => 'AutomationXpert'],
        ]);

        $this->actingAs($user)->get(route('client.flows.submissions', $flow->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('client/Flows/Submissions')
                ->has('submissions.data', 1)
                ->where('submissions.data.0.contact.name', 'Jamie')
                ->where('submissions.data.0.answer_preview', 'company: AutomationXpert'));

        ['user' => $otherUser, 'client' => $otherClient] = $this->createWorkspaceContext();
        $this->grantFlows($otherClient);
        $this->actingAs($otherUser)->get(route('client.flows.submissions', $flow->uuid))->assertNotFound();
    }

    #[Test]
    public function an_owner_can_delete_a_flow_from_the_existing_destroy_endpoint(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->grantFlows($client);
        $flow = WhatsappFlow::create([
            'workspace_id' => $workspace->id, 'name' => 'Delete me', 'category' => 'OTHER', 'status' => 'draft',
            'screens' => $this->screens(), 'submit_settings' => [],
        ]);

        $this->actingAs($user)->delete(route('client.flows.destroy', $flow->uuid))
            ->assertRedirect(route('client.flows.index'));

        $this->assertSoftDeleted('whatsapp_flows', ['id' => $flow->id]);
    }
}
