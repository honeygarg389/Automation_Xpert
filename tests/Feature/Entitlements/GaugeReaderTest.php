<?php

namespace Tests\Feature\Entitlements;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Entitlements\Services\GaugeReader;
use App\Modules\Entitlements\Support\Entitlement;
use App\Modules\Entitlements\Support\Entitlements;
use App\Modules\Entitlements\Support\GaugeSources;
use App\Modules\Entitlements\Support\PlanLimitKinds;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE TESTS MUST PROVE THE TENANT BOUNDARY, NOT JUST THAT A COUNT HAPPENS.
 *
 * `users` and `inbox_agents` are the same table with different boundaries, and
 * `social_accounts` names one of two classes with the same basename. Getting
 * either wrong is a tenant boundary error wearing the costume of a counting
 * error: the number looks plausible and belongs to somebody else.
 *
 * So every gauge here is asserted with a SECOND tenant holding rows of the same
 * kind. A count that ignores the boundary returns the combined figure and passes
 * any test that only checks "did we count something".
 */
class GaugeReaderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{workspace: Workspace, client: Client} */
    private function tenant(?array $limits = null): array
    {
        $client = Client::factory()->create();

        if ($limits !== null) {
            $plan = Plan::factory()->create(['limits' => $limits]);
            ClientSubscription::create([
                'client_id' => $client->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
                'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'active',
            ]);
        }

        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return ['workspace' => $workspace->refresh(), 'client' => $client];
    }

    private function reader(): GaugeReader
    {
        return app(GaugeReader::class);
    }

    /** Insert `$n` rows of a gauge's owning table for a workspace. */
    private function seedGauge(string $key, Workspace $workspace, int $n): void
    {
        $table = (new (GaugeSources::MAP[$key]['model']))->getTable();

        for ($i = 0; $i < $n; $i++) {
            $row = ['workspace_id' => $workspace->id, 'created_at' => now(), 'updated_at' => now()];

            // Columns read from the schema, not guessed — the first version of
            // this helper invented three that do not exist.
            $row += match ($key) {
                'whatsapp_accounts' => ['waba_id' => 'WABA-'.Str::random(10)],
                'whatsapp_templates' => ['waba_id' => 'WABA-'.Str::random(10), 'name' => 'tpl_'.Str::random(8)],
                'knowledge_bases' => ['uuid' => (string) Str::uuid(), 'name' => 'KB '.$i],
                'chatbots' => ['uuid' => (string) Str::uuid(), 'name' => 'Bot '.$i],
                'social_accounts' => [
                    'network' => 'facebook',
                    'account_id' => Str::random(10),
                    'access_token' => 'tok-'.Str::random(8),
                ],
                'automations' => ['uuid' => (string) Str::uuid(), 'name' => 'Auto '.$i],
                default => [],
            };

            DB::table($table)->insert($row);
        }
    }

    // ══ The declaration itself ═════════════════════════════════════════════

    /**
     * ⚠️ Every gauge in PlanLimitKinds is either sourced or explicitly excluded.
     *
     * Absence is how a key stays inert, so absence must be a decision. Without
     * this, a gauge could drop out of MAP and simply never be enforced, which is
     * indistinguishable from the bug BUG-024 already describes.
     */
    #[Test]
    public function every_gauge_is_either_sourced_or_explicitly_excluded(): void
    {
        $gauges = PlanLimitKinds::keysOfKind('gauge');

        $this->assertCount(9, $gauges, 'Expected 9 gauge keys in PlanLimitKinds.');

        foreach ($gauges as $key) {
            $this->assertTrue(
                isset(GaugeSources::MAP[$key]) || GaugeSources::isExcluded($key),
                "Gauge '{$key}' is neither sourced nor excluded — it would silently never be "
                .'enforced, which is exactly BUG-024.'
            );
        }

        $this->assertCount(7, GaugeSources::MAP, '9 gauges minus storage and inbox_agents.');
        $this->assertArrayHasKey('storage', GaugeSources::EXCLUDED);
        $this->assertArrayHasKey('inbox_agents', GaugeSources::EXCLUDED);
    }

    /**
     * ⚠️ THE NAME COLLISION. Asserted on the TABLE, not the class name — the
     * class basename is identical for both candidates, so asserting it would
     * pass for the wrong one.
     */
    #[Test]
    public function social_accounts_resolves_to_the_publishing_table_not_the_oauth_one(): void
    {
        $model = new (GaugeSources::MAP['social_accounts']['model']);

        $this->assertSame('social_media_accounts', $model->getTable(),
            'The social_accounts gauge resolved to the OAuth login table. Two models share the '
            .'basename SocialAccount; the limit means the publishing one.');

        $this->assertSame('social_accounts', (new SocialAccount)->getTable(),
            'Control: the other SocialAccount still owns social_accounts, so the two really are '
            .'distinct and the assertion above is meaningful.');
    }

    // ══ Scope: workspace-scoped gauges ═════════════════════════════════════

    /**
     * Each workspace-scoped gauge, with a SECOND workspace holding rows of the
     * same kind. A boundary-blind count returns the combined figure.
     */
    #[Test]
    public function workspace_scoped_gauges_ignore_another_workspaces_rows(): void
    {
        $a = $this->tenant()['workspace'];
        $b = $this->tenant()['workspace'];

        $keys = ['whatsapp_accounts', 'whatsapp_templates', 'knowledge_bases', 'chatbots', 'social_accounts', 'automations'];

        foreach ($keys as $key) {
            $this->seedGauge($key, $a, 2);
            $this->seedGauge($key, $b, 5);
        }

        foreach ($keys as $key) {
            $this->assertSame(2, $this->reader()->count($key, $a),
                "Gauge '{$key}' counted another workspace's rows. 7 means the boundary was "
                .'ignored — a tenant error, not a counting error.');
            $this->assertSame(5, $this->reader()->count($key, $b), "Gauge '{$key}' miscounted workspace B.");
        }
    }

    /**
     * ⚠️ Counts must be correct with NO workspace context — the fail-open shape.
     *
     * Under the scope a contextless count returns 0, and 0 reads as "nothing
     * held", which hands the customer their FULL limit as headroom. That is how
     * ContactCapacity failed.
     *
     * ─── ⚠️ THIS TEST CANNOT CURRENTLY FAIL, AND THAT IS WORTH KNOWING ──────
     *
     * Measured: removing the scope bypass from GaugeReader leaves this file
     * entirely green. NONE of the seven gauge models carries BelongsToWorkspace
     * yet — six sit in Phase 0's un-started slice 8 and User is NEVER_SCOPED —
     * so the bypass branch never executes and there is no scope to fail closed.
     *
     * The bypass is therefore written for a future that has not arrived. It is
     * kept because adding it later, once slice 8 has scoped these models, means
     * a window in which every gauge silently under-counts and grants full
     * headroom — and the guard test would be written after the damage.
     *
     * `the_bypass_becomes_load_bearing_when_slice_8_scopes_a_gauge_model` below
     * is the tripwire: it fails the moment any gauge model gains the trait,
     * forcing whoever does that to re-verify this test can actually fail.
     */
    #[Test]
    public function gauges_count_correctly_with_no_workspace_context(): void
    {
        $a = $this->tenant()['workspace'];
        $this->seedGauge('chatbots', $a, 3);

        WorkspaceContext::flush();
        $this->assertNull(WorkspaceContext::id(), 'Precondition: no workspace context.');

        $this->assertSame(3, $this->reader()->count('chatbots', $a),
            'The gauge returned 0 with no context. A gauge that under-counts grants unlimited '
            .'headroom — the fail-OPEN direction, and the one that costs money.');
    }

    /**
     * ⚠️ TRIPWIRE for the test above, which is dormant until slice 8.
     *
     * The fail-open guard in GaugeReader only does anything once a gauge model
     * is workspace-scoped. Today none is, so removing the guard changes nothing
     * and no test notices — measured, not assumed.
     *
     * This asserts that state explicitly. When Phase 0 slice 8 scopes any of
     * these models this test FAILS, which is the intended behaviour: it is the
     * signal to confirm the bypass is live and that
     * `gauges_count_correctly_with_no_workspace_context` can now genuinely fail.
     *
     * Delete this test at that point — after checking, not instead of checking.
     */
    #[Test]
    public function the_bypass_becomes_load_bearing_when_slice_8_scopes_a_gauge_model(): void
    {
        $scoped = [];

        foreach (GaugeSources::MAP as $key => $source) {
            if (method_exists($source['model'], 'scopeWithoutWorkspaceScope')) {
                $scoped[] = $key;
            }
        }

        $this->assertSame([], $scoped,
            'These gauge models are now workspace-scoped: '.implode(', ', $scoped).". \n"
            ."GaugeReader's scope bypass has just become load-bearing. Confirm that\n"
            ."gauges_count_correctly_with_no_workspace_context can now actually FAIL when the\n"
            .'bypass is removed — until slice 8 it could not — and then delete this tripwire.');
    }

    // ══ Scope: the CLIENT-scoped gauge ═════════════════════════════════════

    /**
     * ⚠️ `users` counts by client_id, and this is the assertion that proves it.
     *
     * A client with two workspaces and four users holds FOUR seats, not four per
     * workspace. A workspace-scoped count would return 2 here and let the client
     * buy twice the seats they paid for.
     */
    #[Test]
    public function the_users_gauge_counts_the_whole_client_not_one_workspace(): void
    {
        $t = $this->tenant();
        $client = $t['client'];
        $first = $t['workspace'];

        $second = Workspace::factory()->create(['owner_id' => $first->owner_id, 'client_id' => $client->id]);

        // 1 owner already exists on $first; add one more there and two on $second.
        User::factory()->create(['role' => 'client', 'client_id' => $client->id, 'workspace_id' => $first->id]);
        User::factory()->count(2)->create(['role' => 'client', 'client_id' => $client->id, 'workspace_id' => $second->id]);

        $expected = (int) DB::table('users')->where('client_id', $client->id)->count();
        $this->assertSame(4, $expected, 'Precondition: 4 users across 2 workspaces.');

        $this->assertSame(4, $this->reader()->count('users', $first),
            'The users gauge returned a per-workspace figure. Seats belong to the organisation '
            .'holding the plan; counting per workspace multiplies them by the workspace count.');
        $this->assertSame(4, $this->reader()->count('users', $second),
            'Both workspaces of one client must report the same seat count.');
    }

    /** …and it must not count another client's users. */
    #[Test]
    public function the_users_gauge_ignores_another_clients_users(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        User::factory()->count(3)->create(['role' => 'client', 'client_id' => $b['client']->id]);

        $this->assertSame(1, $this->reader()->count('users', $a['workspace']),
            'The users gauge counted another client’s users — a cross-tenant seat count.');
    }

    // ══ Binds AND releases ═════════════════════════════════════════════════

    /**
     * A gauge must go DOWN when a row is deleted. That is the whole reason it
     * cannot be a usage_meter: a counter never decreases within a period, so a
     * customer who deleted a chatbot would never get the slot back.
     */
    #[Test]
    public function a_gauge_releases_when_a_row_is_deleted(): void
    {
        $ws = $this->tenant()['workspace'];
        $this->seedGauge('chatbots', $ws, 3);

        $this->assertSame(3, $this->reader()->count('chatbots', $ws));

        DB::table('ai_chatbots')->where('workspace_id', $ws->id)->limit(1)->delete();

        $this->assertSame(2, $this->reader()->count('chatbots', $ws),
            'The gauge did not release on delete. If this ever fails, the model has started '
            .'soft-deleting and the count must exclude trashed rows.');
    }

    /** No gauge model soft-deletes today — asserted, because it is load-bearing. */
    #[Test]
    public function no_gauge_model_soft_deletes(): void
    {
        foreach (GaugeSources::MAP as $key => $source) {
            $this->assertNotContains(
                SoftDeletes::class,
                class_uses_recursive($source['model']),
                "Gauge '{$key}' now soft-deletes, so COUNT(*) includes trashed rows and deleting "
                .'one never frees the slot. The count must add whereNull(deleted_at).'
            );
        }
    }

    #[Test]
    public function an_unknown_or_excluded_key_has_no_count(): void
    {
        $ws = $this->tenant()['workspace'];

        $this->assertNull($this->reader()->count('storage', $ws), 'storage is excluded.');
        $this->assertNull($this->reader()->count('inbox_agents', $ws), 'inbox_agents is excluded.');
        $this->assertNull($this->reader()->count('not_a_key', $ws));
        $this->assertNull($this->reader()->count('campaigns_per_month', $ws), 'A counter is not a gauge.');
    }

    // ══ ⚠️ 0 vs null — pinned, NOT changed ═════════════════════════════════

    /**
     * ⚠️ MEASURED SEMANTICS, ASSERTED SO THEY CANNOT BE INVERTED.
     *
     * There is a persistent belief that `0` means unlimited. It does not, and it
     * never has:
     *
     *     limit 0     -> bounded at zero -> the first item is REFUSED
     *     limit null  -> no ceiling      -> unlimited
     *
     * A plan intending "no chatbots" that sets 0 gets exactly that. Inverting
     * this to make 0 mean unlimited would CREATE the hole it is imagined to
     * close: every plan with a deliberate zero would start granting everything.
     *
     * These assertions exist so that the next person who believes the inversion
     * has to delete a test that states the truth, rather than quietly changing a
     * comparison.
     */
    #[Test]
    public function a_zero_limit_bounds_at_zero_and_null_means_unlimited(): void
    {
        $zero = new Entitlement(['chatbots' => 0]);
        $unlimited = new Entitlement(['chatbots' => null]);
        $absent = new Entitlement([]);

        $this->assertSame(0, $zero->limit('chatbots'), 'A zero limit must survive as 0, not become null.');
        $this->assertTrue($zero->has('chatbots'), 'Zero is a granted-but-bounded limit.');
        $this->assertFalse($zero->isUnlimited('chatbots'),
            'A zero limit reported itself as unlimited. That inversion would turn every '
            .'deliberate "none of this feature" into "all of it".');

        $this->assertNull($unlimited->limit('chatbots'));
        $this->assertTrue($unlimited->isUnlimited('chatbots'));

        // The third state, and why has() exists at all.
        $this->assertNull($absent->limit('chatbots'));
        $this->assertFalse($absent->has('chatbots'), 'Absent is not granted.');
        $this->assertFalse($absent->isUnlimited('chatbots'), 'Absence is not a grant of unlimited.');
    }

    /** The gauge decision itself: zero refuses the first item, null allows any. */
    #[Test]
    public function a_zero_gauge_limit_refuses_the_very_first_item(): void
    {
        $ws = $this->tenant(['chatbots' => 0])['workspace'];

        $limit = app(Entitlements::class)
            ->limitForWorkspace($ws->id, 'chatbots');

        $this->assertSame(0, $limit);
        $this->assertSame(0, $this->reader()->count('chatbots', $ws));
        $this->assertTrue($limit <= 0,
            'With a limit of 0 and nothing held, a gauge check must still refuse — that is what '
            .'"this plan includes no chatbots" means.');
    }

    #[Test]
    public function a_null_gauge_limit_allows_any_number(): void
    {
        $ws = $this->tenant(['chatbots' => null])['workspace'];
        $this->seedGauge('chatbots', $ws, 50);

        $limit = app(Entitlements::class)
            ->limitForWorkspace($ws->id, 'chatbots');

        $this->assertNull($limit, 'null must remain unlimited.');
        $this->assertSame(50, $this->reader()->count('chatbots', $ws));
    }
}
