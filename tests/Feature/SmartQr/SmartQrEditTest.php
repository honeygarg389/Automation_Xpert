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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 3c — renaming a batch, and editing a QR's per-tenant details.
 *
 * ⚠️ The tests that matter here are the NEGATIVE ones: serial_number,
 * public_token and workspace_id must be unreachable through an edit form. Each
 * has a positive control beside it, because "the endpoint ignores everything"
 * would satisfy every negative assertion on its own.
 */
class SmartQrEditTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(array $keys): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-edit-'.(implode('-', $keys) ?: 'none')],
            ['name' => 'QR Edit Test Role', 'description' => 'test']
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

    private function assignment(): SmartQrAssignment
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        return SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => SmartQrCode::factory()->create()->id,
            'workspace_id' => $workspace->id,
            'name' => 'Old name',
            'qr_type' => 'Counter',
        ]);
    }

    // ══ Batch rename ═══════════════════════════════════════════════════════

    #[Test]
    public function a_batch_name_and_number_can_be_renamed(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create(['batch_name' => 'Old', 'batch_number' => 'AX-BK-OLD']);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.qr.batches.update', $batch->uuid), [
                'batch_name' => 'New name',
                'batch_number' => 'AX-BK-NEW',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('smart_qr_batches', [
            'id' => $batch->id, 'batch_name' => 'New name', 'batch_number' => 'AX-BK-NEW',
        ]);
    }

    /**
     * ⚠️ Renaming must NOT touch the serial range.
     *
     * prefix / serial_start / quantity define serials that are already generated
     * and already printed. A rename that moved them would leave every existing
     * serial describing a range the batch no longer claims.
     */
    #[Test]
    public function renaming_cannot_move_the_serial_range(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create(['prefix' => 'AX', 'serial_start' => 1, 'quantity' => 10]);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.qr.batches.update', $batch->uuid), [
                'batch_name' => 'Renamed',
                'batch_number' => 'AX-BK-2',
                'prefix' => 'ZZ',
                'serial_start' => 9000,
                'quantity' => 999,
            ])
            ->assertSessionHasNoErrors();

        $batch->refresh();
        $this->assertSame('AX', $batch->prefix, 'The prefix moved. Existing serials now describe a range the batch does not claim.');
        $this->assertSame(1, $batch->serial_start);
        $this->assertSame(10, $batch->quantity);
        $this->assertSame('Renamed', $batch->batch_name, 'Positive control: the rename itself did apply.');
    }

    /** batch_number stays unique — two print runs sharing one is its own confusion. */
    #[Test]
    public function a_duplicate_batch_number_is_refused_but_keeping_your_own_is_allowed(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        SmartQrBatch::factory()->create(['batch_number' => 'AX-BK-TAKEN']);
        $batch = SmartQrBatch::factory()->create(['batch_number' => 'AX-BK-MINE']);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.batches.index'))
            ->patch(route('admin.qr.batches.update', $batch->uuid), [
                'batch_name' => 'X', 'batch_number' => 'AX-BK-TAKEN',
            ])
            ->assertSessionHasErrors('batch_number');

        // ⚠️ Positive control for the `ignore()`: saving a batch WITHOUT changing
        // its number must not collide with itself.
        $this->actingAs($admin, 'admin')
            ->patch(route('admin.qr.batches.update', $batch->uuid), [
                'batch_name' => 'Renamed only', 'batch_number' => 'AX-BK-MINE',
            ])
            ->assertSessionHasNoErrors();
    }

    // ══ ⚠️ Assignment edit — the immutable fields ══════════════════════════

    #[Test]
    public function the_editable_per_tenant_fields_are_saved(): void
    {
        $admin = $this->adminWith(['assign_qr_codes']);
        $a = $this->assignment();

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.qr.assignments.update', $a->uuid), [
                'name' => 'Front counter',
                'qr_type' => 'Reception',
                'default_message' => 'Hello there',
                'status' => SmartQrStatus::ASSIGNMENT_INACTIVE,
                'expires_at' => now()->addMonth()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame('Front counter', $a->name);
        $this->assertSame('Reception', $a->qr_type);
        $this->assertSame(SmartQrStatus::ASSIGNMENT_INACTIVE, $a->status);
        $this->assertNotNull($a->expires_at);
    }

    /**
     * ⚠️ serial_number and public_token are unreachable through this form.
     *
     * The serial is printed on a physical object; the token is the secret the QR
     * encodes and addresses a tenant from an unauthenticated request. §11 and
     * R-4 both forbid changing either.
     */
    #[Test]
    public function the_serial_and_token_cannot_be_changed_through_the_edit_form(): void
    {
        $admin = $this->adminWith(['assign_qr_codes']);
        $a = $this->assignment();
        $code = $a->code;
        $originalSerial = $code->serial_number;
        $originalToken = $code->public_token;

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.qr.assignments.update', $a->uuid), [
                'name' => 'Legit change',
                'serial_number' => 'AX-999999',
                'public_token' => 'attacker-chosen-token',
            ])
            ->assertSessionHasNoErrors();

        $code->refresh();
        $this->assertSame($originalSerial, $code->serial_number,
            'The printed serial was changed. It names a sticker already on a counter.');
        $this->assertSame($originalToken, $code->public_token,
            'The public token was changed, silently breaking every printed copy of this code.');
        $this->assertSame('Legit change', $a->fresh()->name,
            'Positive control: the request DID go through, so the two assertions above are about '
            .'fields that were ignored rather than a rejected request.');
    }

    /**
     * ⚠️ workspace_id is not reachable either — changing the tenant is a
     * REASSIGNMENT, which must close the old period so the previous tenant's
     * scans stay reachable and the one-current-assignment index stays valid.
     */
    #[Test]
    public function the_workspace_cannot_be_changed_through_the_edit_form(): void
    {
        $admin = $this->adminWith(['assign_qr_codes']);
        $a = $this->assignment();
        $original = $a->workspace_id;

        ['workspace' => $other] = $this->createWorkspaceContext();

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.qr.assignments.update', $a->uuid), [
                'name' => 'Legit change',
                'workspace_id' => $other->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($original, (int) $a->fresh()->workspace_id,
            'The assignment moved to another workspace through an edit form. That rewrites '
            .'history: one row now claims the code always belonged to the new tenant, and the '
            .'old tenant\'s scans hang off a period that never ended.');
    }

    #[Test]
    public function editing_requires_the_assign_permission(): void
    {
        $admin = $this->adminWith(['view_qr_inventory']);
        $a = $this->assignment();

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.qr.assignments.update', $a->uuid), ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertSame('Old name', $a->fresh()->name);
    }
}
