<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 6546399 — search, status toggle and the history-view column gate on the
 * admin assignments list.
 *
 * ─── ⚠️ THE LOCKED-ROW TOGGLE IS DELIBERATELY NOT RE-TESTED HERE ────────────
 *
 * `QrAssignmentController::update` carries no lock guard — that is not an
 * omission, it is R-8's documented split: the lock stops the CUSTOMER only,
 * and `SmartQrAssignmentLockTest::the_admin_status_path_is_unaffected_by_the_lock`
 * already proves the admin PATCH changes status on a locked row without
 * clearing the lock. Duplicating it here would test the same endpoint twice.
 *
 * The thing that actually gates the admin UI — the `window.confirm()` prompt
 * in Assignments/Index.jsx — is JS, not PHP, and is covered (with a mutation
 * check on the confirm call itself) in
 * `resources/js/__tests__/smartqr-assignments-list.test.jsx`.
 */
class SmartQrAssignmentsListTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $permissions */
    private function admin(array $permissions = ['view_qr_inventory', 'assign_qr_codes']): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-list-test-'.md5(implode(',', $permissions))],
            ['name' => 'QR List Test', 'description' => 'test']
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

    /** @return array{workspace: Workspace, channel: ChannelAccount} */
    private function workspace(): array
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['smart_qr_max_assigned' => 50]]));

        $channel = ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $workspace->id, 'channel' => 'whatsapp',
            'display_name' => 'Main Line', 'phone_number_id' => 'PN-'.uniqid(), 'status' => 'active',
        ]);

        return compact('workspace', 'channel');
    }

    /** @param  array<string, mixed>  $over */
    private function assignmentWithSerial(Workspace $workspace, ChannelAccount $channel, string $serial, array $over = []): SmartQrAssignment
    {
        $code = SmartQrCode::factory()->create(['serial_number' => $serial]);

        return SmartQrAssignment::factory()->create(array_merge([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channel->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ], $over));
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function rowsFrom(TestResponse $response): array
    {
        $rows = [];
        $response->assertInertia(function ($page) use (&$rows) {
            $rows = $page->toArray()['props']['assignments']['data'] ?? [];
        });

        return $rows;
    }

    // ══ Status toggle round trip ═══════════════════════════════════════════

    #[Test]
    public function the_toggle_patches_an_active_assignment_to_inactive_and_back(): void
    {
        ['workspace' => $workspace, 'channel' => $channel] = $this->workspace();
        $a = $this->assignmentWithSerial($workspace, $channel, 'AX-000001', ['status' => SmartQrStatus::ASSIGNMENT_ACTIVE]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.qr.assignments.update', $a->uuid), [
                'name' => $a->name, 'qr_type' => $a->qr_type, 'status' => SmartQrStatus::ASSIGNMENT_INACTIVE,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(SmartQrStatus::ASSIGNMENT_INACTIVE, $a->fresh()->status,
            'The PATCH did not persist inactive to the row.');

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.qr.assignments.update', $a->uuid), [
                'name' => $a->name, 'qr_type' => $a->qr_type, 'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(SmartQrStatus::ASSIGNMENT_ACTIVE, $a->fresh()->status,
            'The PATCH did not persist active back to the row on the return trip.');
    }

    #[Test]
    public function toggling_requires_the_assign_permission(): void
    {
        ['workspace' => $workspace, 'channel' => $channel] = $this->workspace();
        $a = $this->assignmentWithSerial($workspace, $channel, 'AX-000002', ['status' => SmartQrStatus::ASSIGNMENT_ACTIVE]);

        $this->actingAs($this->admin(['view_qr_inventory']), 'admin')
            ->patch(route('admin.qr.assignments.update', $a->uuid), [
                'name' => $a->name, 'status' => SmartQrStatus::ASSIGNMENT_INACTIVE,
            ])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(SmartQrStatus::ASSIGNMENT_ACTIVE, $a->fresh()->status,
            'An admin without assign_qr_codes changed the status.');
    }

    // ══ Search ═════════════════════════════════════════════════════════════

    #[Test]
    public function search_matches_a_partial_serial_number(): void
    {
        ['workspace' => $workspace, 'channel' => $channel] = $this->workspace();
        $target = $this->assignmentWithSerial($workspace, $channel, 'AX-000042');
        $other = $this->assignmentWithSerial($workspace, $channel, 'ZZ-999999');

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.assignments.index', ['search' => '000042']));

        $response->assertOk();
        $rows = $this->rowsFrom($response);
        $serials = array_map(fn ($r) => $r['code']['serial_number'], $rows);

        $this->assertContains('AX-000042', $serials, 'The matching serial was not returned by the search.');
        $this->assertNotContains('ZZ-999999', $serials, 'A non-matching serial was returned by the search.');
    }

    /** POSITIVE CONTROL: the same two rows, no search term, both come back. */
    #[Test]
    public function without_a_search_term_both_rows_are_returned(): void
    {
        ['workspace' => $workspace, 'channel' => $channel] = $this->workspace();
        $this->assignmentWithSerial($workspace, $channel, 'AX-000042');
        $this->assignmentWithSerial($workspace, $channel, 'ZZ-999999');

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.assignments.index'));

        $rows = $this->rowsFrom($response);
        $serials = array_map(fn ($r) => $r['code']['serial_number'], $rows);

        $this->assertContains('AX-000042', $serials);
        $this->assertContains('ZZ-999999', $serials);
    }

    #[Test]
    public function search_is_case_insensitive(): void
    {
        ['workspace' => $workspace, 'channel' => $channel] = $this->workspace();
        $this->assignmentWithSerial($workspace, $channel, 'AX-LOWER-01');

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.assignments.index', ['search' => 'ax-lower']));

        $rows = $this->rowsFrom($response);
        $serials = array_map(fn ($r) => $r['code']['serial_number'], $rows);

        $this->assertContains('AX-LOWER-01', $serials,
            'A lowercase search term did not match an uppercase serial — search is case-sensitive as implemented.');
    }

    #[Test]
    public function search_composes_with_the_history_filter_instead_of_resetting_it(): void
    {
        ['workspace' => $workspace, 'channel' => $channel] = $this->workspace();
        $ended = $this->assignmentWithSerial($workspace, $channel, 'AX-ENDED-01', [
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
            'unassigned_at' => now()->subDay(),
        ]);
        $current = $this->assignmentWithSerial($workspace, $channel, 'AX-ENDED-02');

        // current=all + search must still find the ENDED row by serial.
        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.assignments.index', ['current' => 'all', 'search' => 'AX-ENDED-01']));

        $rows = $this->rowsFrom($response);
        $serials = array_map(fn ($r) => $r['code']['serial_number'], $rows);

        $this->assertContains('AX-ENDED-01', $serials,
            'Searching while viewing history must still search history, not reset to current-only.');
        $this->assertNotContains('AX-ENDED-02', $serials);
    }

    // ══ current=all / ended-row gating (backend half of the ENDED column) ═══

    /**
     * ⚠️ THE BACKEND HALF of the ENDED column's visibility. The column itself is
     * a frontend-only render decision (see the vitest coverage), but it exists
     * ONLY because the controller guarantees every current-view row has
     * unassigned_at === null — this test pins that guarantee, which is what the
     * JSX comment in Index.jsx relies on without re-checking.
     */
    #[Test]
    public function the_default_view_excludes_ended_assignments(): void
    {
        ['workspace' => $workspace, 'channel' => $channel] = $this->workspace();
        $this->assignmentWithSerial($workspace, $channel, 'AX-CUR-01');
        $this->assignmentWithSerial($workspace, $channel, 'AX-END-01', [
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
            'unassigned_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.assignments.index'));

        $rows = $this->rowsFrom($response);
        $serials = array_map(fn ($r) => $r['code']['serial_number'], $rows);

        $this->assertContains('AX-CUR-01', $serials);
        $this->assertNotContains('AX-END-01', $serials,
            'An ended assignment leaked into the current-only (default) view.');

        foreach ($rows as $r) {
            $this->assertNull($r['unassigned_at'],
                'A row in the current-only view had unassigned_at set — the ENDED column would show it as a dash anyway.');
        }
    }

    /** POSITIVE CONTROL: current=all brings the ended row back, with its end date. */
    #[Test]
    public function current_all_includes_ended_assignments_with_their_end_date(): void
    {
        ['workspace' => $workspace, 'channel' => $channel] = $this->workspace();
        $this->assignmentWithSerial($workspace, $channel, 'AX-CUR-02');
        $ended = $this->assignmentWithSerial($workspace, $channel, 'AX-END-02', [
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
            'unassigned_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.assignments.index', ['current' => 'all']));

        $rows = $this->rowsFrom($response);
        $endedRow = collect($rows)->firstWhere('code.serial_number', 'AX-END-02');

        $this->assertNotNull($endedRow, 'The ended assignment was not returned under current=all.');
        $this->assertNotNull($endedRow['unassigned_at'],
            'The ended row was returned with no end date — the ENDED column would show a dash despite the period being closed.');
    }
}
