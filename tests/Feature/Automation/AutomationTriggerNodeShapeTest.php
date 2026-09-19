<?php

namespace Tests\Feature\Automation;

use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationRunLog;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fixtures elsewhere in this suite all use the seed-style trigger node
 * (`type: 'trigger'`). The Builder actually persists `type: 'triggerNode'`, and
 * every Builder-created automation failed with "No trigger node." until the
 * engine's two duplicate trigger lookups were unified.
 */
class AutomationTriggerNodeShapeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Node shapes copied from a real Builder-saved automation, not hand-simplified.
     *
     * @return list<array<string, mixed>>
     */
    private function builderNodes(): array
    {
        return [
            ['id' => 'trigger-1', 'type' => 'triggerNode', 'position' => ['x' => 177.5, 'y' => 8], 'data' => ['label' => 'Trigger', 'triggerType' => 'message.received']],
            ['id' => 'add_tag-1789827483028', 'type' => 'add_tag', 'position' => ['x' => 247.625, 'y' => 103], 'data' => ['tag' => 'Builder Tagged', 'label' => null, 'nodeType' => 'add_tag', 'configured' => true]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function seedStyleNodes(): array
    {
        return [
            ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 250, 'y' => 40], 'data' => ['label' => 'Message Received', 'triggerType' => 'message.received']],
            ['id' => 'add_tag-1', 'type' => 'add_tag', 'position' => ['x' => 250, 'y' => 170], 'data' => ['tag' => 'Seed Tagged', 'label' => 'Add Tag', 'nodeType' => 'add_tag', 'configured' => true]],
        ];
    }

    /** @param list<array<string, mixed>> $nodes */
    private function automationWith(array $nodes): Automation
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        return Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Shape test',
            'status' => 'active',
            'trigger_type' => 'message.received',
            'trigger_config' => ['keywords' => ['Hello']],
            'nodes' => $nodes,
            'edges' => [['id' => 'e1', 'source' => $nodes[0]['id'], 'target' => $nodes[1]['id']]],
        ]);
    }

    private function execute(Automation $automation): AutomationRun
    {
        $contact = Contact::factory()->create(['workspace_id' => $automation->workspace_id]);
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
            'context' => ['message_body' => 'Hello', 'message_channel' => 'whatsapp'],
            'started_at' => now(),
        ]);

        $this->runJob(new ExecuteAutomationRunJob($run->id), [app(AutomationEngine::class)]);

        return $run->fresh();
    }

    #[Test]
    public function a_builder_saved_triggernode_automation_runs_past_the_trigger_lookup(): void
    {
        $automation = $this->automationWith($this->builderNodes());

        $run = $this->execute($automation);

        $this->assertNotSame('No trigger node.', $run->error);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull(
            AutomationRunLog::where('run_id', $run->id)->where('node_id', 'add_tag-1789827483028')->first(),
            'The action node after the trigger must actually have executed.'
        );
    }

    #[Test]
    public function a_seed_style_trigger_automation_still_runs_unchanged(): void
    {
        $automation = $this->automationWith($this->seedStyleNodes());

        $run = $this->execute($automation);

        $this->assertNull($run->error);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull(AutomationRunLog::where('run_id', $run->id)->where('node_id', 'add_tag-1')->first());
    }

    #[Test]
    public function a_run_with_no_trigger_node_at_all_still_fails_with_the_explicit_error(): void
    {
        $nodes = $this->builderNodes();
        $nodes[0] = ['id' => 'orphan-1', 'type' => 'add_tag', 'position' => ['x' => 0, 'y' => 0], 'data' => ['tag' => 'X', 'nodeType' => 'add_tag']];
        $automation = $this->automationWith($nodes);

        $run = $this->execute($automation);

        $this->assertSame('failed', $run->status);
        $this->assertSame('No trigger node.', $run->error);
    }

    #[Test]
    public function the_test_simulation_and_the_real_run_agree_on_which_node_is_the_trigger(): void
    {
        $automation = $this->automationWith($this->builderNodes());

        $simulation = app(AutomationEngine::class)->testRun(
            $automation,
            $automation->nodes,
            $automation->edges,
        );

        $this->assertTrue($simulation['ok'], json_encode($simulation));
        $this->assertSame('completed', $this->execute($automation)->status);
    }
}
