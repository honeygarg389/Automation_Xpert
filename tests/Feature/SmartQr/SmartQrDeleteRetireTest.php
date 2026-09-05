<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrScanEvent;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 3c — delete vs retire.
 *
 * ─── ⚠️ THE DISCRIMINATOR, FOUND BEFORE THE TESTS WERE WRITTEN ──────────────
 *
 * The question is not "was an error returned". Both a correct and a broken rule
 * can return an error. What separates them is WHAT SURVIVES:
 *
 *   A TOO-PERMISSIVE rule deletes an assigned code. The delete cascades to
 *   `smart_qr_assignments` and from there to `smart_qr_scan_events`, so the
 *   previous tenant's entire scan history disappears — silently, because
 *   nothing reads those rows at delete time. So the assertion is that the
 *   ASSIGNMENT ROW AND ITS SCAN EVENTS STILL EXIST, not that a 4xx came back.
 *
 *   A TOO-STRICT rule refuses a clean batch. That is the positive control, and
 *   without it "nothing is ever deletable" would satisfy every negative test in
 *   this file.
 *
 * Both directions are asserted for codes and for batches.
 */
class SmartQrDeleteRetireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->withoutVite();
    }

    private function adminWith(array $keys): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-del-'.(implode('-', $keys) ?: 'none')],
            ['name' => 'QR Delete Test Role', 'description' => 'test']
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

    /** A code that was assigned, then unassigned — history, but nothing current. */
    private function formerlyAssigned(SmartQrBatch $batch): SmartQrCode
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $code = SmartQrCode::factory()->create(['batch_id' => $batch->id]);
        $assignment = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
        ]);
        SmartQrScanEvent::create(['smart_qr_assignment_id' => $assignment->id, 'scanned_at' => now()->subDay()]);
        $assignment->forceFill(['unassigned_at' => now(), 'status' => SmartQrStatus::ASSIGNMENT_ENDED])->saveQuietly();

        return $code;
    }

    // ══ ⚠️ CODES — the destructive direction ═══════════════════════════════

    /**
     * ⚠️ THE ASSERTION THAT MATTERS: an assigned code's HISTORY survives.
     *
     * A too-permissive rule returns success here and takes the assignment row
     * and every scan keyed to it with it. Asserting only the HTTP response, or
     * only that the code row survived, would not notice the scans were gone.
     */
    #[Test]
    public function deleting_an_assigned_code_is_refused_and_its_scan_history_survives(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create();
        $code = $this->formerlyAssigned($batch);

        $this->assertSame(1, (int) DB::table('smart_qr_scan_events')->count(), 'Precondition.');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.inventory.index'))
            ->delete(route('admin.qr.inventory.destroy'), ['code_ids' => [$code->id]])
            ->assertSessionHasErrors('code_ids');

        $this->assertDatabaseHas('smart_qr_codes', ['id' => $code->id]);

        // ⚠️ The two rows a permissive rule would have destroyed, silently.
        $this->assertSame(1, (int) DB::table('smart_qr_assignments')->where('smart_qr_code_id', $code->id)->count(),
            "The assignment row is gone. Deleting an assigned code destroys the tenant's period, "
            .'which is exactly what R-4 and the reassignment model exist to preserve.');
        $this->assertSame(1, (int) DB::table('smart_qr_scan_events')->count(),
            "The previous tenant's scan events were destroyed. They cascade from the assignment, "
            .'so nothing surfaces at delete time — this assertion is the only thing that notices.');
    }

    /** A printed code is refused too — the sticker exists whatever we do to the row. */
    #[Test]
    public function deleting_a_printed_code_is_refused(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $code = SmartQrCode::factory()->printed()->create();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.inventory.index'))
            ->delete(route('admin.qr.inventory.destroy'), ['code_ids' => [$code->id]])
            ->assertSessionHasErrors('code_ids');

        $this->assertDatabaseHas('smart_qr_codes', ['id' => $code->id]);
    }

    /**
     * ⚠️ POSITIVE CONTROL. Without it, "delete nothing, ever" passes every test
     * above and the feature would be shipped inert.
     */
    #[Test]
    public function a_never_printed_never_assigned_code_is_genuinely_deleted(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $code = SmartQrCode::factory()->create();

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.qr.inventory.destroy'), ['code_ids' => [$code->id]])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('smart_qr_codes', ['id' => $code->id]);
    }

    /** All-or-nothing: one blocked code refuses the whole selection. */
    #[Test]
    public function one_blocked_code_refuses_the_entire_selection(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $clean = SmartQrCode::factory()->count(3)->create();
        $printed = SmartQrCode::factory()->printed()->create();

        $ids = $clean->pluck('id')->push($printed->id)->all();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.inventory.index'))
            ->delete(route('admin.qr.inventory.destroy'), ['code_ids' => $ids])
            ->assertSessionHasErrors('code_ids');

        $this->assertSame(4, SmartQrCode::whereIn('id', $ids)->count(),
            'Some codes were deleted before the blocked one was reached. A partial delete has no '
            .'undo and reports failure while rows are already gone.');
    }

    /** The refusal NAMES the blocking serial, so an operator can act on it. */
    #[Test]
    public function the_refusal_names_the_blocking_code(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $printed = SmartQrCode::factory()->printed()->create(['serial_number' => 'AX-000777']);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.inventory.index'))
            ->delete(route('admin.qr.inventory.destroy'), ['code_ids' => [$printed->id]]);

        $this->assertStringContainsString('AX-000777', session('errors')->first('code_ids'),
            'The refusal did not name the blocking code. In a 500-code batch "refused" leaves an '
            .'operator hunting for the one row that matters.');
    }

    // ══ BATCHES ════════════════════════════════════════════════════════════

    /**
     * ⚠️ ONE printed code among many makes the WHOLE batch retire-only, and the
     * codes that were clean must survive too.
     */
    #[Test]
    public function a_batch_with_any_printed_code_cannot_be_deleted(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create();
        SmartQrCode::factory()->count(4)->create(['batch_id' => $batch->id]);
        SmartQrCode::factory()->printed()->create(['batch_id' => $batch->id, 'serial_number' => 'AX-000042']);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.batches.index'))
            ->delete(route('admin.qr.batches.destroy', $batch->uuid))
            ->assertSessionHasErrors('batch');

        $this->assertStringContainsString('AX-000042', session('errors')->first('batch'));
        $this->assertDatabaseHas('smart_qr_batches', ['id' => $batch->id]);
        $this->assertSame(5, SmartQrCode::where('batch_id', $batch->id)->count(),
            'Codes were deleted even though the batch delete was refused.');
    }

    /** …and one EVER-assigned code blocks it just as hard as a printed one. */
    #[Test]
    public function a_batch_with_a_formerly_assigned_code_cannot_be_deleted(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create();
        SmartQrCode::factory()->count(2)->create(['batch_id' => $batch->id]);
        $formerlyAssigned = $this->formerlyAssigned($batch);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.batches.index'))
            ->delete(route('admin.qr.batches.destroy', $batch->uuid))
            ->assertSessionHasErrors('batch');

        $this->assertSame(
            'This batch cannot be deleted: 1 of its codes have been printed or have assignment history '
            ."({$formerlyAssigned->serial_number} (assigned)). Retire it instead — deleting would destroy "
            .'assignment history and leave printed stickers unexplainable.',
            session('errors')->first('batch')
        );

        $this->assertDatabaseHas('smart_qr_batches', ['id' => $batch->id]);
        $this->assertSame(1, (int) DB::table('smart_qr_scan_events')->count(),
            'The scan history of a code whose assignment ENDED was destroyed. "Never assigned" '
            .'means ever, not currently — asking the current question reports it deletable.');
    }

    /** POSITIVE CONTROL: a wholly clean batch really is deleted, codes and all. */
    #[Test]
    public function a_clean_batch_is_deleted_with_its_codes(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create();
        SmartQrCode::factory()->count(3)->create(['batch_id' => $batch->id]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.qr.batches.destroy', $batch->uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('smart_qr_batches', ['id' => $batch->id]);
        $this->assertSame(0, SmartQrCode::where('batch_id', $batch->id)->count(),
            'The batch went but its codes were orphaned.');
    }

    // ══ RETIRE — the path that always works ════════════════════════════════

    #[Test]
    public function retiring_a_batch_keeps_every_row_and_blocks_assignment(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create();
        SmartQrCode::factory()->count(3)->create(['batch_id' => $batch->id]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.batches.retire', $batch->uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('smart_qr_batches', [
            'id' => $batch->id,
            'status' => SmartQrStatus::BATCH_RETIRED,
        ]);
        $this->assertSame(3, SmartQrCode::where('batch_id', $batch->id)
            ->where('status', SmartQrStatus::CODE_RETIRED)->count(),
            'Retiring must keep the rows and move their status — it is the alternative to '
            .'deletion, not a slower version of it.');
    }

    /** A retired code is refused at assignment — the status has to mean something. */
    #[Test]
    public function a_retired_code_cannot_be_assigned(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $channel = ChannelAccount::withoutWorkspaceScope('reason: fixture')->create([
            'workspace_id' => $workspace->id, 'channel' => 'whatsapp',
            'display_name' => 'Line', 'phone_number_id' => 'PN-'.uniqid(), 'status' => 'active',
        ]);
        $admin = $this->adminWith(['assign_qr_codes']);
        $code = SmartQrCode::factory()->retired()->create();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.assignments.index'))
            ->post(route('admin.qr.assignments.store'), [
                'code_ids' => [$code->id],
                'workspace_id' => $workspace->id,
                'channel_account_id' => $channel->id,
            ])
            ->assertSessionHasErrors('code_ids');

        $this->assertSame(0, (int) DB::table('smart_qr_assignments')->count());
    }

    // ══ Audit + permissions ════════════════════════════════════════════════

    #[Test]
    public function a_deletion_is_audit_logged_with_the_count(): void
    {
        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create();
        SmartQrCode::factory()->count(2)->create(['batch_id' => $batch->id]);

        $this->actingAs($admin, 'admin')->delete(route('admin.qr.batches.destroy', $batch->uuid));

        $log = AuditLog::where('action', 'smart_qr.batch_deleted')->latest('id')->first();

        $this->assertNotNull($log, 'The deletion was not audit-logged (§19 lists it as audited).');
        $this->assertSame($admin->id, $log->actor_admin_id);
        $this->assertSame(2, $log->meta['codes_deleted'] ?? null,
            'The audit row does not record how many codes went with the batch.');
    }

    #[Test]
    public function deleting_requires_the_manage_permission(): void
    {
        $admin = $this->adminWith(['view_qr_inventory']);
        $code = SmartQrCode::factory()->create();

        $this->actingAs($admin, 'admin')
            ->deleteJson(route('admin.qr.inventory.destroy'), ['code_ids' => [$code->id]])
            ->assertForbidden();

        $this->assertDatabaseHas('smart_qr_codes', ['id' => $code->id]);
    }

    // ══ BATCH LOGO CLEANUP ON DELETE ═══════════════════════════════════════

    /**
     * ⚠️ REGISTERED ON THE MODEL — SmartQrBatch::booted()'s static::deleting()
     * hook — NOT in QrBatchController::destroy(), which this file's other
     * tests already exercise. Going through the real HTTP delete flow here is
     * what proves the hook actually fires from that path; a direct
     * `$batch->delete()` call would prove the hook exists but not that the
     * controller reaches it.
     */
    #[Test]
    public function deleting_a_batch_through_the_real_flow_removes_its_logo_file_from_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('branding/qr-logo-cleanup-test.png', 'fake-png-bytes');

        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create([
            'logo_path' => 'branding/qr-logo-cleanup-test.png',
            'logo_disk' => 'local',
        ]);

        $this->assertTrue(Storage::disk('local')->exists('branding/qr-logo-cleanup-test.png'),
            'Positive control: the file was never actually written, so its absence afterward '
            .'would prove nothing.');

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.qr.batches.destroy', $batch->uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('smart_qr_batches', ['id' => $batch->id]);
        $this->assertFalse(Storage::disk('local')->exists('branding/qr-logo-cleanup-test.png'),
            'The batch row was deleted but its logo file survived on disk — the orphan this hook '
            .'exists to prevent.');
    }

    /**
     * ⚠️ THE MEASURED NON-THROWING CASE, PINNED ANYWAY.
     *
     * Storage::delete() on an already-missing file returns true and throws
     * nothing — measured directly against this app's local Flysystem adapter
     * before this hook was written. This test exists so that guarantee stays
     * true rather than merely having been true once during development.
     */
    #[Test]
    public function deleting_a_batch_whose_logo_file_is_already_gone_still_succeeds(): void
    {
        Storage::fake('local');
        // ⚠️ Deliberately NEVER written to the fake disk — this IS the
        // "already gone" state, not a file created then removed.

        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create([
            'logo_path' => 'branding/qr-logo-vanished.png',
            'logo_disk' => 'local',
        ]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.qr.batches.destroy', $batch->uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('smart_qr_batches', ['id' => $batch->id]);
    }

    /**
     * ═══ ⚠️ THE CRITICAL CASE — A FILE-DELETE FAILURE MUST NOT BLOCK THE ROW ═══
     *
     * Simulated with an INVALID disk name rather than a mock, so the failure
     * is a real one the framework produces (an unconfigured disk throws when
     * Storage::disk() tries to resolve it), not a stand-in for a failure that
     * might not match how PHP actually fails here.
     *
     * ⚠️ THIS TEST FAILS IF THE try/catch IS REMOVED — mutation-verified, not
     * merely asserted to. Without the try/catch, the exception from
     * Storage::disk('this-disk-does-not-exist') propagates out of the
     * `deleting` listener, Eloquent aborts the delete, and both assertions
     * below fail: the row survives and nothing is logged as a *caught*
     * failure (Laravel's own unhandled-exception path would fire instead).
     */
    #[Test]
    public function a_genuine_logo_delete_failure_is_logged_but_does_not_block_the_batch_delete(): void
    {
        Log::spy();

        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create([
            'logo_path' => 'branding/qr-logo-unreachable.png',
            'logo_disk' => 'this-disk-does-not-exist',
        ]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.qr.batches.destroy', $batch->uuid))
            ->assertSessionHasNoErrors();

        // ⚠️ assertDatabaseMissing's 3rd param is a CONNECTION name in this
        // Laravel version, not a message — confirmed against
        // InteractsWithDatabase.php before fixing this. The comment above
        // carries what the failure would mean instead.
        $this->assertDatabaseMissing('smart_qr_batches', ['id' => $batch->id]);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($batch) {
                return $message === 'smart_qr.batch_logo_cleanup_failed'
                    && $context['batch_id'] === $batch->id
                    && $context['logo_disk'] === 'this-disk-does-not-exist';
            });
    }

    /**
     * POSITIVE CONTROL for all three tests above: a batch with NO logo takes
     * no Storage action at all and deletes exactly as it always did.
     */
    #[Test]
    public function deleting_a_batch_without_a_logo_makes_no_storage_call_and_behaves_unchanged(): void
    {
        Storage::fake('local');

        $admin = $this->adminWith(['manage_qr_batches']);
        $batch = SmartQrBatch::factory()->create(['logo_path' => null, 'logo_disk' => null]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.qr.batches.destroy', $batch->uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('smart_qr_batches', ['id' => $batch->id]);

        // ⚠️ Storage::fake() records every operation; asserting NONE happened
        // is the discriminator against a version of the hook that calls
        // Storage unconditionally and only happens to no-op for null paths.
        Storage::disk('local')->assertDirectoryEmpty('branding');
    }
}
