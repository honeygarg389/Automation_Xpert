<?php

namespace Tests\Feature\Polish;

use App\Modules\Broadcasting\Models\UsageMeter;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE ORIGINAL VERSION OF THIS FILE COULD NOT FAIL.
 *
 * It called `UsageMeter::track()` exactly ONCE and asserted the stored value
 * equalled the amount tracked. One call cannot distinguish "accumulates" from
 * "resets to zero and then increments" — both leave `$by` in the column. The
 * meter had never accumulated, and this test was green for its whole life.
 *
 * Every test below therefore calls `track()` **more than once**. That is the
 * entire point; a single-call assertion here is a regression in the test, not a
 * simplification.
 */
class PlanLimitAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function stored(int $workspaceId, string $metric): int
    {
        return (int) DB::table('usage_meters')
            ->where('workspace_id', $workspaceId)
            ->where('metric', $metric)
            ->where('period', (int) now()->format('Ym'))
            ->value('value');
    }

    /** THE REGRESSION TEST. Three calls, and the answer must be three. */
    #[Test]
    public function tracking_three_times_accumulates_to_three(): void
    {
        $ws = $this->createWorkspaceContext()['workspace'];

        UsageMeter::track($ws->id, 'campaigns');
        $this->assertSame(1, $this->stored($ws->id, 'campaigns'), 'First call must create the row at 1.');

        UsageMeter::track($ws->id, 'campaigns');
        UsageMeter::track($ws->id, 'campaigns');

        $this->assertSame(3, $this->stored($ws->id, 'campaigns'),
            'Three calls left the counter at something other than 3. The meter is resetting '
            .'on each increment again — which silently disables every plan limit, because '
            .'EnforceLimit compares this number against the limit.');

        $this->assertSame(3, UsageMeter::current($ws->id, 'campaigns'),
            'current() must agree with the stored row.');
    }

    /** Increments larger than one must sum, not overwrite. */
    #[Test]
    public function tracking_with_a_quantity_sums_across_calls(): void
    {
        $ws = $this->createWorkspaceContext()['workspace'];

        UsageMeter::track($ws->id, 'ai_tokens', 500);
        UsageMeter::track($ws->id, 'ai_tokens', 500);
        UsageMeter::track($ws->id, 'ai_tokens', 250);

        $this->assertSame(1250, $this->stored($ws->id, 'ai_tokens'),
            'Quantities must add. 500 here would mean the last call overwrote the total.');
    }

    /** Metrics and workspaces must not bleed into one another. */
    #[Test]
    public function counters_are_separate_per_metric_and_per_workspace(): void
    {
        $a = $this->createWorkspaceContext()['workspace'];
        $b = $this->createWorkspaceContext()['workspace'];

        UsageMeter::track($a->id, 'campaigns', 2);
        UsageMeter::track($a->id, 'social_posts', 7);
        UsageMeter::track($b->id, 'campaigns', 5);

        $this->assertSame(2, $this->stored($a->id, 'campaigns'));
        $this->assertSame(7, $this->stored($a->id, 'social_posts'));
        $this->assertSame(5, $this->stored($b->id, 'campaigns'));
    }

    /**
     * `current()` deliberately bypasses the workspace scope, because its own
     * explicit `where('workspace_id', …)` is the boundary.
     *
     * The failure this prevents is specific and fail-OPEN: under the scope, a
     * null workspace context ANDs an unsatisfiable condition onto the query, so
     * usage reads 0 — and `EnforceLimit` reads 0 usage as "under limit". A
     * missing context would hand out unlimited quota.
     */
    #[Test]
    public function current_reads_the_named_workspace_even_with_no_context(): void
    {
        $ws = $this->createWorkspaceContext()['workspace'];
        UsageMeter::track($ws->id, 'campaigns', 4);

        WorkspaceContext::flush();

        $this->assertNull(WorkspaceContext::id(), 'Precondition: no workspace context.');
        $this->assertSame(4, UsageMeter::current($ws->id, 'campaigns'),
            'current() returned 0 with no context — which EnforceLimit would read as '
            .'"no usage yet" and allow the request. The scope must not filter this query.');
    }

    /** But ordinary Eloquent reads of the model ARE scoped. */
    #[Test]
    public function ordinary_queries_on_the_model_are_workspace_scoped(): void
    {
        $a = $this->createWorkspaceContext()['workspace'];
        $b = $this->createWorkspaceContext()['workspace'];

        UsageMeter::track($a->id, 'campaigns');
        UsageMeter::track($b->id, 'campaigns');

        $this->assertSame(2, DB::table('usage_meters')->count(), 'Positive control: two rows exist.');

        $this->assertSame(1, WorkspaceContext::for($a->id, fn () => UsageMeter::count()));
        $this->assertSame(1, WorkspaceContext::for($b->id, fn () => UsageMeter::count()));
    }
}
