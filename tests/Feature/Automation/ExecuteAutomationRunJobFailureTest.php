<?php

namespace Tests\Feature\Automation;

use App\Events\AutomationFailed;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ExecuteAutomationRunJob's catch block wrote the exception message to
 * `error_message`, a column that does not exist: the table has `error`, and
 * AutomationRun::$fillable lists `error`. Mass assignment silently discards an
 * unfillable key, so `status = 'failed'` persisted while the reason vanished —
 * the Runs page (which reads `run.error`) showed a failed run with no cause.
 *
 * These assert the STORED ROW, not the update() payload: a payload assertion
 * passes while the column drops the value, which is exactly how this shipped.
 */
class ExecuteAutomationRunJobFailureTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<array<string,mixed>>|null  $nodes */
    private function pendingRun(?array $nodes = null): AutomationRun
    {
        $ctx = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);

        $automation = Automation::create([
            'workspace_id' => $ctx['workspace']->id,
            'name' => 'Failure test',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'nodes' => $nodes ?? [['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []]],
            'edges' => [],
        ]);

        return AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
            'started_at' => now(),
        ]);
    }

    #[Test]
    public function an_exception_during_execution_is_stored_on_the_run_and_readable(): void
    {
        Event::fake([AutomationFailed::class]);
        $run = $this->pendingRun();

        // A REAL engine subclass that throws, not a Mockery chain: Mockery's fluent
        // shouldReceive()->andThrow() is untypeable under PHPStan level 6 (the same
        // limit SmartQrBatchForceDeleteTest documents), and the catch below proves
        // executeRun() ran, since the job can only rethrow what the engine threw.
        $engine = new class(app(ChannelManager::class), app(ChatbotRunner::class), app(LlmGateway::class)) extends AutomationEngine
        {
            public function executeRun(AutomationRun $run): void
            {
                throw new RuntimeException('Graph API exploded: HTTP 500');
            }
        };

        try {
            $this->runJob(new ExecuteAutomationRunJob($run->id), [$engine]);
            $this->fail('The job must rethrow so the queue can retry/fail it.');
        } catch (RuntimeException $e) {
            $this->assertSame('Graph API exploded: HTTP 500', $e->getMessage());
        }

        $stored = AutomationRun::find($run->id);
        $this->assertSame('failed', $stored->status);
        $this->assertSame('Graph API exploded: HTTP 500', $stored->error,
            'The exception message must land in the `error` column — not be silently discarded.');

        Event::assertDispatched(AutomationFailed::class, fn ($event) => $event->run->id === $run->id);
    }

    /**
     * POSITIVE CONTROL: the engine's own failure path (`No trigger node.`)
     * already writes `error` and reads back correctly. If this passed only
     * because `error` were unreadable in general, the test above would be
     * meaningless — so it pins that the same column round-trips a message.
     */
    #[Test]
    public function the_engines_own_failure_path_stores_its_message_in_the_same_column(): void
    {
        $run = $this->pendingRun(nodes: [['id' => 'n1', 'type' => 'add_tag', 'position' => ['x' => 0, 'y' => 0], 'data' => ['tag' => 'x']]]);

        $this->runJob(new ExecuteAutomationRunJob($run->id), [app(AutomationEngine::class)]);

        $stored = AutomationRun::find($run->id);
        $this->assertSame('failed', $stored->status);
        $this->assertSame('No trigger node.', $stored->error);
    }

    #[Test]
    public function a_run_that_succeeds_has_no_error(): void
    {
        $run = $this->pendingRun();

        $this->runJob(new ExecuteAutomationRunJob($run->id), [app(AutomationEngine::class)]);

        $this->assertNull(AutomationRun::find($run->id)->error);
    }
}
