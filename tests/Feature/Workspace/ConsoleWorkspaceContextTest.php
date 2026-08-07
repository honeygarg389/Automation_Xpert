<?php

namespace Tests\Feature\Workspace;

use App\Mail\WeeklyDigestMail;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0, slice 4b. The three console commands that reach tenant data.
 *
 * ─── There is no framework here, on purpose ─────────────────────────────────
 *
 * Commands have no middleware pipeline, and the three do not share a shape:
 *
 *   SendWeeklyDigestCommand        PER-TENANT, looping. Enumerates Workspace
 *                                  (never scoped), then runs each iteration
 *                                  inside for($workspace->id).
 *   WhatsappWebhookRegisterCommand CROSS-TENANT. Registers one platform-wide
 *                                  Meta callback and subscribes every WABA.
 *   MessengerProfileTestCommand    BOTH. crossTenant to discover the account,
 *                                  then for() around the rest.
 *
 * Three callers, three shapes: each wraps its own body. Inventing an abstraction
 * over three call sites that disagree would have cost more than it saved and
 * would have forced at least one of them into the wrong shape.
 *
 * ─── Testing the FUTURE state ───────────────────────────────────────────────
 *
 * No application model carries the trait until slice 5, so these commands would
 * pass today regardless. The tests below register `WorkspaceScope` on the
 * relevant models at runtime, which is exactly what slices 6-7 will do
 * permanently — so a command that will break in slice 6 breaks HERE instead.
 *
 * `Model::clearBootedModels()` in tearDown removes it again; booted scopes are
 * per-class-per-process, so leaving one behind would contaminate every later
 * test in the run.
 */
class ConsoleWorkspaceContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
    }

    protected function tearDown(): void
    {
        // MUST run: a global scope left on a shared model class leaks into every
        // subsequent test in this process.
        Model::clearBootedModels();
        WorkspaceContext::flush();
        parent::tearDown();
    }

    /** Apply the scope now, the way slices 6-7 will apply it permanently. */
    private function simulateScopeOn(string ...$models): void
    {
        foreach ($models as $model) {
            $model::addGlobalScope(new WorkspaceScope);
        }
    }

    /** @return array{0:Workspace,1:User} */
    private function workspaceWithOwner(string $name): array
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext([], ['name' => $name]);

        $workspace->forceFill(['owner_id' => $user->id])->save();

        return [$workspace->fresh(), $user];
    }

    /**
     * NOTE ON TIMING: the digest window is `[now()->subWeek()->startOfDay(),
     * now()->startOfDay()]` — and `$to` is then MUTATED by `$to->subDay()` while
     * building the period label, because Carbon is mutable. So the real window
     * ends YESTERDAY. Rows created `now()` fall outside it entirely and every
     * count is 0 for reasons that have nothing to do with the workspace scope.
     * Seeded three days back to sit safely inside. See docs/found-bugs.md.
     */
    private function seedConversation(int $workspaceId): void
    {
        $when = now()->subDays(3);

        // conversations.contact_id is NOT NULL with no default, so a contact has
        // to exist first — the digest counts conversations, not contacts, so the
        // contact is scaffolding rather than part of what is being asserted.
        $contactId = DB::table('contacts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'created_at' => $when,
            'updated_at' => $when,
        ]);

        DB::table('conversations')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'contact_id' => $contactId,
            'status' => 'open',
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    // ── SendWeeklyDigestCommand — per-tenant, looping ──────────────────────

    /**
     * The urgent one. It is SCHEDULED, so without context it would mail every
     * customer a digest of zeroes, every week, exiting 0 the whole time.
     */
    #[Test]
    public function the_weekly_digest_builds_each_workspaces_stats_inside_that_workspace(): void
    {
        [$a] = $this->workspaceWithOwner('Tenant A');
        [$b] = $this->workspaceWithOwner('Tenant B');

        $this->seedConversation($a->id);
        $this->seedConversation($a->id);
        $this->seedConversation($b->id);

        $this->simulateScopeOn(Conversation::class);

        $seen = [];
        Mail::fake();

        Artisan::call('reports:weekly-digest');

        Mail::assertQueuedCount(2);

        Mail::assertQueued(WeeklyDigestMail::class, function ($mail) use (&$seen) {
            $seen[$mail->workspace->id] = $mail->stats['conversations_opened'];

            return true;
        });

        $this->assertSame(2, $seen[$a->id] ?? null,
            'Tenant A must see its own 2 conversations. 0 here means the command ran with no '
            .'workspace context and the scope failed closed — a weekly email of zeroes.');
        $this->assertSame(1, $seen[$b->id] ?? null, 'Tenant B must see exactly its own 1.');
    }

    /**
     * POSITIVE CONTROL for the above: the rows really exist and really are split
     * across two workspaces, so "2 and 1" cannot be a coincidence of an empty
     * table.
     */
    #[Test]
    public function the_digest_fixtures_really_do_span_two_workspaces(): void
    {
        [$a] = $this->workspaceWithOwner('Tenant A');
        [$b] = $this->workspaceWithOwner('Tenant B');

        $this->seedConversation($a->id);
        $this->seedConversation($a->id);
        $this->seedConversation($b->id);

        $this->assertSame(3, DB::table('conversations')->count());
        $this->assertSame(2, DB::table('conversations')->where('workspace_id', $a->id)->count());
    }

    // ── WhatsappWebhookRegisterCommand — cross-tenant ──────────────────────

    /**
     * Cross-tenant, and it must stay that way: it registers ONE platform-wide
     * Meta callback URL and subscribes every workspace's WABA to it. Scoped to
     * one tenant, every other tenant's inbound messages stop arriving and the
     * command still reports success.
     *
     * Driven END TO END — the command really runs, with Meta credentials seeded
     * and Meta's HTTP faked. An earlier version asserted the mechanism directly
     * instead, and the stash-check exposed it: removing crossTenant() from the
     * command failed only the coverage guard, never a behavioural test.
     */
    #[Test]
    public function the_whatsapp_webhook_registration_subscribes_wabas_in_every_workspace(): void
    {
        IntegrationConfig::updateOrCreate(
            ['provider' => 'meta_app'],
            ['label' => 'Meta', 'enabled' => true, 'credentials' => ['app_id' => '111', 'app_secret' => 'secret']]
        );

        DB::table('whatsapp_business_accounts')->insert([
            ['workspace_id' => 501, 'waba_id' => 'w-501', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => 502, 'waba_id' => 'w-502', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->simulateScopeOn(WhatsappBusinessAccount::class);

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        Artisan::call('whatsapp:register-webhook');
        $output = Artisan::output();

        $this->assertStringContainsString('Subscribing 2 WABA(s)', $output,
            'The command must find WABAs in EVERY workspace. "0 WABA(s)" here means it ran '
            .'unscoped under a fail-closed scope — every tenant but one silently unsubscribed, '
            .'and the command still exits 0.');

        // Both WABAs are iterated. They stop short of the subscribed_apps call
        // because neither has an access token and no system user token is
        // configured — which is the command's own behaviour, not the scope's.
        $this->assertStringContainsString('w-501', $output);
        $this->assertStringContainsString('w-502', $output,
            'The second workspace\'s WABA was never reached.');
    }

    /**
     * POSITIVE CONTROL. Proves the assertion above can fail: with NO context and
     * no cross-tenant declaration, the same query really does find nothing.
     */
    #[Test]
    public function an_unscoped_console_query_finds_nothing_under_a_fail_closed_scope(): void
    {
        DB::table('whatsapp_business_accounts')->insert([
            ['workspace_id' => 501, 'waba_id' => 'w-501', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->simulateScopeOn(WhatsappBusinessAccount::class);

        $this->assertSame(0, WhatsappBusinessAccount::count(),
            '"No context" is not the same as "cross-tenant" — this is the difference.');

        $this->assertSame(1, WorkspaceContext::crossTenant(
            'reason: proving the door works',
            fn () => WhatsappBusinessAccount::count()
        ));
    }

    // ── MessengerProfileTestCommand — both shapes ──────────────────────────

    #[Test]
    public function the_messenger_diagnostic_finds_an_account_in_any_workspace(): void
    {
        $this->simulateScopeOn(ChannelAccount::class);

        DB::table('channel_accounts')->insert([
            'workspace_id' => 601,
            'channel' => 'messenger',
            'display_name' => 'Page A',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('messenger:test-profile');
        $output = Artisan::output();

        $this->assertStringNotContainsString('No Messenger channel account found', $output,
            'The diagnostic could not find an account that exists. An operator does not know '
            .'which workspace a broken connection is in — discovering it is the point.');
        $this->assertStringContainsString('601', $output, 'It should report the workspace it found.');
        $this->assertSame(1, $exit, 'It still fails on the missing token — that is the diagnosis, not the bug.');
    }

    /** POSITIVE CONTROL: with no account at all, it really does say so. */
    #[Test]
    public function the_messenger_diagnostic_reports_when_there_is_genuinely_no_account(): void
    {
        $this->simulateScopeOn(ChannelAccount::class);

        Artisan::call('messenger:test-profile');

        $this->assertStringContainsString('No Messenger channel account found', Artisan::output());
    }

    // ── The simulation itself must be real ─────────────────────────────────

    /**
     * If `simulateScopeOn()` silently did nothing, every test above would pass
     * for the wrong reason. This proves the runtime scope actually filters.
     */
    #[Test]
    public function the_simulated_scope_is_genuinely_active(): void
    {
        [$a] = $this->workspaceWithOwner('Tenant A');
        $this->seedConversation($a->id);

        $this->assertSame(1, Conversation::count(), 'Unscoped baseline.');

        $this->simulateScopeOn(Conversation::class);

        $this->assertSame(0, Conversation::count(),
            'simulateScopeOn() did not apply the scope, so every other test in this file is vacuous.');
        $this->assertSame(1, WorkspaceContext::for($a->id, fn () => Conversation::count()));
    }
}
