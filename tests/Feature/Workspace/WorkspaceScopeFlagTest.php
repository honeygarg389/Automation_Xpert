<?php

namespace Tests\Feature\Workspace;

use App\Models\SystemSetting;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0, slice 3. `ENFORCE_WORKSPACE_SCOPE` — the emergency brake.
 *
 * Built now, while the scope still applies to no application model, because
 * `docs/deployment-safety.md:571` says exactly this: build the brake WITH the
 * scope, not after it. A brake first exercised during the incident it exists for
 * is not a brake.
 *
 * The scope IS active here — `ScopedFixture` (declared in WorkspaceScopeTest)
 * uses the trait — so both positions are tested against real filtering rather
 * than against a config value in isolation.
 */
class WorkspaceScopeFlagTest extends TestCase
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

    private function seedLead(int $workspaceId, string $name): void
    {
        DB::table('leads')->insert([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'whatsapp_status' => 'unknown',
            'pushed_to_contacts' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Both positions, against real filtering ─────────────────────────────

    #[Test]
    public function with_the_flag_on_the_scope_filters(): void
    {
        config(['workspace.enforce_scope' => true]);

        $this->seedLead(1, 'one');
        $this->seedLead(2, 'two');

        $this->assertSame(1, WorkspaceContext::for(1, fn () => ScopedFixture::count()));
    }

    #[Test]
    public function with_the_flag_off_the_scope_does_not_filter(): void
    {
        config(['workspace.enforce_scope' => false]);

        $this->seedLead(1, 'one');
        $this->seedLead(2, 'two');

        $this->assertSame(2, WorkspaceContext::for(1, fn () => ScopedFixture::count()),
            'With the brake pulled, the scope must be a complete no-op — that is the point of it.');
    }

    /**
     * The brake must also release the fail-closed behaviour. Otherwise pulling it
     * during an incident would turn "sees the wrong rows" into "sees no rows",
     * which is not an improvement at 3am.
     */
    #[Test]
    public function with_the_flag_off_a_null_context_no_longer_matches_nothing(): void
    {
        config(['workspace.enforce_scope' => false]);

        $this->seedLead(1, 'one');
        $this->seedLead(2, 'two');

        $this->assertNull(WorkspaceContext::id());
        $this->assertSame(2, ScopedFixture::count());
    }

    // ── The default ────────────────────────────────────────────────────────

    /**
     * A brake whose default is "off" is not a brake — it is an isolation feature
     * that ships disabled and nobody notices. This asserts the default in the
     * config FILE, not the value currently in the container, because a test that
     * only reads `config()` would pass even if the file defaulted to false and
     * something else had set it true.
     */
    #[Test]
    public function the_flag_defaults_to_on(): void
    {
        $this->assertTrue(config('workspace.enforce_scope'),
            'The scope must be enforced by default.');

        $source = file_get_contents(config_path('workspace.php'));

        $this->assertMatchesRegularExpression(
            "/'enforce_scope'\s*=>\s*env\(\s*'ENFORCE_WORKSPACE_SCOPE'\s*,\s*true\s*\)/",
            $source,
            'config/workspace.php must default ENFORCE_WORKSPACE_SCOPE to true. A brake that '
            .'ships off is an isolation feature that ships off.'
        );
    }

    /**
     * The scope reads the flag per query rather than capturing it at boot, so
     * `config:clear` is enough to take effect. If it were captured at boot, the
     * documented recovery procedure would not work.
     */
    #[Test]
    public function the_flag_is_read_at_query_time_not_captured_at_boot(): void
    {
        $this->seedLead(1, 'one');
        $this->seedLead(2, 'two');

        config(['workspace.enforce_scope' => true]);
        $filtered = WorkspaceContext::for(1, fn () => ScopedFixture::count());

        // Same process, same booted models, flag flipped in between.
        config(['workspace.enforce_scope' => false]);
        $unfiltered = WorkspaceContext::for(1, fn () => ScopedFixture::count());

        $this->assertSame(1, $filtered);
        $this->assertSame(2, $unfiltered);
    }

    // ── It must not be reachable from the admin panel ──────────────────────

    /**
     * ⚠️ THE ADMIN-REACHABILITY TEST.
     *
     * `Admin\SystemSettingsController::update()` validates `settings.*.key` as a
     * free-form string, so an admin with `manage_settings` can write ANY row into
     * `system_settings`. That is the codebase's pattern for admin-settable flags.
     *
     * The only thing keeping this flag out of that reach is that nothing reads it
     * from there. That is a property worth asserting rather than trusting: it
     * would be entirely natural for a later change to "helpfully" make the flag
     * settable from the settings screen, and the result would be a security
     * control disabled by anyone who compromises an admin account.
     */
    #[Test]
    public function the_flag_cannot_be_disabled_from_the_system_settings_table(): void
    {
        $this->seedLead(1, 'one');
        $this->seedLead(2, 'two');

        // Exactly what an admin could write through the settings screen.
        foreach (['ENFORCE_WORKSPACE_SCOPE', 'enforce_workspace_scope', 'workspace.enforce_scope'] as $key) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => 'false', 'group' => 'general']);
        }

        $this->assertSame('false', SystemSetting::get('ENFORCE_WORKSPACE_SCOPE'),
            'Positive control: the row really was written, so a passing assertion below means '
            .'the value is ignored rather than absent.');

        $this->assertTrue(config('workspace.enforce_scope'),
            'A system_settings row must not influence the flag.');

        $this->assertSame(1, WorkspaceContext::for(1, fn () => ScopedFixture::count()),
            'The scope must still be enforced after an admin wrote every plausible '
            .'system_settings key to disable it.');
    }

    /** The flag must not be read from anywhere but config. */
    #[Test]
    public function nothing_reads_the_flag_outside_the_config_file(): void
    {
        $hits = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (str_contains($source, 'ENFORCE_WORKSPACE_SCOPE')) {
                $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $hits,
            'The env var name must appear only in config/workspace.php. Reading env() directly '
            .'from application code bypasses config caching and makes the documented recovery '
            .'procedure (set .env, config:clear) unreliable. Found in: '.implode(', ', $hits));
    }
}
