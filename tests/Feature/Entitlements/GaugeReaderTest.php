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

    /**
     * A batch to hang generated codes off. Smart QR codes are platform
     * inventory, so this takes no workspace — that is the whole of R-4.
     */
    private function qrBatch(): int
    {
        return (int) DB::table('smart_qr_batches')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'batch_number' => 'AX-BK-'.Str::upper(Str::random(8)),
            'batch_name' => 'Gauge fixture',
            'prefix' => 'GA',
            'quantity' => 100,
            'serial_start' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * One assignment row, current unless `$unassignedAt` is given.
     *
     * ⚠️ Written with the query builder, not the model, so the workspace scope
     * plays no part in the FIXTURE. A fixture that depended on the thing under
     * test would make these counts unfalsifiable.
     */
    private function qrAssignment(Workspace $workspace, int $batchId, ?string $unassignedAt = null): void
    {
        $codeId = DB::table('smart_qr_codes')->insertGetId([
            'serial_number' => 'GA-'.Str::upper(Str::random(10)),
            'public_token' => bin2hex(random_bytes(16)),
            'batch_id' => $batchId,
            'status' => 'generated',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('smart_qr_assignments')->insert([
            'uuid' => (string) Str::uuid(),
            'smart_qr_code_id' => $codeId,
            'workspace_id' => $workspace->id,
            'status' => $unassignedAt === null ? 'active' : 'ended',
            'assigned_at' => now()->subDay(),
            'unassigned_at' => $unassignedAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Insert `$n` rows of a gauge's owning table for a workspace. */
    private function seedGauge(string $key, Workspace $workspace, int $n): void
    {
        if ($key === 'smart_qr_max_assigned') {
            $batchId = $this->qrBatch();

            for ($i = 0; $i < $n; $i++) {
                $this->qrAssignment($workspace, $batchId);
            }

            return;
        }

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

        // ⚠️ 9 -> 10: `smart_qr_max_assigned` joined in Smart QR slice 3. The
        // key is NAMED rather than the number bumped, so a future key arriving
        // by accident still fails this line instead of quietly making 11.
        $this->assertCount(10, $gauges, 'Expected 10 gauge keys in PlanLimitKinds.');
        $this->assertContains('smart_qr_max_assigned', $gauges,
            'The Smart QR assignment gauge lost its PlanLimitKinds entry. Without it the key '
            .'has no declared kind, and this test would stop checking it has a source at all.');

        foreach ($gauges as $key) {
            $this->assertTrue(
                isset(GaugeSources::MAP[$key]) || GaugeSources::isExcluded($key),
                "Gauge '{$key}' is neither sourced nor excluded — it would silently never be "
                .'enforced, which is exactly BUG-024.'
            );
        }

        // ⚠️ 7 -> 8, same reason, same naming.
        $this->assertCount(8, GaugeSources::MAP, '10 gauges minus storage and inbox_agents.');
        $this->assertArrayHasKey('smart_qr_max_assigned', GaugeSources::MAP);
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
     * ─── ⚠️ RE-POINTED IN SMART QR SLICE 3, AND NOW IT CAN ACTUALLY FAIL ────
     *
     * This test used to seed `chatbots`, and in that form it was DORMANT:
     * measured, removing the scope bypass from GaugeReader left the whole file
     * green, because none of the seven gauge models carried BelongsToWorkspace —
     * six sit in Phase 0's un-started slice 8 and User is NEVER_SCOPED. There
     * was no scope to fail closed, so the bypass branch never executed.
     *
     * `smart_qr_max_assigned` changed that. `SmartQrAssignment` uses
     * BelongsToWorkspace from birth, so it is the FIRST gauge where the bypass
     * is live — and re-pointing this test at it converts a test that could not
     * fail into one that does.
     *
     * The `chatbots` assertion is kept below as the control: it still cannot
     * fail today, and the contrast between the two is the point.
     */
    #[Test]
    public function gauges_count_correctly_with_no_workspace_context(): void
    {
        $a = $this->tenant()['workspace'];
        $this->seedGauge('chatbots', $a, 3);
        $this->seedGauge('smart_qr_max_assigned', $a, 4);

        WorkspaceContext::flush();
        $this->assertNull(WorkspaceContext::id(), 'Precondition: no workspace context.');

        // ⚠️ THE LIVE ONE. SmartQrAssignment is workspace-scoped, so without the
        // bypass the scope fails closed here and this returns 0.
        $this->assertSame(4, $this->reader()->count('smart_qr_max_assigned', $a),
            'The Smart QR gauge returned 0 with no workspace context — the scope failed closed '
            .'and the bypass in GaugeReader is gone. 0 reads as "nothing held", which grants '
            .'the FULL limit as headroom: the fail-OPEN direction, and the one that costs '
            .'money. This is the first gauge where that bypass is load-bearing.');

        // The control, still dormant: no scope exists on AiChatbot to fail.
        $this->assertSame(3, $this->reader()->count('chatbots', $a),
            'The gauge returned 0 with no context. A gauge that under-counts grants unlimited '
            .'headroom — the fail-OPEN direction, and the one that costs money.');
    }

    /**
     * ⚠️ TRIPWIRE — NARROWED IN SMART QR SLICE 3, NOT DELETED.
     *
     * It originally asserted that NO gauge model was workspace-scoped, and said
     * to delete it when one became so. Slice 3 made one so — `SmartQrAssignment`
     * carries BelongsToWorkspace from birth — but deleting on that signal would
     * have been wrong, for a reason the original could not have anticipated.
     *
     * The tripwire fired for the wrong event. It was written for Phase 0 slice 8
     * CONVERTING an existing gauge model; what actually happened is a NEW model
     * arriving already scoped. Those are different, and the difference matters:
     * the seven below are still unscoped, so this test still records a live
     * MEASUREMENT — that for those seven, removing GaugeReader's bypass changes
     * nothing and no test notices.
     *
     * Deleting it would have erased that measurement while leaving the seven
     * exactly as unprotected as before.
     *
     * So: narrowed to the still-unscoped seven. It fails the day slice 8 scopes
     * any of them, which is still the intended signal — go and confirm that
     * `gauges_count_correctly_with_no_workspace_context` covers the newly-scoped
     * model too, then remove it from this list.
     *
     * `smart_qr_max_assigned` is deliberately EXCLUDED: it is the one gauge
     * where the bypass is already load-bearing, and the test above now proves it.
     */
    #[Test]
    public function the_bypass_is_still_dormant_for_the_seven_unscoped_gauges(): void
    {
        $expectedUnscoped = [
            'users', 'whatsapp_accounts', 'whatsapp_templates',
            'knowledge_bases', 'chatbots', 'social_accounts', 'automations',
        ];

        // Control: the list has not silently drifted out of MAP.
        foreach ($expectedUnscoped as $key) {
            $this->assertArrayHasKey($key, GaugeSources::MAP,
                "Gauge '{$key}' left GaugeSources::MAP, so this tripwire is watching a key that "
                .'no longer exists and would pass however the remaining gauges are scoped.');
        }

        $scoped = [];

        foreach ($expectedUnscoped as $key) {
            if (method_exists(GaugeSources::MAP[$key]['model'], 'scopeWithoutWorkspaceScope')) {
                $scoped[] = $key;
            }
        }

        $this->assertSame([], $scoped,
            'These gauge models are now workspace-scoped: '.implode(', ', $scoped).". \n"
            ."GaugeReader's scope bypass has just become load-bearing for them. Confirm that\n"
            ."gauges_count_correctly_with_no_workspace_context covers the newly-scoped model —\n"
            .'it currently proves the bypass only through smart_qr_max_assigned — then drop the '
            ."key from this list.\n");

        // ⚠️ THE POSITIVE HALF. Without this, the assertion above would pass if
        // `method_exists` were broken or the trait renamed, and the narrowing
        // would look like a measurement while proving nothing.
        $this->assertTrue(
            method_exists(GaugeSources::MAP['smart_qr_max_assigned']['model'], 'scopeWithoutWorkspaceScope'),
            'SmartQrAssignment is no longer workspace-scoped. It is the one gauge model that IS, '
            .'and this check is what proves the detection above can distinguish the two.'
        );
    }

    // ══ ⚠️ R-7 — THE FILTERED GAUGE ════════════════════════════════════════

    /**
     * ⚠️ THE DISCRIMINATOR. Filtered returns 2; unfiltered returns 5.
     *
     * `smart_qr_assignments` keeps history — a reassignment sets `unassigned_at`
     * and leaves the row, because R-4 needs the old period to survive. So an
     * unfiltered count returns every assignment the workspace has EVER held.
     *
     * A workspace holding 2 codes with 3 previously reassigned away would read
     * 5 used / 0 current: at its limit while owning nothing. And because the
     * count only grows, a workspace that churns codes is permanently locked out.
     *
     * N=2 and M=3 are chosen so the two implementations return DISTINCT numbers.
     * Equal counts would let the wrong one pass by coincidence.
     */
    #[Test]
    public function the_assignment_gauge_counts_only_current_assignments(): void
    {
        $ws = $this->tenant()['workspace'];
        $batch = $this->qrBatch();

        for ($i = 0; $i < 2; $i++) {
            $this->qrAssignment($ws, $batch);                                  // current
        }

        for ($i = 0; $i < 3; $i++) {
            $this->qrAssignment($ws, $batch, now()->subHour()->toDateTimeString());  // ended
        }

        $this->assertSame(5, (int) DB::table('smart_qr_assignments')->where('workspace_id', $ws->id)->count(),
            'Precondition: 5 assignment rows exist, 2 of them current.');

        $this->assertSame(2, $this->reader()->count('smart_qr_max_assigned', $ws),
            'The assignment gauge returned the workspace\'s ENTIRE assignment history rather '
            .'than its current holdings. 5 means the unassigned_at filter is missing: the count '
            .'is then monotonic, so a workspace that reassigns codes is permanently at its '
            .'limit while owning nothing.');
    }

    /** …and it releases when a code is reassigned away, which a counter could not. */
    #[Test]
    public function the_assignment_gauge_releases_on_reassignment(): void
    {
        $ws = $this->tenant()['workspace'];
        $batch = $this->qrBatch();

        $this->qrAssignment($ws, $batch);
        $this->qrAssignment($ws, $batch);

        $this->assertSame(2, $this->reader()->count('smart_qr_max_assigned', $ws));

        DB::table('smart_qr_assignments')
            ->where('workspace_id', $ws->id)
            ->limit(1)
            ->update(['unassigned_at' => now(), 'status' => 'ended']);

        $this->assertSame(1, $this->reader()->count('smart_qr_max_assigned', $ws),
            'Ending an assignment did not free the slot. A gauge must go DOWN, which is why '
            .'this cannot be a usage_meter (BUG-024).');
    }

    /** The filtered gauge is still a tenant boundary, not just a filter. */
    #[Test]
    public function the_assignment_gauge_ignores_another_workspaces_assignments(): void
    {
        $a = $this->tenant()['workspace'];
        $b = $this->tenant()['workspace'];
        $batch = $this->qrBatch();

        $this->qrAssignment($a, $batch);
        $this->qrAssignment($b, $batch);
        $this->qrAssignment($b, $batch);

        $this->assertSame(1, $this->reader()->count('smart_qr_max_assigned', $a),
            'The assignment gauge counted another workspace\'s codes.');
        $this->assertSame(2, $this->reader()->count('smart_qr_max_assigned', $b));
    }

    /**
     * ⚠️ THE UNMOVED-SEVEN TEST, AND IT DISCRIMINATES.
     *
     * "The seven are unchanged" passes trivially against untouched code and
     * proves nothing — the vacuous shape R-7 condition 2 exists to forbid. So
     * this adds a `where` to one of the seven, proves its count CHANGES, removes
     * it, and proves it RETURNS.
     *
     * `GaugeSources::MAP` is a const on a final class and cannot be mutated,
     * which is why `GaugeReader::sourceFor()` exists as a seam. Overriding it
     * here is the only way this test can fail for the right reason.
     */
    #[Test]
    public function the_where_branch_is_inert_for_the_seven_but_would_not_be_if_they_declared_one(): void
    {
        $ws = $this->tenant()['workspace'];
        $this->seedGauge('chatbots', $ws, 3);

        // Two of the three are given a name the filter will exclude.
        DB::table('ai_chatbots')->where('workspace_id', $ws->id)->limit(2)
            ->update(['name' => 'FILTERED-OUT']);

        // 1. Absent `where` — the branch does not run.
        $this->assertSame(3, $this->reader()->count('chatbots', $ws),
            'Baseline: with no `where` declared, all three rows count.');

        // 2. ⚠️ A `where` IS declared. If the branch were dead, this would still
        //    return 3 and the inertness claim would be unfalsifiable.
        $filtered = new class extends GaugeReader
        {
            protected function sourceFor(string $key): ?array
            {
                $source = GaugeSources::for($key);

                if ($key === 'chatbots' && $source !== null) {
                    $source['where'] = ['name' => 'FILTERED-OUT'];
                }

                return $source;
            }
        };

        $this->assertSame(2, $filtered->count('chatbots', $ws),
            'Declaring a `where` on chatbots did not change its count. The filter branch in '
            .'GaugeReader is dead code, so "the seven are unaffected" is true for the wrong '
            .'reason and proves nothing about them.');

        // 3. Removed again — the count returns. Proves step 2 changed the
        //    declaration and not the rows.
        $this->assertSame(3, $this->reader()->count('chatbots', $ws),
            'The count did not return to 3 after the filter was removed, so step 2 mutated the '
            .'data rather than the declaration and this test measured the wrong thing.');
    }

    /**
     * ⚠️ An explicitly-NULL filter must NOT fire the branch.
     *
     * R-7 condition 1: guard on the key's ABSENCE with `isset()`, never on
     * truthiness. `array_key_exists` would treat `'where' => null` as a declared
     * filter and iterate null — the same conflation of absent and null that
     * BUG-030 is about.
     */
    #[Test]
    public function an_explicitly_null_where_is_treated_as_no_filter(): void
    {
        $ws = $this->tenant()['workspace'];
        $this->seedGauge('chatbots', $ws, 3);

        $nullFilter = new class extends GaugeReader
        {
            protected function sourceFor(string $key): ?array
            {
                $source = GaugeSources::for($key);

                if ($key === 'chatbots' && $source !== null) {
                    $source['where'] = null;
                }

                return $source;
            }
        };

        $this->assertSame(3, $nullFilter->count('chatbots', $ws),
            "A 'where' => null fired the filter branch. Absent and null are different: the "
            .'guard must be isset(), not array_key_exists() and not truthiness.');
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
