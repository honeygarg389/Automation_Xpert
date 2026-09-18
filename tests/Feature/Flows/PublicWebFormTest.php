<?php

namespace Tests\Feature\Flows;

use App\Models\Workspace;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Flows\Models\FormSubmission;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Shared\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicWebFormTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: WhatsappFlow, 1: Workspace}
     */
    private function publicFlow(array $overrides = []): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $flow = WorkspaceContext::for($workspace->id, fn (): WhatsappFlow => WhatsappFlow::create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => 'Website lead form',
            'description' => 'Share your details.',
            'category' => 'LEAD_GENERATION',
            'status' => WhatsappFlow::STATUS_DRAFT,
            'web_form_enabled' => true,
            'public_slug' => bin2hex(random_bytes(16)),
            'recaptcha_enabled' => false,
            'submit_settings' => ['button_text' => 'Send enquiry', 'success_message' => 'Thank you for getting in touch.'],
            'screens' => [[
                'id' => 'contact', 'title' => 'Contact details', 'fields' => [
                    ['id' => 'first_name', 'type' => 'text', 'label' => 'First name', 'name' => 'first_name', 'required' => true, 'helper_text' => 'As shown on your ID', 'options' => [], 'step' => 1, 'order' => 1],
                    ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 2],
                    ['id' => 'phone', 'type' => 'phone', 'label' => 'Phone', 'name' => 'phone', 'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 3],
                    ['id' => 'interests', 'type' => 'checkbox', 'label' => 'Interests', 'name' => 'interests', 'required' => false, 'helper_text' => null, 'options' => [['id' => 'news', 'title' => 'News']], 'step' => 1, 'order' => 4],
                ],
            ]],
        ], $overrides)));

        return [$flow, $workspace];
    }

    #[Test]
    public function an_enabled_public_slug_renders_a_standard_csrf_protected_html_form(): void
    {
        [$flow] = $this->publicFlow();

        $this->get(route('public.flows.form.show', $flow->public_slug))
            ->assertOk()
            ->assertSee('Website lead form')
            ->assertSee('name="_token"', false)
            ->assertSee('type="email"', false)
            ->assertSee('name="interests[]"', false)
            ->assertSee('Send enquiry');
    }

    #[Test]
    public function disabled_unknown_and_malformed_public_slugs_are_indistinguishable_not_found_responses(): void
    {
        [$flow] = $this->publicFlow(['web_form_enabled' => false]);

        $disabled = $this->get('/f/'.$flow->public_slug)->assertNotFound();
        $unknown = $this->get('/f/'.str_repeat('f', 32))->assertNotFound();
        $malformed = $this->get('/f/not-a-public-slug')->assertNotFound();

        $this->assertSame($disabled->getContent(), $unknown->getContent());
        $this->assertSame($unknown->getContent(), $malformed->getContent());
    }

    #[Test]
    public function recaptcha_failure_rejects_a_public_submission_without_persisting_it(): void
    {
        [$flow] = $this->publicFlow(['recaptcha_enabled' => true]);
        config(['services.recaptcha.site_key' => 'site-key', 'services.recaptcha.secret_key' => 'secret-key']);
        Http::fake(['https://www.google.com/recaptcha/api/siteverify' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $this->post(route('public.flows.form.submit', $flow->public_slug), $this->answers(['g-recaptcha-response' => 'bad-token']))
            ->assertRedirect()
            ->assertSessionHasErrors('recaptcha');

        $this->assertDatabaseCount('form_submissions', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
            && $request['secret'] === 'secret-key'
            && $request['response'] === 'bad-token');
    }

    #[Test]
    public function a_web_submission_reuses_fill_missing_contact_enrichment_and_the_form_trigger_path(): void
    {
        [$flow, $workspace] = $this->publicFlow();
        $contact = WorkspaceContext::for($workspace->id, fn (): Contact => Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+15555550101',
            'first_name' => 'Established',
            'email' => null,
            'source' => 'manual',
        ]));
        WorkspaceContext::for($workspace->id, fn (): Automation => Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Website follow-up',
            'status' => 'active',
            'trigger_type' => 'form.submitted',
            'nodes' => [],
            'edges' => [],
        ]));

        $this->post(route('public.flows.form.submit', $flow->public_slug), $this->answers(['first_name' => 'Overwrite attempt']))
            ->assertRedirect(route('public.flows.form.show', $flow->public_slug))
            ->assertSessionHas('web_form_success', 'Thank you for getting in touch.');

        $submission = WorkspaceContext::for($workspace->id, fn (): FormSubmission => FormSubmission::firstOrFail());
        $this->assertSame(FormSubmission::SOURCE_WEB_FORM, $submission->source);
        $this->assertSame($flow->id, $submission->whatsapp_flow_id);
        $this->assertSame($contact->id, $submission->contact_id);
        $this->assertSame('Established', $contact->fresh()->first_name, 'A web response must not overwrite real contact data.');
        $this->assertSame('lead@example.test', $contact->fresh()->email, 'A missing contact value may be enriched.');

        $run = WorkspaceContext::for($workspace->id, fn (): AutomationRun => AutomationRun::firstOrFail());
        $this->assertSame('Website lead form', $run->context['flow_name']);
        $this->assertSame('web_form', $run->context['source']);
        $this->assertSame('lead@example.test', $run->context['email']);
    }

    #[Test]
    public function submissions_are_scoped_to_the_flow_workspace_and_post_limits_are_per_ip(): void
    {
        [$flow, $workspace] = $this->publicFlow();
        [$otherFlow, $otherWorkspace] = $this->publicFlow();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->post(route('public.flows.form.submit', $flow->public_slug), $this->answers(['email' => "lead{$attempt}@example.test"]))
                ->assertRedirect();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('public.flows.form.submit', $flow->public_slug), $this->answers(['email' => 'blocked@example.test']))
            ->assertTooManyRequests();

        WorkspaceContext::for($workspace->id, function () use ($flow): void {
            $this->assertSame(10, FormSubmission::where('whatsapp_flow_id', $flow->id)->count());
        });
        WorkspaceContext::for($otherWorkspace->id, function () use ($otherFlow): void {
            $this->assertSame(0, FormSubmission::where('whatsapp_flow_id', $otherFlow->id)->count());
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function answers(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jamie',
            'email' => 'lead@example.test',
            'phone' => '+15555550101',
            'interests' => ['news'],
        ], $overrides);
    }
}
