<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smart QR slice 3 — batch creation (§4) and inventory (§5).
 *
 * ⚠️ Two things carry real weight here:
 *
 *   the serial-range rule — the gap slice 2 found and deferred to this slice
 *   R-10               — `assigned` is derived, and must never reach a status column
 */
class SmartQrAdminInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ `withoutVite()` — and it is a SCOPE marker, not a convenience.
     *
     * Slice 3a ships the routes, controllers, permissions and Inertia props.
     * The React page components under `resources/js/Pages/Admin/SmartQr/` are
     * slice 3b, per CLAUDE.md's "schema is one task, the service layer is
     * another, UI is another".
     *
     * Without this, every Inertia GET here fails on "Unable to locate file in
     * Vite manifest" — a missing asset, not a failing controller. Asserting the
     * PROPS is what this file is for; whether a component renders them is 3b's
     * question.
     *
     * ⚠️ These calls come OUT when 3b lands. A `withoutVite()` left in place
     * after the pages exist would hide a genuinely broken page reference.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // ⚠️ Same scope marker, second half. Inertia's assertInertia() also
        // checks the page component FILE exists. Slice 3a asserts the PROPS a
        // controller returns; the components that consume them are 3b.
        config(['inertia.testing.ensure_pages_exist' => false]);
    }

    private function adminWith(array $keys): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-inv-'.(implode('-', $keys) ?: 'none')],
            ['name' => 'QR Inventory Test Role', 'description' => 'test']
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

    private function batchPayload(array $extra = []): array
    {
        return array_merge([
            'batch_name' => 'AutomationXpert Business Kit August 2026',
            'batch_number' => 'AX-BK-'.uniqid(),
            'prefix' => 'AX',
            'quantity' => 10,
            'serial_start' => 1,
        ], $extra);
    }

    // ══ ⚠️ The serial-range gap slice 2 deferred here ═══════════════════════

    /**
     * ⚠️ Overlapping ranges are refused AT CREATION, not at generation.
     *
     * `serial_number` is globally unique, so `prefix + serial_start + quantity`
     * defines a range no other batch may share. Before this rule the collision
     * surfaced mid-generation — possibly on the fifth chunk, after four had
     * committed — as a duplicate-key message against a half-generated batch.
     */
    #[Test]
    public function an_overlapping_serial_range_is_refused_at_batch_creation(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);

        SmartQrBatch::factory()->create(['prefix' => 'AX', 'serial_start' => 1, 'quantity' => 100]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.batches.index'))
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'prefix' => 'AX', 'serial_start' => 50, 'quantity' => 100,   // 50..149 vs 1..100
            ]))
            ->assertSessionHasErrors('serial_start');

        $this->assertSame(1, SmartQrBatch::where('prefix', 'AX')->count(),
            'The overlapping batch was created anyway. It would have failed later, mid-'
            .'generation, against a half-written batch.');
    }

    /**
     * POSITIVE CONTROL: an ADJACENT range is accepted.
     *
     * Without this, the rule could reject everything — including the legitimate
     * second print run continuing the sequence, which is the whole reason
     * `serial_start` exists — and the test above would still pass.
     */
    #[Test]
    public function an_adjacent_non_overlapping_range_is_accepted(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);

        SmartQrBatch::factory()->create(['prefix' => 'AX', 'serial_start' => 1, 'quantity' => 100]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'prefix' => 'AX', 'serial_start' => 101, 'quantity' => 100,   // 101..200, touching but not overlapping
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SmartQrBatch::where('prefix', 'AX')->count(),
            'A legitimate continuation run was refused. Off by one at the boundary: 101 starts '
            .'exactly where 1..100 ends, and must be allowed.');
    }

    /** A different prefix over the same numbers is a different serial space. */
    #[Test]
    public function the_same_range_under_a_different_prefix_is_accepted(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);

        SmartQrBatch::factory()->create(['prefix' => 'AX', 'serial_start' => 1, 'quantity' => 100]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'prefix' => 'ZZ', 'serial_start' => 1, 'quantity' => 100,
            ]))
            ->assertSessionHasNoErrors();
    }

    // ══ ⚠️ R-10 — the status vocabularies ══════════════════════════════════

    /**
     * ⚠️ `assigned` must never reach `smart_qr_codes.status`.
     *
     * Whether a code is assigned is already answered — exactly and atomically —
     * by the unique index over `current_code_id`. A status string beside it
     * would be a second source of truth beside a DB-ENFORCED one, and the two
     * would disagree the first time a row was written by a seeder, a raw insert
     * or a request that died between the two writes.
     */
    #[Test]
    public function assigning_a_code_does_not_write_an_assignment_word_to_the_code_status(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $code = SmartQrCode::factory()->create(['status' => SmartQrStatus::CODE_PRINTED]);
        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
        ]);

        $this->assertSame(SmartQrStatus::CODE_PRINTED, $code->fresh()->status,
            'Assigning a code changed its PHYSICAL status. The sticker did not change; only '
            .'who holds it did.');

        $this->assertTrue($code->fresh()->isAssigned(),
            'Positive control: the code IS assigned, so the assertion above is about a code '
            .'that would have been mislabelled.');
    }

    /** …and no row anywhere carries one, whatever wrote it. */
    #[Test]
    public function no_code_row_carries_an_assignment_word_as_its_status(): void
    {
        SmartQrCode::factory()->count(3)->create();

        $offending = DB::table('smart_qr_codes')
            ->whereIn('status', SmartQrStatus::FORBIDDEN_ON_CODE)
            ->pluck('serial_number')
            ->all();

        $this->assertSame([], $offending,
            'These codes carry an ASSIGNMENT word in their physical status column: '
            .implode(', ', $offending).'. R-10: codes.status is generated/printed/damaged/'
            .'lost/retired. "assigned" is derived from the current-assignment index and stored '
            .'nowhere.');
    }

    /** The bulk status action cannot be used to smuggle one in. */
    #[Test]
    public function the_bulk_status_action_rejects_an_assignment_word(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $code = SmartQrCode::factory()->create();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.inventory.index'))
            ->post(route('admin.qr.inventory.change-status'), [
                'code_ids' => [$code->id],
                'status' => 'assigned',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(SmartQrStatus::CODE_GENERATED, $code->fresh()->status);
    }

    /** POSITIVE CONTROL: a legitimate physical transition IS accepted. */
    #[Test]
    public function the_bulk_status_action_accepts_a_physical_status(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $code = SmartQrCode::factory()->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.inventory.change-status'), [
                'code_ids' => [$code->id],
                'status' => SmartQrStatus::CODE_RETIRED,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(SmartQrStatus::CODE_RETIRED, $code->fresh()->status,
            'The endpoint refuses every status, so the rejection above proved nothing.');
    }

    // ══ Inventory: unassigned inventory must be VISIBLE to admin ═══════════

    /**
     * ⚠️ The half a workspace scope would have broken, re-asserted at the HTTP
     * layer rather than only at the model.
     *
     * Unassigned codes are the entire reason SmartQrCode is lifecycle-owned: a
     * scope would hide them from every tenant AND from this screen.
     */
    #[Test]
    public function the_inventory_lists_unassigned_codes(): void
    {
        $admin = $this->adminWith(['view_qr_inventory']);
        SmartQrCode::factory()->count(3)->create();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SmartQr/Inventory/Index')
                ->has('codes.data', 3));
    }

    /**
     * ⚠️ The assigned/unassigned filter, and it is the fail-CLOSED trap.
     *
     * SmartQrAssignment is workspace-scoped and this request has no workspace
     * context, so a `whereHas` that left the scope on would match NOTHING and
     * the "assigned" filter would return an empty list for codes that are
     * genuinely assigned. Both directions are asserted, so a filter that
     * returns nothing cannot pass.
     */
    #[Test]
    public function the_assignment_filter_works_in_both_directions(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $admin = $this->adminWith(['view_qr_inventory']);

        $assigned = SmartQrCode::factory()->create();
        SmartQrCode::factory()->count(2)->create();

        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $assigned->id,
            'workspace_id' => $workspace->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.index', ['assignment' => 'assigned']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('codes.data', 1));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.index', ['assignment' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('codes.data', 2));
    }

    /** Serial search, because §5 lists it first. */
    #[Test]
    public function the_inventory_can_be_searched_by_serial(): void
    {
        $admin = $this->adminWith(['view_qr_inventory']);
        SmartQrCode::factory()->create(['serial_number' => 'AX-000042']);
        SmartQrCode::factory()->count(2)->create();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.index', ['search' => '000042']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('codes.data', 1));
    }

    // ══ ⚠️ R-12 — assigned_count is derived ════════════════════════════════

    /**
     * The batch list reports assignments without a stored counter, and the
     * number FALLS when one is ended — which a stored counter maintained by
     * hand is exactly what fails to do.
     */
    #[Test]
    public function the_batch_assigned_count_is_derived_and_falls_on_unassignment(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $admin = $this->adminWith(['view_qr_inventory']);

        $batch = SmartQrBatch::factory()->create();
        $codes = SmartQrCode::factory()->count(3)->create(['batch_id' => $batch->id]);

        foreach ($codes as $code) {
            SmartQrAssignment::factory()->create([
                'smart_qr_code_id' => $code->id,
                'workspace_id' => $workspace->id,
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.batches.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('batches.data.0.assigned_count', 3));

        DB::table('smart_qr_assignments')
            ->where('smart_qr_code_id', $codes->first()->id)
            ->update(['unassigned_at' => now(), 'status' => SmartQrStatus::ASSIGNMENT_ENDED]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.batches.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('batches.data.0.assigned_count', 2));

        // ⚠️ assertDatabaseCount's third argument is the CONNECTION, not a
        // message. Passing a message there throws "Database connection [...]
        // not configured" — a test that errors instead of asserting.
        $this->assertSame(3, (int) DB::table('smart_qr_assignments')->count(),
            'The ended assignment row was deleted. History must survive — R-4 needs the old '
            .'period for the previous tenant\'s scans to stay reachable.');
    }

    // ══ Permissions ════════════════════════════════════════════════════════

    #[Test]
    public function creating_a_batch_requires_the_manage_permission(): void
    {
        $admin = $this->adminWith(['view_qr_inventory']);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.qr.batches.store'), $this->batchPayload())
            ->assertForbidden();

        $this->assertSame(0, SmartQrBatch::count());
    }

    /** POSITIVE CONTROL: same route, same verb, same admin type. */
    #[Test]
    public function creating_a_batch_succeeds_with_the_manage_permission(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SmartQrBatch::count(),
            'The endpoint refuses everyone, so the 403 above proved nothing about the gate.');
    }

    #[Test]
    public function viewing_the_inventory_requires_a_permission(): void
    {
        $admin = $this->adminWith([]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.qr.inventory.index'))
            ->assertForbidden();
    }

    /**
     * ⚠️ The routes are NOT reachable without an admin session.
     *
     * This module owns its route file, so it declares `auth:admin` itself
     * rather than inheriting it from the group in bootstrap/app.php that wraps
     * routes/admin.php. Omitting it would publish the whole QR surface
     * unauthenticated, and every test above would still pass — they all sign in.
     */
    #[Test]
    public function the_qr_admin_routes_reject_a_guest(): void
    {
        $this->getJson(route('admin.qr.inventory.index'))->assertUnauthorized();
        $this->postJson(route('admin.qr.batches.store'), $this->batchPayload())->assertUnauthorized();
        $this->postJson(route('admin.qr.assignments.store'), [])->assertUnauthorized();
    }

    /**
     * …and a signed-in CLIENT user is not an admin.
     *
     * `auth:admin` is a different guard from `web`; a client session must not
     * satisfy it.
     */
    #[Test]
    public function the_qr_admin_routes_reject_a_client_user(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->getJson(route('admin.qr.inventory.index'))
            ->assertUnauthorized();
    }
}
