<?php

namespace Tests\Feature\ProductionHardening;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ A job dispatched onto a named queue that no worker consumes is the
 * quietest failure this application has: no exception, no `failed_jobs` row,
 * the row just sits in `jobs` forever (see CLAUDE.md's `reserved=NEVER`
 * section). Petpooja slice 2 shipped `ProcessPosWebhookEventJob` on a
 * `restaurant` queue with no worker in `docker-compose.queues.yml` — every
 * bill would have stayed `pending` in production. "Remember to add a worker"
 * is not a control, so this makes the omission a failing build.
 *
 * Scope: literal `onQueue('name')` calls under app/. A queue name built from a
 * variable cannot be checked statically and is not covered.
 */
class QueueWorkerCoverageGuardTest extends TestCase
{
    /**
     * Queues KNOWN to be dispatched onto with no worker, each with the reason.
     *
     * ⚠️ This is a debt register, not a permission slip. Every entry is
     * asserted to STILL be both dispatched-onto and unconsumed
     * (`known_exceptions_are_still_real`), so the moment one is fixed the test
     * fails until the entry is deleted — the list cannot quietly rot into a
     * blanket exemption.
     *
     * @var array<string, string>
     */
    private const KNOWN_UNCONSUMED = [
        'automations' => 'Inherited from the initial import (4ec7e3e): '
            .'Api\\V1\\AutomationApiController dispatches ExecuteAutomationRunJob onto the plural '
            ."'automations' while every other dispatch of that job — and the only worker — use the "
            ."singular 'automation', so API-triggered automation runs are never consumed. Needs its "
            .'own fix/* branch (CLAUDE.md: never park a fix on a feature branch).',
    ];

    /** @return list<string> */
    private function dispatchedQueueNames(): array
    {
        $names = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match_all('/onQueue\(\s*[\'"]([a-z0-9_.-]+)[\'"]\s*\)/i', (string) file_get_contents($file->getPathname()), $m)) {
                array_push($names, ...$m[1]);
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private function workerQueueNames(): array
    {
        $compose = (string) file_get_contents(base_path('docker-compose.queues.yml'));

        preg_match_all('/queue:work(?:\s+\S+)?\s+--queue=([a-z0-9_.,-]+)/i', $compose, $m);

        $names = [];
        foreach ($m[1] as $list) {
            array_push($names, ...explode(',', $list));
        }

        return array_values(array_unique($names));
    }

    #[Test]
    public function every_queue_the_application_dispatches_onto_has_a_worker(): void
    {
        $dispatched = $this->dispatchedQueueNames();
        $workers = $this->workerQueueNames();

        $this->assertNotEmpty($dispatched, 'Scanner found no onQueue() calls at all — it has gone blind.');
        $this->assertNotEmpty($workers, 'Parser found no queue workers in docker-compose.queues.yml — it has gone blind.');

        $unconsumed = array_values(array_diff($dispatched, $workers, array_keys(self::KNOWN_UNCONSUMED)));

        $this->assertSame(
            [],
            $unconsumed,
            'Jobs are dispatched onto queue(s) with no worker in docker-compose.queues.yml: '
            .implode(', ', $unconsumed).'. They would sit unconsumed forever with no error.'
        );
    }

    #[Test]
    public function known_exceptions_are_still_real(): void
    {
        $dispatched = $this->dispatchedQueueNames();
        $workers = $this->workerQueueNames();

        foreach (array_keys(self::KNOWN_UNCONSUMED) as $queue) {
            $this->assertContains($queue, $dispatched, "'{$queue}' is no longer dispatched onto — delete it from KNOWN_UNCONSUMED.");
            $this->assertNotContains($queue, $workers, "'{$queue}' now has a worker — delete it from KNOWN_UNCONSUMED.");
        }
    }

    /** The scanner is not vacuous: the queue this guard was written for is seen on BOTH sides. */
    #[Test]
    public function the_restaurant_queue_is_dispatched_onto_and_has_a_worker(): void
    {
        $this->assertContains('restaurant', $this->dispatchedQueueNames());
        $this->assertContains('restaurant', $this->workerQueueNames());
    }
}
