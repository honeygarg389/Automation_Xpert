<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Http\Requests\UpdateCustomerQrRequest;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin lock on a tenant's active/inactive toggle. §11.
 *
 * ─── ⚠️ WHAT THIS FILE PINS ─────────────────────────────────────────────────
 *
 *   the write     locking sets all four columns AND forces status=inactive, in
 *                 one update — a locked code that kept serving would satisfy the
 *                 letter of the feature and defeat its purpose
 *   the unlock    returns CONTROL, not state: it must NOT re-activate
 *   the guard     enforced SERVER-SIDE on the customer route, with the specific
 *                 reason in the message — not a bare 403
 *   the scope     a locked tenant can still edit name/type/message
 *   the exclusion admin_locked cannot be mass-assigned through the customer path
 *
 * ⚠️ THE MASS-ASSIGNMENT TEST POSTS THE FIELD. Asserting that a column is absent
 * from $fillable proves the list, not the behaviour — and the behaviour is what a
 * tenant would exploit. It goes through the real route with the real payload.
 */
class SmartQrAssignmentLockTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $permissions */
    private function admin(array $permissions = ['lock_qr_assignments', 'assign_qr_codes', 'view_qr_inventory']): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-lock-test-'.md5(implode(',', $permissions))],
            ['name' => 'QR Lock Test', 'description' => 'test']
        );

        foreach ($permissions as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => $key, 'category' => 'QR Management']
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /**
     * A tenant with an active assignment and a client administrator who can
     * reach the customer edit route.
     *
     * @return array{assignment: SmartQrAssignment, code: SmartQrCode, user: User}
     */
    private function tenant(): array
    {
        ['workspace' => $workspace, 'client' => $client, 'user' => $user] = $this->createWorkspaceContext();

        $user->forceFill(['client_role' => User::CLIENT_ROLE_ADMINISTRATOR])->save();
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['smart_qr_max_assigned' => 50]]));

        $channel = ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $workspace->id, 'channel' => 'whatsapp',
            'display_name' => 'Main Line', 'phone_number_id' => 'PN-'.uniqid(), 'status' => 'active',
        ]);

        $code = SmartQrCode::factory()->create();

        $assignment = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channel->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
            'name' => 'Front counter',
            'qr_type' => 'table-tent',
        ]);

        return compact('assignment', 'code', 'user');
    }

    /**
     * The payload the customer edit form posts — every field, every time.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function customerPayload(SmartQrAssignment $a, array $over = []): array
    {
        return array_merge([
            'name' => $a->name,
            'qr_type' => $a->qr_type,
            'default_message' => $a->default_message,
            'status' => $a->status,
        ], $over);
    }

    // ══ Lock ═══════════════════════════════════════════════════════════════

    /**
     * ⚠️ ALL FOUR COLUMNS AND THE STATUS, TOGETHER. Locking an ACTIVE code and
     * leaving it active would freeze the toggle while the code kept serving —
     * the letter of the feature, the opposite of its purpose.
     */
    #[Test]
    public function locking_sets_every_column_and_forces_the_code_inactive(): void
    {
        ['assignment' => $a] = $this->tenant();
        $admin = $this->admin();

        $this->assertSame(SmartQrStatus::ASSIGNMENT_ACTIVE, $a->status, 'Fixture must start active.');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Chargeback under review'])
            ->assertSessionHasNoErrors();

        $fresh = $a->fresh();
        $this->assertTrue($fresh->admin_locked);
        $this->assertSame(SmartQrStatus::ASSIGNMENT_INACTIVE, $fresh->status, 'A locked code must not keep serving.');
        $this->assertSame('Chargeback under review', $fresh->lock_reason);
        $this->assertSame($admin->id, $fresh->locked_by_admin_id);
        $this->assertNotNull($fresh->locked_at);
    }

    #[Test]
    public function locking_requires_a_reason(): void
    {
        ['assignment' => $a] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), [])
            ->assertSessionHasErrors('lock_reason');

        $this->assertFalse($a->fresh()->admin_locked, 'A rejected lock must not have taken effect.');
    }

    #[Test]
    public function locking_is_audit_logged(): void
    {
        ['assignment' => $a] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Abuse report filed']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'smart_qr.assignment_locked',
            'auditable_type' => SmartQrAssignment::class,
            'auditable_id' => $a->id,
        ]);
    }

    // ══ Unlock ═════════════════════════════════════════════════════════════

    /**
     * ⚠️ RETURNS CONTROL, NOT STATE. Re-activating here would publish a live
     * destination on the customer's behalf — their decision, not the platform's.
     */
    #[Test]
    public function unlocking_clears_the_lock_without_reactivating(): void
    {
        ['assignment' => $a] = $this->tenant();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Payment dispute']);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.qr.assignments.unlock', $a->uuid))
            ->assertSessionHasNoErrors();

        $fresh = $a->fresh();
        $this->assertFalse($fresh->admin_locked);
        $this->assertNull($fresh->lock_reason);
        $this->assertNull($fresh->locked_by_admin_id);
        $this->assertNull($fresh->locked_at);
        $this->assertSame(SmartQrStatus::ASSIGNMENT_INACTIVE, $fresh->status,
            'Unlocking must hand back the toggle, not flip it.');
    }

    #[Test]
    public function unlocking_is_audit_logged(): void
    {
        ['assignment' => $a] = $this->tenant();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Under review']);
        $this->actingAs($admin, 'admin')->delete(route('admin.qr.assignments.unlock', $a->uuid));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'smart_qr.assignment_unlocked',
            'auditable_id' => $a->id,
        ]);
    }

    // ══ Customer enforcement ═══════════════════════════════════════════════

    /**
     * ⚠️ THE ENFORCEMENT, exercised through the real route with the UI bypassed.
     * A disabled <Select> is a courtesy; this is what actually holds.
     */
    #[Test]
    public function a_locked_customer_cannot_change_the_status(): void
    {
        ['assignment' => $a, 'code' => $code, 'user' => $user] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Chargeback under review']);

        $this->actingAs($user)
            ->patch(route('client.smartqr.codes.update', $code->serial_number),
                $this->customerPayload($a->fresh(), ['status' => SmartQrStatus::ASSIGNMENT_ACTIVE]))
            ->assertSessionHasErrors('status');

        $this->assertSame(SmartQrStatus::ASSIGNMENT_INACTIVE, $a->fresh()->status,
            'The tenant re-activated a locked code.');
    }

    /** ⚠️ The message must carry the REASON — a bare 403 reads as a broken page. */
    #[Test]
    public function the_refusal_names_the_reason(): void
    {
        ['assignment' => $a, 'code' => $code, 'user' => $user] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Chargeback under review']);

        $response = $this->actingAs($user)
            ->patch(route('client.smartqr.codes.update', $code->serial_number),
                $this->customerPayload($a->fresh(), ['status' => SmartQrStatus::ASSIGNMENT_ACTIVE]));

        $response->assertStatus(302);
        $errors = session('errors')->get('status');

        $this->assertStringContainsString('locked by the platform team', $errors[0]);
        $this->assertStringContainsString('Chargeback under review', $errors[0]);
        $this->assertStringContainsString('Contact support', $errors[0]);
    }

    /**
     * ⚠️ THE LOCK IS ABOUT THE TOGGLE AND NOTHING ELSE. Refusing the whole form
     * would take away edits the admin never intended to freeze.
     */
    #[Test]
    public function a_locked_customer_can_still_edit_the_other_fields(): void
    {
        ['assignment' => $a, 'code' => $code, 'user' => $user] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Under review']);

        $this->actingAs($user)
            ->patch(route('client.smartqr.codes.update', $code->serial_number),
                $this->customerPayload($a->fresh(), [
                    'name' => 'Renamed while locked',
                    'qr_type' => 'poster',
                    'default_message' => 'New message',
                ]))
            ->assertSessionHasNoErrors();

        $fresh = $a->fresh();
        $this->assertSame('Renamed while locked', $fresh->name);
        $this->assertSame('poster', $fresh->qr_type);
        $this->assertSame('New message', $fresh->default_message);
        $this->assertTrue($fresh->admin_locked, 'Editing other fields must not clear the lock.');
    }

    /** POSITIVE CONTROL: unlocked, the same request succeeds. */
    #[Test]
    public function an_unlocked_customer_can_change_the_status(): void
    {
        ['assignment' => $a, 'code' => $code, 'user' => $user] = $this->tenant();

        $this->actingAs($user)
            ->patch(route('client.smartqr.codes.update', $code->serial_number),
                $this->customerPayload($a, ['status' => SmartQrStatus::ASSIGNMENT_INACTIVE]))
            ->assertSessionHasNoErrors();

        $this->assertSame(SmartQrStatus::ASSIGNMENT_INACTIVE, $a->fresh()->status);
    }

    /**
     * ⚠️ Resubmitting the SAME status while locked must not be refused. The form
     * posts every field, so a locked tenant editing only the name sends `status`
     * unchanged — rejecting that would block the edit the previous test allows.
     */
    #[Test]
    public function resubmitting_an_unchanged_status_while_locked_is_allowed(): void
    {
        ['assignment' => $a, 'code' => $code, 'user' => $user] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Under review']);

        $locked = $a->fresh();

        $this->actingAs($user)
            ->patch(route('client.smartqr.codes.update', $code->serial_number),
                $this->customerPayload($locked, ['name' => 'Still editable']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Still editable', $a->fresh()->name);
    }

    // ══ Mass-assignment ════════════════════════════════════════════════════

    /**
     * ⚠️ LAYER ONE: validation strips the field before it reaches the model.
     *
     * UpdateCustomerQrRequest's rules do not mention admin_locked, so
     * $request->validated() never carries it. This test proves THAT layer —
     * and mutation testing showed it proves ONLY that layer: making the lock
     * columns $fillable leaves this test green, because the key is already gone
     * by then.
     *
     * The $fillable exclusion is the second, independent layer, exercised by the
     * test below. Both are real; neither on its own is the whole defence, and a
     * test that conflated them would report protection it had not checked.
     */
    #[Test]
    public function a_customer_cannot_unlock_themselves_by_posting_the_column(): void
    {
        ['assignment' => $a, 'code' => $code, 'user' => $user] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Chargeback under review']);

        $this->actingAs($user)
            ->patch(route('client.smartqr.codes.update', $code->serial_number),
                $this->customerPayload($a->fresh(), [
                    'admin_locked' => false,
                    'lock_reason' => null,
                    'locked_by_admin_id' => null,
                    'locked_at' => null,
                ]));

        $fresh = $a->fresh();
        $this->assertTrue($fresh->admin_locked, 'The tenant unlocked themselves by mass assignment.');
        $this->assertSame('Chargeback under review', $fresh->lock_reason);
        $this->assertNotNull($fresh->locked_by_admin_id);
        $this->assertNotNull($fresh->locked_at);
    }

    /**
     * ⚠️ LAYER TWO: $fillable, exercised directly.
     *
     * The route test above cannot reach this — validation removes the key first.
     * So this calls update() on the model with the lock payload, which is exactly
     * what would happen if a future rule, a new endpoint, or a console command
     * ever passed unfiltered input through. If the lock columns were fillable,
     * this silently unlocks the row.
     *
     * Two layers, two tests. Mutation-verified: making the columns fillable fails
     * THIS test and nothing else.
     */
    #[Test]
    public function the_lock_columns_are_not_mass_assignable(): void
    {
        ['assignment' => $a] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Chargeback under review']);

        $locked = $a->fresh();

        // The unfiltered payload a tenant would love to submit.
        $locked->update([
            'admin_locked' => false,
            'lock_reason' => null,
            'locked_by_admin_id' => null,
            'locked_at' => null,
        ]);

        $fresh = $a->fresh();
        $this->assertTrue($fresh->admin_locked, 'update() mass-assigned admin_locked — the column is fillable.');
        $this->assertSame('Chargeback under review', $fresh->lock_reason);
        $this->assertNotNull($fresh->locked_by_admin_id);
        $this->assertNotNull($fresh->locked_at);
    }

    /**
     * ⚠️ And the validator does not accept it either — so a future change that
     * made the column fillable would still be caught here, and vice versa. The
     * two layers fail independently, which is the point of having both.
     */
    #[Test]
    public function the_customer_validator_does_not_accept_lock_fields(): void
    {
        ['assignment' => $a] = $this->tenant();

        $validated = app(UpdateCustomerQrRequest::class)
            ->setContainer(app())
            ->merge($this->customerPayload($a, ['admin_locked' => false, 'lock_reason' => 'x']))
            ->rules();

        $this->assertArrayNotHasKey('admin_locked', $validated);
        $this->assertArrayNotHasKey('lock_reason', $validated);
        $this->assertArrayNotHasKey('locked_by_admin_id', $validated);
        $this->assertArrayNotHasKey('locked_at', $validated);
    }

    // ══ Admin path is unaffected ═══════════════════════════════════════════

    /**
     * ⚠️ assignments.update carries NO lock guard — it is the sibling of the
     * unlock path. An admin who can already lock and unlock gains nothing from
     * being blocked here, and would be unable to correct a mistake.
     */
    #[Test]
    public function the_admin_status_path_is_unaffected_by_the_lock(): void
    {
        ['assignment' => $a] = $this->tenant();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Under review']);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.qr.assignments.update', $a->uuid), [
                'name' => $a->name,
                'qr_type' => $a->qr_type,
                'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
            ])
            ->assertSessionHasNoErrors();

        $fresh = $a->fresh();
        $this->assertSame(SmartQrStatus::ASSIGNMENT_ACTIVE, $fresh->status,
            'An admin must still be able to change status on a locked assignment.');
        $this->assertTrue($fresh->admin_locked, 'and doing so must not clear the lock.');
    }

    // ══ Permission gate ════════════════════════════════════════════════════

    /**
     * ⚠️ ASSERTS THE EFFECT, not a status code — RequirePermission redirects an
     * HTML request rather than returning 403.
     */
    #[Test]
    public function locking_requires_the_lock_permission(): void
    {
        ['assignment' => $a] = $this->tenant();
        $without = $this->admin(['assign_qr_codes', 'view_qr_inventory']);

        $this->actingAs($without, 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Should not apply'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertFalse($a->fresh()->admin_locked);
    }

    #[Test]
    public function unlocking_requires_the_lock_permission(): void
    {
        ['assignment' => $a] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Under review']);

        $this->actingAs($this->admin(['assign_qr_codes']), 'admin')
            ->delete(route('admin.qr.assignments.unlock', $a->uuid))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue($a->fresh()->admin_locked, 'The lock survived an unauthorised unlock.');
    }

    // ══ Public scan unchanged ══════════════════════════════════════════════

    /**
     * ⚠️ THE LOCK ADDS NO OUTCOME. SmartQrRedirectResolver still routes on
     * `status` alone; a locked code shows the ordinary INACTIVE page, because
     * from a scanner's side that is exactly what it is.
     */
    #[Test]
    public function a_locked_code_scans_as_inactive_not_as_something_new(): void
    {
        ['assignment' => $a, 'code' => $code] = $this->tenant();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.assignments.lock', $a->uuid), ['lock_reason' => 'Under review']);

        // ⚠️ Asserted on the RENDERED PAGE, matching
        // PublicQrRedirectTest::an_inactive_assignment_shows_the_inactive_page.
        // smart_qr_scan_events records no outcome column — the outcome lives in
        // the response, so that is where it has to be checked.
        $this->get(route('smartqr.scan', ['token' => $code->public_token]))
            ->assertOk()
            ->assertSee('currently inactive', false);
    }
}
