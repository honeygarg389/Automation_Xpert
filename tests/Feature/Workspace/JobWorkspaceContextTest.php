<?php

namespace Tests\Feature\Workspace;

use App\Exceptions\MissingWorkspaceContextException;
use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Phase 0, slice 4. The job middleware, exercised.
 *
 * The subject is `ScopedFixture` (declared in WorkspaceScopeTest) on the `leads`
 * table, because no application model carries the trait until slice 5. The
 * middleware itself is the real one.
 */
class JobWorkspaceContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
        ContextProbeJob::$seen = [];
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function seedLead(int $workspaceId, string $name): int
    {
        return DB::table('leads')->insertGetId([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'whatsapp_status' => 'unknown',
            'pushed_to_contacts' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Run a job through its own middleware, the way the queue worker does. */
    private function runThroughMiddleware(object $job): mixed
    {
        $pipeline = array_reverse($job->middleware());
        $next = fn () => $job->handle();

        foreach ($pipeline as $middleware) {
            $current = $next;
            $next = fn () => $middleware->handle($job, fn () => $current());
        }

        return $next();
    }

    // ── Context is established from the job's own payload ──────────────────

    #[Test]
    public function the_middleware_establishes_the_workspace_from_the_jobs_payload(): void
    {
        $id = $this->seedLead(4242, 'target');

        $this->runThroughMiddleware(new ContextProbeJob($id));

        $this->assertSame([4242], ContextProbeJob::$seen);
    }

    #[Test]
    public function inside_the_job_other_tenants_rows_are_invisible(): void
    {
        $mine = $this->seedLead(100, 'mine');
        $this->seedLead(200, 'theirs');
        $this->seedLead(200, 'theirs too');

        $this->runThroughMiddleware(new CountingJob($mine));

        $this->assertSame(1, CountingJob::$count,
            'A job must see only its own tenant. The other rows exist — see the positive control.');
        $this->assertSame(3, DB::table('leads')->count(),
            'Positive control: the hidden rows are really there.');
    }

    // ── The leak this slice exists to prevent ──────────────────────────────

    /**
     * ⚠️ THE ONE THAT MATTERS.
     *
     * `WorkspaceContext` memoises per user id for the life of the PHP process,
     * and a queue worker is long-lived. Two jobs for two tenants really do run
     * in one process, one after the other.
     *
     * This runs BOTH jobs for real, in sequence, in this process, with the
     * `Queue::before` flush simulated between them exactly as the worker does
     * it. Asserting on a config value or on a single job would prove nothing.
     */
    #[Test]
    public function context_does_not_survive_from_one_job_to_the_next_for_a_different_tenant(): void
    {
        $first = $this->seedLead(111, 'tenant one');
        $second = $this->seedLead(222, 'tenant two');

        $this->runThroughMiddleware(new ContextProbeJob($first));

        // Exactly what AppServiceProvider registers on Queue::before.
        WorkspaceContext::flush();

        $this->runThroughMiddleware(new ContextProbeJob($second));

        $this->assertSame([111, 222], ContextProbeJob::$seen,
            'The second job saw the first job\'s workspace. That is the cross-tenant leak in a '
            .'background process that this slice exists to prevent.');
    }

    /**
     * And with no flush at all, `for()`'s own `finally` must still restore —
     * otherwise the flush would be load-bearing for correctness rather than a
     * safety net, and a missed flush would leak silently.
     */
    #[Test]
    public function the_override_is_restored_even_without_the_between_jobs_flush(): void
    {
        $first = $this->seedLead(111, 'tenant one');
        $second = $this->seedLead(222, 'tenant two');

        $this->runThroughMiddleware(new ContextProbeJob($first));
        $this->runThroughMiddleware(new ContextProbeJob($second));

        $this->assertSame([111, 222], ContextProbeJob::$seen);
        $this->assertNull(WorkspaceContext::id(), 'The override outlived the job that set it.');
    }

    #[Test]
    public function a_job_that_throws_still_leaves_no_context_behind(): void
    {
        $id = $this->seedLead(333, 'boom');

        try {
            $this->runThroughMiddleware(new ThrowingJob($id));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull(WorkspaceContext::id(),
            'A job that threw left its workspace context set for the next job.');
    }

    // ── The flush is actually WIRED, not merely available ──────────────────

    /**
     * ⚠️ ADDED AFTER A STASH-CHECK PASSED THAT SHOULD HAVE FAILED.
     *
     * `context_does_not_survive_...` above calls `WorkspaceContext::flush()`
     * itself, so it proves the flush WORKS. It does not prove anything CALLS
     * it — commenting out `Queue::before($flush)` in AppServiceProvider left
     * the whole file green.
     *
     * These two exercise the registration. The memo is poisoned by resolving
     * context and THEN changing the underlying row: a stale memo returns the
     * old workspace, a flushed one re-resolves to the new. Nothing here calls
     * flush() directly, so only the listener can make it pass.
     */
    #[Test]
    public function the_queue_worker_flush_is_registered_and_clears_the_memo(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->actingAs($user);

        $this->assertSame((int) $workspace->id, WorkspaceContext::id(), 'memoised');

        // Change the answer underneath the memo. The refresh() matters: actingAs()
        // hands the guard THIS instance, so without it re-resolution would read a
        // stale in-memory workspace_id and the test would fail for a reason that
        // has nothing to do with the flush.
        DB::table('users')->where('id', $user->id)->update(['workspace_id' => 777]);
        $user->refresh();

        // A real dispatch on the sync connection, which fires Queue::before.
        MemoProbeJob::$seen = null;
        dispatch_sync(new MemoProbeJob);

        $this->assertSame(777, MemoProbeJob::$seen,
            'The job saw the pre-dispatch memo. Queue::before is not flushing WorkspaceContext, '
            .'so a worker handling tenant A then tenant B would give B tenant A\'s context.');
    }

    /**
     * The console half of the same registration.
     *
     * ⚠️ This dispatches `CommandStarting` DIRECTLY, and the reason is worth
     * knowing: `Artisan::call()` does NOT fire it — measured, the listener count
     * was 0 across a call. The event is fired by the console Kernel on a real
     * `php artisan …` invocation, which a feature test cannot produce.
     *
     * So this proves what is ours to prove — that our listener is registered
     * against the event and flushes when it arrives. Whether Laravel fires the
     * event on a real CLI run is Laravel's contract, not ours.
     */
    #[Test]
    public function the_console_flush_is_registered_and_clears_the_memo(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->actingAs($user);

        $this->assertSame((int) $workspace->id, WorkspaceContext::id(), 'memoised');

        DB::table('users')->where('id', $user->id)->update(['workspace_id' => 888]);
        $user->refresh();

        event(new CommandStarting(
            'anything',
            new ArrayInput([]),
            new BufferedOutput
        ));

        $this->assertSame(888, WorkspaceContext::id(),
            'schedule:run and every artisan command share a process with whatever ran before '
            .'them; without this listener the first command\'s tenant would be reused.');
    }

    // ── Fail loudly ────────────────────────────────────────────────────────

    #[Test]
    public function a_job_whose_workspace_cannot_be_resolved_throws_rather_than_doing_nothing(): void
    {
        $this->expectException(MissingWorkspaceContextException::class);

        // A key that does not exist: the row is gone, or the id was wrong.
        $this->runThroughMiddleware(new ContextProbeJob(999999));
    }

    #[Test]
    public function the_failure_does_not_run_the_job_body_at_all(): void
    {
        try {
            $this->runThroughMiddleware(new ContextProbeJob(999999));
        } catch (MissingWorkspaceContextException) {
            // expected
        }

        $this->assertSame([], ContextProbeJob::$seen,
            'handle() ran despite the workspace being unresolvable.');
    }

    // ── Cross-tenant, declared ─────────────────────────────────────────────

    #[Test]
    public function a_declared_cross_tenant_job_runs_with_no_context_and_sees_every_workspace(): void
    {
        $this->seedLead(1, 'a');
        $this->seedLead(2, 'b');
        $this->seedLead(3, 'c');

        $this->runThroughMiddleware(new SchedulerJob);

        $this->assertSame(3, SchedulerJob::$count,
            'A scheduler must see every workspace — that is its entire function.');
    }

    #[Test]
    public function cross_tenant_requires_a_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EstablishesWorkspaceContext::crossTenant('   ');
    }

    // ── failed() runs OUTSIDE the middleware ───────────────────────────────

    /**
     * Laravel invokes `failed()` from the queue worker's exception path, NOT
     * through the job's middleware pipeline. So a `failed()` handler has no
     * workspace context and, under the scope, sees nothing.
     *
     * Stating it plainly rather than working around it: **the `failed()`
     * handlers on `ProcessEcommerceWebhookJob` and `ProcessInboundMessageJob`
     * are unscoped.** Both only call `Log::error()` with ids from the job's own
     * payload, so neither queries tenant data and neither is broken today. But
     * anyone adding a query to a `failed()` handler will get an empty result and
     * no indication why.
     *
     * This test pins the behaviour so the next person discovers it here rather
     * than in production.
     */
    #[Test]
    public function failed_handlers_run_outside_the_middleware_and_therefore_have_no_context(): void
    {
        $id = $this->seedLead(555, 'x');
        $job = new ThrowingJob($id);

        try {
            $this->runThroughMiddleware($job);
        } catch (\RuntimeException) {
            // expected
        }

        // The worker calls failed() separately, with no middleware around it.
        $job->failed(new \RuntimeException('boom'));

        $this->assertNull($job->contextInsideFailed,
            'failed() saw a workspace context. If that ever becomes true, this comment and '
            .'the ProcessEcommerceWebhookJob/ProcessInboundMessageJob note need revisiting.');
    }
}

// ─── Test doubles ──────────────────────────────────────────────────────────

class ContextProbeJob
{
    /** @var list<int|null> */
    public static array $seen = [];

    public function __construct(public readonly int $leadId) {}

    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::from(ScopedFixture::class, $this->leadId)];
    }

    public function handle(): void
    {
        self::$seen[] = WorkspaceContext::id();
    }
}

class CountingJob
{
    public static int $count = 0;

    public function __construct(public readonly int $leadId) {}

    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::from(ScopedFixture::class, $this->leadId)];
    }

    public function handle(): void
    {
        self::$count = ScopedFixture::count();
    }
}

class ThrowingJob
{
    public ?int $contextInsideFailed = null;

    public function __construct(public readonly int $leadId) {}

    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::from(ScopedFixture::class, $this->leadId)];
    }

    public function handle(): void
    {
        throw new \RuntimeException('boom');
    }

    public function failed(\Throwable $e): void
    {
        $this->contextInsideFailed = WorkspaceContext::id();
    }
}

/** Dispatched for real, on the sync connection, so Queue::before fires. */
class MemoProbeJob implements ShouldQueue
{
    use \Illuminate\Foundation\Bus\Dispatchable, \Illuminate\Queue\InteractsWithQueue, Queueable;

    public static ?int $seen = null;

    public function handle(): void
    {
        self::$seen = WorkspaceContext::id();
    }
}

class SchedulerJob
{
    public static int $count = 0;

    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::crossTenant('reason: scans every workspace for due work')];
    }

    public function handle(): void
    {
        self::$count = ScopedFixture::count();
    }
}
