<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smart QR slice 3 — admin assignment. R-1, R-8, R-11, R-13, R-14.
 *
 * ─── ⚠️ THE TWO ASSERTIONS THIS FILE EXISTS FOR ─────────────────────────────
 *
 * Everything else here is ordinary coverage. These two catch the wrong turns
 * that are easy to take and hard to see afterwards:
 *
 *   R-11  five codes into three slots leaves ZERO assigned, not three.
 *         A test asserting only "an error was returned" PASSES against the
 *         partial implementation — it errors too, after committing three rows.
 *
 *   R-14  a member whose PRIMARY workspace is different must be ACCEPTED.
 *         This is the only test that fails if membership is later
 *         re-implemented as `users.workspace_id`, which reads simpler and is
 *         wrong.
 */
class SmartQrAdminAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /** An admin holding exactly the given permission keys. */
    private function adminWith(array $keys): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-test-'.(implode('-', $keys) ?: 'none')],
            ['name' => 'QR Test Role', 'description' => 'test']
        );

        foreach ($keys as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'QR Management']
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /**
     * A workspace with an active WhatsApp channel and a plan limit.
     *
     * @return array{workspace: Workspace, channel: ChannelAccount, user: User}
     */
    private function tenant(?int $qrLimit = 50): array
    {
        ['workspace' => $workspace, 'client' => $client, 'user' => $user] = $this->createWorkspaceContext();

        if ($qrLimit !== null) {
            $this->attachPlanToClient($client, Plan::factory()->create([
                'limits' => ['smart_qr_max_assigned' => $qrLimit],
            ]));
        }

        $channel = ChannelAccount::withoutWorkspaceScope('reason: test fixture setup')->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Main Line',
            'phone_number_id' => 'PN-'.uniqid(),
            'status' => 'active',
        ]);

        return ['workspace' => $workspace, 'channel' => $channel, 'user' => $user];
    }

    /** @return list<int> */
    private function codes(int $n): array
    {
        return SmartQrCode::factory()->count($n)->create()->pluck('id')->all();
    }

    private function currentAssignments(Workspace $workspace): int
    {
        return (int) DB::table('smart_qr_assignments')
            ->where('workspace_id', $workspace->id)
            ->whereNull('unassigned_at')
            ->count();
    }

    private function payload(array $t, array $codeIds, array $extra = []): array
    {
        return array_merge([
            'code_ids' => $codeIds,
            'workspace_id' => $t['workspace']->id,
            'channel_account_id' => $t['channel']->id,
            'name' => 'Counter QR',
            'qr_type' => 'Counter',
        ], $extra);
    }

    // ══ ⚠️ R-11 — ALL OR NOTHING ═══════════════════════════════════════════

    /**
     * ⚠️ THE DISCRIMINATOR. Five codes, three slots, ZERO written.
     *
     * The partial implementation — a capacity check inside the loop — assigns
     * three, refuses the fourth, and returns an error having already committed
     * three rows. The admin reads "failed" while three codes are live, and
     * nobody finds out until a customer reports a QR that goes nowhere.
     *
     * So the assertion is the ROW COUNT, not the error. Asserting only that an
     * error came back passes against the partial implementation.
     */
    #[Test]
    public function five_codes_into_three_slots_assigns_zero_not_three(): void
    {
        $t = $this->tenant(qrLimit: 3);
        $admin = $this->adminWith(['assign_qr_codes']);

        $this->assertSame(0, $this->currentAssignments($t['workspace']), 'Precondition: empty.');

        $response = $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(5)));

        $response->assertSessionHasErrors('code_ids');

        // ⚠️ THE ASSERTION THAT DISCRIMINATES.
        $this->assertSame(0, $this->currentAssignments($t['workspace']),
            'Three of the five codes were assigned before the limit refused the fourth. The '
            .'admin was told the request failed while three QR codes are live — silent, and '
            .'discovered by a customer rather than by us. The capacity check must be made ONCE '
            .'for the whole request, before the loop, not per code inside it.');

        $this->assertSame(0, SmartQrAssignment::withoutWorkspaceScope('reason: assert across tenants')->count(),
            'Assignment rows exist for some other workspace — the rollback was partial.');
    }

    /** POSITIVE CONTROL: three into three succeeds, so the refusal is about capacity. */
    #[Test]
    public function three_codes_into_three_slots_all_land(): void
    {
        $t = $this->tenant(qrLimit: 3);
        $admin = $this->adminWith(['assign_qr_codes']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(3)))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $this->currentAssignments($t['workspace']),
            'A request that exactly fills the limit was refused. The check is off by one, or '
            .'the refusal above is not about capacity at all.');
    }

    /**
     * ⚠️ A mid-batch failure takes the WHOLE batch back out.
     *
     * Code 3 of 5 is already held by another workspace, so it is refused
     * mid-loop — after two rows have been written inside the transaction.
     */
    #[Test]
    public function a_failure_midway_through_a_batch_rolls_the_whole_batch_back(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $admin = $this->adminWith(['assign_qr_codes']);

        $ids = $this->codes(5);

        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $ids[2],
            'workspace_id' => $other['workspace']->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $ids))
            ->assertSessionHasErrors('code_ids');

        $this->assertSame(0, $this->currentAssignments($t['workspace']),
            'The first two codes stayed assigned after the third failed. Without one '
            .'transaction for the whole batch, a partial assignment survives a reported failure.');

        $this->assertSame(1, $this->currentAssignments($other['workspace']),
            'The other workspace lost its existing assignment — the rollback went too far.');
    }

    // ══ ⚠️ R-14 — MEMBERSHIP, NOT PRIMARY WORKSPACE ════════════════════════

    /**
     * ⚠️ THE ONLY TEST THAT CATCHES `users.workspace_id`.
     *
     * The user's `workspace_id` is `home`; they are a pivot member of `other`.
     * Assigning to `other` with that user must be ACCEPTED.
     *
     * If membership is ever re-implemented as `$user->workspace_id === $ws->id`
     * — which reads simpler and is what someone will reach for — this fails and
     * nothing else does. In production that mistake shows up as a valid user
     * missing from the picker with no explanation.
     */
    #[Test]
    public function a_member_whose_primary_workspace_is_different_is_accepted(): void
    {
        ['user' => $user, 'client' => $client, 'home' => $home, 'other' => $other] =
            $this->createTwoWorkspaceUser();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'limits' => ['smart_qr_max_assigned' => 50],
        ]));

        $channel = ChannelAccount::withoutWorkspaceScope('reason: test fixture setup')->create([
            'workspace_id' => $other->id,
            'channel' => 'whatsapp',
            'display_name' => 'Other Line',
            'phone_number_id' => 'PN-'.uniqid(),
            'status' => 'active',
        ]);

        // Precondition, stated so the test cannot silently stop being about this.
        $this->assertNotSame((int) $user->workspace_id, (int) $other->id,
            'Fixture broken: the user\'s primary workspace is the one being assigned to, so '
            .'this test could not distinguish membership from primary workspace.');
        $this->assertTrue($other->isAccessibleBy($user), 'Fixture: the user IS a member.');

        $admin = $this->adminWith(['assign_qr_codes']);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), [
                'code_ids' => $this->codes(1),
                'workspace_id' => $other->id,
                'channel_account_id' => $channel->id,
                'assigned_user_id' => $user->id,
                'name' => 'Reception QR',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->currentAssignments($other),
            'A legitimate member was refused because their PRIMARY workspace is a different '
            .'one. Membership is Workspace::isAccessibleBy() (R-14) — owner or pivot, filtered '
            .'by client_id — not the users.workspace_id column, which names one workspace out '
            .'of possibly several.');

        $this->assertSame(0, $this->currentAssignments($home), 'It landed on the wrong workspace.');
    }

    /** …and a user from another CLIENT entirely is still refused. */
    #[Test]
    public function a_user_from_another_client_is_refused(): void
    {
        $t = $this->tenant();
        $stranger = $this->tenant();
        $admin = $this->adminWith(['assign_qr_codes']);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1), [
                'assigned_user_id' => $stranger['user']->id,
            ]))
            ->assertSessionHasErrors('code_ids');

        $this->assertSame(0, $this->currentAssignments($t['workspace']));
    }

    // ══ Cross-tenant channel (§6) ══════════════════════════════════════════

    #[Test]
    public function a_channel_belonging_to_another_workspace_is_refused(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $admin = $this->adminWith(['assign_qr_codes']);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1), [
                'channel_account_id' => $other['channel']->id,
            ]))
            ->assertSessionHasErrors('code_ids');

        $this->assertSame(0, $this->currentAssignments($t['workspace']));
    }

    /**
     * ⚠️ POSITIVE CONTROL, and it is load-bearing.
     *
     * ChannelAccount is workspace-scoped and an admin request has no workspace
     * context, so a validator that forgot to drop the scope would reject EVERY
     * channel — including the correct one. The cross-tenant test above passes
     * either way, because "nobody's channel works" satisfies "their channel
     * does not work". This is the fail-CLOSED half.
     */
    #[Test]
    public function the_workspaces_own_channel_is_accepted(): void
    {
        $t = $this->tenant();
        $admin = $this->adminWith(['assign_qr_codes']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1)))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->currentAssignments($t['workspace']),
            'The workspace\'s OWN channel was refused. The workspace scope was left on the '
            .'channel lookup and failed closed with no admin context — the H-2 shape that made '
            .'the slice-1 canary return 0.');
    }

    /**
     * §6: "prevent assignment to inactive or disconnected WhatsApp channels".
     *
     * ⚠️ There is no `disconnected` in this codebase. `channel_accounts.status`
     * is `enum('active','inactive','error')` — measured, after the first version
     * of this test wrote 'disconnected' and MySQL truncated it to ''. Both
     * non-active values are asserted, because "not active" is the rule and
     * naming only one would leave the other untested.
     */
    #[Test]
    public function a_channel_that_is_not_active_is_refused(): void
    {
        $admin = $this->adminWith(['assign_qr_codes']);

        foreach (['inactive', 'error'] as $status) {
            $t = $this->tenant();
            $t['channel']->forceFill(['status' => $status])->save();

            $this->actingAs($admin, 'admin')
                ->from(route('admin.qr.assignments.index'))
                ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1)))
                ->assertSessionHasErrors('code_ids');

            $this->assertSame(0, $this->currentAssignments($t['workspace']),
                "A QR was pointed at a channel in state '{$status}' — a printed sticker that "
                .'goes nowhere.');
        }
    }

    // ══ ⚠️ R-8 — the override ══════════════════════════════════════════════

    #[Test]
    public function an_over_limit_assignment_is_refused_with_the_counts_in_the_message(): void
    {
        $t = $this->tenant(qrLimit: 2);
        $admin = $this->adminWith(['assign_qr_codes']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(2)));

        $response = $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1)));

        $response->assertSessionHasErrors('code_ids');

        $message = session('errors')->first('code_ids');
        $this->assertStringContainsString('2 of 2', $message,
            'The refusal did not name the numbers. An admin refused without being told the '
            .'counts cannot tell a limit from a bug (R-8).');
    }

    #[Test]
    public function the_override_requires_the_permission(): void
    {
        $t = $this->tenant(qrLimit: 0);
        $admin = $this->adminWith(['assign_qr_codes']);   // no override permission

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1), [
                'override_limit' => true,
                'override_reason' => 'Customer prepaid for extra codes on the phone.',
            ]))
            ->assertSessionHasErrors('override_limit');

        $this->assertSame(0, $this->currentAssignments($t['workspace']));
    }

    /** POSITIVE CONTROL: with the permission AND a reason, it lands and is logged. */
    #[Test]
    public function the_override_succeeds_with_the_permission_and_is_audit_logged(): void
    {
        $t = $this->tenant(qrLimit: 0);
        $admin = $this->adminWith(['assign_qr_codes', 'override_qr_assignment_limit']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1), [
                'override_limit' => true,
                'override_reason' => 'Customer prepaid for extra codes; invoice INV-4417.',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->currentAssignments($t['workspace']),
            'The override did not assign. A limit of 0 refuses everything (BUG-030 semantics: '
            .'0 is bounded at zero, null is unlimited), so this is the path the override exists '
            .'for.');

        $log = AuditLog::where('action', 'smart_qr.assigned_over_limit')->latest('id')->first();

        $this->assertNotNull($log, 'The over-limit assignment was not audit-logged.');
        $this->assertStringContainsString('INV-4417', json_encode($log->meta),
            'The reason was not recorded. R-8 forbids a nullable column for it, so the audit '
            .'log is the only place it lives — if it is not here it is nowhere.');
        $this->assertSame($admin->id, $log->actor_admin_id);
    }

    /**
     * ⚠️ The reason is REQUIRED AT THE SIGNATURE, not merely by the form.
     *
     * A FormRequest rule is bypassed by every caller that is not an HTTP
     * request — a queued job, a console command, a future bulk importer. So the
     * action itself refuses an empty reason, and this asserts that directly
     * rather than through the controller.
     */
    #[Test]
    public function the_action_refuses_an_empty_override_reason(): void
    {
        $t = $this->tenant(qrLimit: 0);
        $action = app(\App\Modules\SmartQr\Actions\AssignQrCodesAction::class);

        $this->expectException(\InvalidArgumentException::class);

        $action->assignOverridingLimit(
            $t['workspace'],
            $this->codes(1),
            ['channel_account_id' => $t['channel']->id],
            '   ',
        );
    }

    /** …and the HTTP layer refuses one too, so both gates are real. */
    #[Test]
    public function the_form_requires_a_reason_when_overriding(): void
    {
        $t = $this->tenant(qrLimit: 0);
        $admin = $this->adminWith(['assign_qr_codes', 'override_qr_assignment_limit']);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1), [
                'override_limit' => true,
            ]))
            ->assertSessionHasErrors('override_reason');
    }

    // ══ R-13 — the seeded limit makes the gate reachable ═══════════════════

    /**
     * ⚠️ R-13. A gate that cannot fire is not a gate.
     *
     * The seeder must carry a FINITE value on every tier, including Business
     * where every other limit is null. Without it the refusal path above is
     * dead in a real installation and only ever exercised by tests — which is
     * how BUG-024's nine unenforceable keys happened.
     */
    #[Test]
    public function every_seeded_plan_carries_a_finite_assignment_limit(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $plans = Plan::all();
        $this->assertGreaterThanOrEqual(3, $plans->count(), 'Precondition: the seeder ran.');

        foreach ($plans as $plan) {
            $limit = $plan->limits['smart_qr_max_assigned'] ?? null;

            $this->assertNotNull($limit,
                "Plan '{$plan->slug}' has no smart_qr_max_assigned limit, so R-8's refusal can "
                .'never fire for its customers. null means unlimited, not unset.');
            $this->assertSame(50, (int) $limit,
                "Plan '{$plan->slug}' does not carry the owner's ruled value of 50. R-13 sets "
                .'the same number on all three tiers deliberately: it is a business decision '
                .'about the Business Kit product, not a tier differentiator.');
        }
    }

    // ══ Reassignment (R-4) ═════════════════════════════════════════════════

    #[Test]
    public function unassigning_closes_the_period_and_frees_the_slot(): void
    {
        $t = $this->tenant(qrLimit: 1);
        $admin = $this->adminWith(['assign_qr_codes']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1)))
            ->assertSessionHasNoErrors();

        $assignment = SmartQrAssignment::withoutWorkspaceScope('reason: assert across tenants')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.qr.assignments.destroy', $assignment->uuid))
            ->assertSessionHasNoErrors();

        // ⚠️ The ROW SURVIVES. Deleting it would take the previous tenant's own
        // scan history with it — hiding it from the NEW tenant is the
        // requirement, deleting it is not.
        $this->assertDatabaseHas('smart_qr_assignments', ['id' => $assignment->id]);
        $this->assertNotNull($assignment->fresh()->unassigned_at, 'The period was not closed.');
        $this->assertSame(0, $this->currentAssignments($t['workspace']));

        // The slot is genuinely free: a limit of 1 accepts another.
        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1)))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->currentAssignments($t['workspace']),
            'The slot was not released. An unfiltered gauge counts ended assignments, so a '
            .'workspace that churns codes is permanently at its limit (R-7).');
    }

    #[Test]
    public function an_already_assigned_code_cannot_be_assigned_again(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $admin = $this->adminWith(['assign_qr_codes']);

        $ids = $this->codes(1);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $ids))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), $this->payload($other, $ids))
            ->assertSessionHasErrors('code_ids');

        $this->assertSame(0, $this->currentAssignments($other['workspace']),
            'One code is held by two tenants. A scan would redirect to whichever row was read '
            .'first — the BUG-019 shape.');
    }

    // ══ Permissions ════════════════════════════════════════════════════════

    #[Test]
    public function assigning_requires_the_assign_permission(): void
    {
        $t = $this->tenant();
        $admin = $this->adminWith(['view_qr_inventory']);   // read only

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1)))
            ->assertForbidden();

        $this->assertSame(0, $this->currentAssignments($t['workspace']),
            'Proof the 403 was the gate and not a late failure: nothing was written.');
    }

    /** POSITIVE CONTROL: same route, same verb, same admin type, with the permission. */
    #[Test]
    public function assigning_succeeds_with_the_assign_permission(): void
    {
        $t = $this->tenant();
        $admin = $this->adminWith(['assign_qr_codes']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.store'), $this->payload($t, $this->codes(1)))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->currentAssignments($t['workspace']),
            'The endpoint refuses everyone, so the 403 above proved nothing about the gate.');
    }
}
