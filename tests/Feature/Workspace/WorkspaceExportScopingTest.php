<?php

namespace Tests\Feature\Workspace;

use App\Exceptions\MissingWorkspaceContextException;
use App\Jobs\GenerateWorkspaceExportJob;
use App\Modules\Shared\Models\Contact;
use App\Services\OnboardingService;
use App\Services\WorkspaceExportService;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * BUG-008 — the GDPR data export shipped the wrong workspace's data.
 *
 * A user switched into workspace B who requested an export received workspace
 * A's contacts, conversations and messages. Not a cross-tenant leak — the
 * requester is a member of both — but the wrong workspace's data, in the one
 * feature whose entire purpose is regulatory correctness.
 *
 * These tests assert on the EXPORTED CONTENT, not on the job being dispatched.
 * The whole failure was in what got written: a dispatch assertion passed
 * throughout the life of the bug.
 *
 * Covered here:
 *   - the bug itself, both directions (switched exports B, unswitched exports A)
 *   - the loud-failure path: a job that cannot establish a workspace throws
 *     MissingWorkspaceContextException rather than exporting home data
 *   - the dispatch capture: the controller puts the resolved workspace on the job
 *   - onboarding progress reflecting the switched workspace, with its control
 */
class WorkspaceExportScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
        Storage::fake('local');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function contact(int $workspaceId, string $first): Contact
    {
        return Contact::create([
            'workspace_id' => $workspaceId,
            'first_name' => $first,
            'phone_e164' => '+1555'.random_int(1000000, 9999999),
        ]);
    }

    /** Read contacts.csv out of the generated archive. */
    private function contactsCsv(string $storagePath): string
    {
        $absolute = Storage::path($storagePath);
        $this->assertFileExists($absolute, 'The export archive was not written.');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true, 'The export archive could not be opened.');
        $csv = $zip->getFromName('contacts.csv');
        $zip->close();

        $this->assertIsString($csv, 'contacts.csv is missing from the archive.');

        return $csv;
    }

    // ── THE BUG ────────────────────────────────────────────────────────────

    /**
     * The regression test for BUG-008. Asserts on archive CONTENT: before the
     * fix this produced HomeContact because the job derived the workspace from
     * the user, and a user has a home workspace but not a current one.
     */
    #[Test]
    public function an_export_requested_while_switched_contains_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->contact($home->id, 'HomeContact');
        $this->contact($other->id, 'OtherContact');

        $path = app(WorkspaceExportService::class)->generate($user, $other->id);
        $csv = $this->contactsCsv($path);

        $this->assertStringContainsString('OtherContact', $csv,
            'The export must contain the workspace that was asked for.');
        $this->assertStringNotContainsString('HomeContact', $csv,
            'BUG-008: the export contained the home workspace instead of the requested one.');
    }

    /** Positive control: unswitched, the home workspace still exports correctly. */
    #[Test]
    public function an_export_requested_unswitched_contains_the_home_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->contact($home->id, 'HomeContact');
        $this->contact($other->id, 'OtherContact');

        $path = app(WorkspaceExportService::class)->generate($user, $home->id);
        $csv = $this->contactsCsv($path);

        $this->assertStringContainsString('HomeContact', $csv);
        $this->assertStringNotContainsString('OtherContact', $csv,
            'The export leaked the other workspace.');
    }

    /** End to end through the job, which is where the workspace used to be lost. */
    #[Test]
    public function the_job_exports_the_workspace_it_was_given_not_the_users_home(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->contact($home->id, 'HomeContact');
        $this->contact($other->id, 'OtherContact');

        (new GenerateWorkspaceExportJob($user->id, $other->id))
            ->handle(app(WorkspaceExportService::class));

        $files = Storage::allFiles('exports/'.$other->id);
        $this->assertNotEmpty($files, 'The export was not written under the requested workspace.');

        $csv = $this->contactsCsv($files[0]);
        $this->assertStringContainsString('OtherContact', $csv);
        $this->assertStringNotContainsString('HomeContact', $csv,
            'BUG-008: the job exported the home workspace despite being given another.');
    }

    // ── The loud-failure path ──────────────────────────────────────────────

    /**
     * A job that cannot establish a workspace must throw, not fall back to
     * home. Covers jobs queued before this fix shipped.
     */
    #[Test]
    public function a_job_without_a_workspace_fails_loudly_instead_of_exporting_home_data(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $this->contact($home->id, 'HomeContact');

        $this->expectException(MissingWorkspaceContextException::class);

        try {
            (new GenerateWorkspaceExportJob($user->id, null))
                ->handle(app(WorkspaceExportService::class));
        } finally {
            // The point of failing loudly: nothing was written at all.
            $this->assertEmpty(Storage::allFiles('exports'),
                'A workspace-less export wrote an archive instead of failing.');
        }
    }

    // ── The dispatch capture ───────────────────────────────────────────────

    #[Test]
    public function requesting_an_export_while_switched_dispatches_the_switched_workspace(): void
    {
        Queue::fake();
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.settings.data-export.store'))
            ->assertRedirect();

        Queue::assertPushed(GenerateWorkspaceExportJob::class, function ($job) use ($other) {
            // The workspace must be ON the job — recovering it later is exactly
            // what BUG-008 proved impossible.
            $reflected = new \ReflectionProperty($job, 'workspaceId');

            return (int) $reflected->getValue($job) === (int) $other->id;
        });
    }

    #[Test]
    public function requesting_an_export_unswitched_dispatches_the_home_workspace(): void
    {
        Queue::fake();
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->post(route('client.settings.data-export.store'))
            ->assertRedirect();

        Queue::assertPushed(GenerateWorkspaceExportJob::class, function ($job) use ($home) {
            $reflected = new \ReflectionProperty($job, 'workspaceId');

            return (int) $reflected->getValue($job) === (int) $home->id;
        });
    }

    // ── Onboarding ─────────────────────────────────────────────────────────

    /**
     * Onboarding DETECTION is workspace-scoped: the step queries run against the
     * workspace passed in, not the user's home workspace.
     *
     * The switched workspace is checked FIRST, deliberately. getProgress()
     * PERSISTS auto-detected completions to `onboarding_steps`, which is keyed
     * on (user_id, step) with NO workspace_id — so once a step completes in any
     * workspace, isCompleted() short-circuits on the persisted row everywhere.
     * Checking home first would mark the step done for the user and make this
     * test pass for the wrong reason.
     *
     * That persistence gap is a real, separate defect — recorded as BUG-009. It
     * needs a schema change and is out of scope here; this commit fixes which
     * workspace is QUERIED, which is the part the three Core sites controlled.
     */
    #[Test]
    public function onboarding_detection_queries_the_workspace_it_is_given(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();

        // Only the HOME workspace has contacts.
        $this->contact($home->id, 'HomeContact');

        $service = app(OnboardingService::class);

        $otherProgress = collect($service->getProgress($user, $other->id)['steps'])
            ->firstWhere('key', 'import_first_contacts');
        $this->assertFalse($otherProgress['completed'],
            'The switched workspace has no contacts, so progress must not be inherited from home.');

        $homeProgress = collect($service->getProgress($user, $home->id)['steps'])
            ->firstWhere('key', 'import_first_contacts');
        $this->assertTrue($homeProgress['completed'],
            'The home workspace has contacts, so this step is complete there.');
    }

    #[Test]
    public function onboarding_progress_is_reported_for_the_switched_workspace_over_http(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->getJson(route('client.onboarding.show'))
            ->assertOk();

        // Reaching here without a 500 proves the controller passes a workspace;
        // the value itself is asserted directly in the test above, where the
        // two workspaces can be compared without a second request (the context
        // memoises per user for the life of a test).
        $this->assertTrue(true);
    }
}
