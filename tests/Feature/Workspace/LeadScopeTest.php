<?php

namespace Tests\Feature\Workspace;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Models\Concerns\BelongsToWorkspace;
use App\Modules\Leads\Jobs\ScrapeLeadsJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScrapeJob;
use App\Support\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0, slice 5. `Lead` — the first application model to carry the trait.
 *
 * Chosen as the canary because Leads is one small module with three call sites,
 * so a wrong mechanism costs one model rather than twelve.
 *
 * ─── What the canary actually exposed ───────────────────────────────────────
 *
 * It was not too simple. Two findings came out of it:
 *
 *   1. The 403 -> 404 route-binding change (§G-4) fires on an ID-BOUND model.
 *      The plan predicted it for "the 8 uuid-bound models"; it applies to every
 *      route-bound scoped model.
 *
 *   2. BUG-020 — `GooglePlacesScraper` keys `Lead::updateOrCreate()` on
 *      `google_place_id` ALONE, and that column is GLOBALLY unique. Same shape
 *      as BUG-019, on a different table. Pinned below.
 */
class LeadScopeTest extends TestCase
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

    private function seedLead(int $workspaceId, string $name, ?string $placeId = null): int
    {
        return DB::table('leads')->insertGetId([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'google_place_id' => $placeId,
            'whatsapp_status' => 'unknown',
            'pushed_to_contacts' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ══ The read path ══════════════════════════════════════════════════════

    #[Test]
    public function lead_is_scoped(): void
    {
        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(Lead::class));
    }

    #[Test]
    public function reads_are_filtered_to_the_current_workspace(): void
    {
        $this->seedLead(11, 'mine');
        $this->seedLead(22, 'theirs');
        $this->seedLead(22, 'theirs too');

        $this->assertSame(['mine'], WorkspaceContext::for(11, fn () => Lead::pluck('name')->all()));
        $this->assertSame(2, WorkspaceContext::for(22, fn () => Lead::count()));
    }

    /** POSITIVE CONTROL: the hidden rows genuinely exist. */
    #[Test]
    public function the_filtered_rows_really_are_there(): void
    {
        $this->seedLead(11, 'mine');
        $this->seedLead(22, 'theirs');

        $this->assertSame(2, DB::table('leads')->count());
        $this->assertSame(1, WorkspaceContext::for(11, fn () => Lead::count()));
    }

    #[Test]
    public function a_null_context_matches_nothing(): void
    {
        $this->seedLead(11, 'mine');

        $this->assertSame(0, Lead::count());
    }

    // ══ THE EXISTING-ROW WRITE PATH — the 4c failure mode ══════════════════

    /**
     * ⚠️ THE ONE THAT MATTERS, and it found a live defect.
     *
     * `GooglePlacesScraper` writes with:
     *
     *     Lead::updateOrCreate(['google_place_id' => $placeId], ['workspace_id' => $wsId, …])
     *
     * The lookup key is `google_place_id` ALONE — no workspace_id — and that
     * column carries a GLOBAL unique index (`leads_google_place_id_unique`).
     *
     * BEFORE the scope: workspace B scraping a business workspace A already
     * scraped MATCHES A's row and overwrites its `workspace_id` to B. The lead
     * is silently moved between tenants. That is BUG-019's shape on a new table.
     *
     * AFTER the scope: the lookup becomes `google_place_id = X AND workspace_id
     * = B`, misses A's row, attempts an INSERT, and the global unique index
     * refuses it. The failure moves from silent theft to a loud exception —
     * better, but still broken.
     *
     * Both are proven below. Neither is live today only because the scraper has
     * never worked (BUG-007).
     */
    #[Test]
    public function the_scraper_write_key_collides_across_workspaces_and_the_scope_turns_theft_into_a_hard_failure(): void
    {
        $this->seedLead(11, 'Joes Pizza', 'PLACE-X');

        // The exact call GooglePlacesScraper makes, as workspace 22.
        try {
            WorkspaceContext::for(22, fn () => Lead::updateOrCreate(
                ['google_place_id' => 'PLACE-X'],
                ['workspace_id' => 22, 'name' => 'Joes Pizza']
            ));

            $this->fail(
                'Expected a unique-constraint violation. If this ever stops throwing, check '
                .'whether the lookup now matches workspace 11\'s row — that would mean the lead '
                .'was silently STOLEN, which is the pre-scope behaviour and worse.'
            );
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('google_place_id', $e->getMessage());
        }

        // Workspace 11 still owns it. The scope prevented the theft.
        $this->assertSame(11, (int) DB::table('leads')->where('google_place_id', 'PLACE-X')->value('workspace_id'));
        $this->assertSame(1, DB::table('leads')->where('google_place_id', 'PLACE-X')->count());
    }

    /**
     * POSITIVE CONTROL for the above: the SAME call in the OWNING workspace
     * updates in place, exactly as it always did. The defect is cross-workspace
     * only — a scraper re-run for its own tenant must keep working.
     */
    #[Test]
    public function the_same_scraper_write_in_the_owning_workspace_still_updates_in_place(): void
    {
        $this->seedLead(11, 'Old Name', 'PLACE-X');

        WorkspaceContext::for(11, fn () => Lead::updateOrCreate(
            ['google_place_id' => 'PLACE-X'],
            ['workspace_id' => 11, 'name' => 'New Name']
        ));

        $this->assertSame(1, DB::table('leads')->where('google_place_id', 'PLACE-X')->count(),
            'A same-workspace re-scrape duplicated instead of updating.');
        $this->assertSame('New Name', DB::table('leads')->where('google_place_id', 'PLACE-X')->value('name'));
    }

    /** A first scrape of an unseen place still creates normally. */
    #[Test]
    public function a_brand_new_place_is_created(): void
    {
        WorkspaceContext::for(11, fn () => Lead::updateOrCreate(
            ['google_place_id' => 'PLACE-NEW'],
            ['workspace_id' => 11, 'name' => 'New Place']
        ));

        $this->assertSame(11, (int) DB::table('leads')->where('google_place_id', 'PLACE-NEW')->value('workspace_id'));
    }

    /**
     * Creation with a NULL context. Writes do not need a successful read, so
     * this succeeds — the trait filters reads only, as ruled. Pinned so the
     * ruling is visible rather than assumed.
     */
    #[Test]
    public function creating_a_lead_with_no_context_still_works_because_the_trait_filters_reads_only(): void
    {
        $lead = Lead::create(['workspace_id' => 33, 'name' => 'No context']);

        $this->assertSame(33, (int) DB::table('leads')->where('id', $lead->id)->value('workspace_id'));
        $this->assertSame(0, Lead::count(), 'It was written, and is invisible without context.');
    }

    // ══ Callers still behave ═══════════════════════════════════════════════

    /**
     * ScrapeLeadsJob is Group A: its middleware resolves the workspace from
     * LeadScrapeJob before handle() runs. Proven here rather than assumed,
     * because Lead is now scoped and a wrong context makes the job invisible.
     */
    #[Test]
    public function the_scrape_job_establishes_its_workspace_before_touching_leads(): void
    {
        $scrapeJobId = DB::table('lead_scrape_jobs')->insertGetId([
            'workspace_id' => 77,
            'keyword' => 'pizza',
            'location' => 'Dhaka',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedLead(77, 'mine');
        $this->seedLead(88, 'theirs');

        $job = new ScrapeLeadsJob($scrapeJobId);
        $seen = null;

        foreach ($job->middleware() as $middleware) {
            $middleware->handle($job, function () use (&$seen) {
                $seen = ['workspace' => WorkspaceContext::id(), 'leads' => Lead::count()];
            });
        }

        $this->assertSame(77, $seen['workspace'], 'The job did not establish its own workspace.');
        $this->assertSame(1, $seen['leads'], 'Inside the job, only its own workspace\'s leads are visible.');
    }

    #[Test]
    public function the_scrape_job_still_declares_middleware(): void
    {
        $this->assertTrue(method_exists(ScrapeLeadsJob::class, 'middleware'));
        $this->assertInstanceOf(
            EstablishesWorkspaceContext::class,
            (new ScrapeLeadsJob(1))->middleware()[0]
        );
    }

    /** The controller list path, end to end. */
    #[Test]
    public function the_lead_list_shows_only_the_current_workspaces_leads(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->seedLead((int) $workspace->id, 'mine');
        $this->seedLead(9999, 'theirs');

        $this->actingAs($user)
            ->get(route('client.leads.index'))
            ->assertOk();

        $this->assertSame(1, WorkspaceContext::for((int) $workspace->id, fn () => Lead::count()));
    }

    /** LeadScrapeJob is NOT yet scoped — pinned so slice 7 notices. */
    #[Test]
    public function lead_scrape_job_is_still_unscoped_and_is_slice_seven_work(): void
    {
        $this->assertNotContains(BelongsToWorkspace::class, class_uses_recursive(LeadScrapeJob::class),
            'LeadScrapeJob became scoped without this test being updated — check that '
            .'EstablishesWorkspaceContext::from() still resolves it, since that lookup would '
            .'then need the bypass it already applies conditionally.');
    }
}
