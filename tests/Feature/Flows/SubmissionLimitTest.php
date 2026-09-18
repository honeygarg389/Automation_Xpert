<?php

namespace Tests\Feature\Flows;

use App\Models\Workspace;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationRunLog;
use App\Modules\Flows\Events\WhatsappFlowSubmitted;
use App\Modules\Flows\Models\FormSubmission;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Shared\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 2. WhatsappFlow::hasReachedSubmissionLimit() is ONE method counting
 * against the shared FormSubmission ledger (no `source` filter), called from
 * two independent enforcement points: WhatsappFlowSubmissionListener (the
 * WhatsApp inbound path) and PublicFlowFormController (the web-form path).
 * These tests exercise both call sites against one shared count, not two
 * independently-tested limits — that IS the thing under test.
 */
class SubmissionLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Builds a flow reachable from BOTH submission paths: a meta_flow_id +
     * correlated AutomationRun/AutomationRunLog for the WhatsApp path (the
     * exact correlation chain WhatsappFlowSubmissionListener::flowForRun()
     * requires — see WhatsappFlowSubmissionTest for the same shape), and
     * web_form_enabled + a public_slug for the web-form path.
     *
     * @return array{0: Workspace, 1: WhatsappFlow, 2: AutomationRun, 3: Contact}
     */
    private function flowFixture(?int $maxSubmissions, ?string $limitMessage = null): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        return WorkspaceContext::for($workspace->id, function () use ($workspace, $maxSubmissions, $limitMessage): array {
            $flow = WhatsappFlow::create(array_merge([
                'workspace_id' => $workspace->id,
                'name' => 'Limited flow',
                'category' => 'OTHER',
                'status' => WhatsappFlow::STATUS_DRAFT,
                'screens' => [[
                    'id' => 'contact', 'title' => 'Contact', 'fields' => [[
                        'id' => 'name', 'type' => 'text', 'label' => 'Name', 'name' => 'name',
                        'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
                    ]],
                ]],
                'meta_flow_id' => 'meta-limit-flow',
                'max_submissions' => $maxSubmissions,
                'web_form_enabled' => true,
                'public_slug' => bin2hex(random_bytes(16)),
                'recaptcha_enabled' => false,
            ], $limitMessage !== null ? ['limit_error_message' => $limitMessage] : []));

            $automation = Automation::create([
                'workspace_id' => $workspace->id,
                'name' => 'Send limited form',
                'status' => 'active',
                'nodes' => [['id' => 'form-node', 'type' => 'whatsapp_form', 'data' => ['flow_id' => 'meta-limit-flow']]],
                'edges' => [],
            ]);
            $contact = Contact::create([
                'workspace_id' => $workspace->id,
                'phone_e164' => '+15555550199',
                'first_name' => 'Limit tester',
                'source' => 'manual',
            ]);
            $run = AutomationRun::create([
                'automation_id' => $automation->id,
                'contact_id' => $contact->id,
                'status' => 'completed',
                'context' => [],
                'started_at' => now(),
                'completed_at' => now(),
            ]);
            AutomationRunLog::create(['run_id' => $run->id, 'node_id' => 'form-node', 'node_type' => 'whatsapp_form', 'result' => 'ok']);

            return [$workspace, $flow, $run, $contact];
        });
    }

    private function submissionCount(Workspace $workspace, WhatsappFlow $flow): int
    {
        return WorkspaceContext::for(
            $workspace->id,
            fn (): int => FormSubmission::where('whatsapp_flow_id', $flow->id)->count()
        );
    }

    #[Test]
    public function the_whatsapp_path_rejects_once_the_limit_is_reached_and_does_not_persist_the_overflow(): void
    {
        [$workspace, $flow, $run, $contact] = $this->flowFixture(maxSubmissions: 1);

        WhatsappFlowSubmitted::dispatch($workspace->id, $contact->id, 'flow_'.$run->id, ['name' => 'First, within limit']);
        WhatsappFlowSubmitted::dispatch($workspace->id, $contact->id, 'flow_'.$run->id, ['name' => 'Second, over the limit']);

        $this->assertSame(1, $this->submissionCount($workspace, $flow), 'The second WhatsApp submission must not be persisted once the limit is reached.');

        $retained = WorkspaceContext::for($workspace->id, fn () => FormSubmission::where('whatsapp_flow_id', $flow->id)->first());
        $this->assertSame(['name' => 'First, within limit'], $retained->answers, 'The FIRST (legitimately accepted) submission must be the one retained — proves this is a real limit check, not a broken listener.');
    }

    #[Test]
    public function the_web_form_path_rejects_with_the_configured_limit_message_and_does_not_persist(): void
    {
        [$workspace, $flow] = $this->flowFixture(maxSubmissions: 1, limitMessage: 'No more room, sorry.');
        WorkspaceContext::for($workspace->id, fn () => FormSubmission::create([
            'workspace_id' => $workspace->id,
            'whatsapp_flow_id' => $flow->id,
            'source' => FormSubmission::SOURCE_WEB_FORM,
            'answers' => ['name' => 'Already at the limit'],
        ]));

        $response = $this->from(route('public.flows.form.show', $flow->public_slug))
            ->post(route('public.flows.form.submit', $flow->public_slug), ['name' => 'Blocked visitor']);

        $response->assertRedirect(route('public.flows.form.show', $flow->public_slug));
        $response->assertSessionHasErrors(['limit' => 'No more room, sorry.']);
        $this->assertSame(1, $this->submissionCount($workspace, $flow), 'The over-limit web-form submission must not be persisted.');
    }

    #[Test]
    public function a_null_max_submissions_never_blocks_either_path(): void
    {
        [$workspace, $flow, $run, $contact] = $this->flowFixture(maxSubmissions: null);

        WhatsappFlowSubmitted::dispatch($workspace->id, $contact->id, 'flow_'.$run->id, ['name' => 'Unlimited via WhatsApp']);
        $this->post(route('public.flows.form.submit', $flow->public_slug), ['name' => 'Unlimited via web form'])
            ->assertRedirect(route('public.flows.form.show', $flow->public_slug))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(2, $this->submissionCount($workspace, $flow));
    }

    #[Test]
    public function the_limit_counts_across_both_sources_together_not_independently(): void
    {
        [$workspace, $flow, $run, $contact] = $this->flowFixture(maxSubmissions: 5);

        // 3 via WhatsApp.
        WhatsappFlowSubmitted::dispatch($workspace->id, $contact->id, 'flow_'.$run->id, ['name' => 'wa-1']);
        WhatsappFlowSubmitted::dispatch($workspace->id, $contact->id, 'flow_'.$run->id, ['name' => 'wa-2']);
        WhatsappFlowSubmitted::dispatch($workspace->id, $contact->id, 'flow_'.$run->id, ['name' => 'wa-3']);
        // 2 via the web form.
        $this->post(route('public.flows.form.submit', $flow->public_slug), ['name' => 'web-1'])->assertSessionDoesntHaveErrors();
        $this->post(route('public.flows.form.submit', $flow->public_slug), ['name' => 'web-2'])->assertSessionDoesntHaveErrors();

        $this->assertSame(5, $this->submissionCount($workspace, $flow), 'Sanity check: all 5 accepted so far.');

        // The 6th, regardless of path, must be blocked — proving ONE shared count, not two per-source limits.
        WhatsappFlowSubmitted::dispatch($workspace->id, $contact->id, 'flow_'.$run->id, ['name' => 'wa-4, should be blocked']);
        $this->assertSame(5, $this->submissionCount($workspace, $flow), 'A 4th WhatsApp submission must be blocked once the SHARED count (already 5) is at the limit.');

        $this->post(route('public.flows.form.submit', $flow->public_slug), ['name' => 'web-3, should be blocked'])
            ->assertSessionHasErrors('limit');
        $this->assertSame(5, $this->submissionCount($workspace, $flow), 'A 3rd web-form submission must also be blocked by the same shared count.');
    }
}
