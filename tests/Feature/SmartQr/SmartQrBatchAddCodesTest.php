<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\SmartQr\Actions\GenerateQrBatchAction;
use App\Modules\SmartQr\Jobs\GenerateQrBatchJob;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "more QR's" — extending an existing batch, and the duplicate-key bug that
 * shipping it would otherwise have woken.
 *
 * ─── ⚠️ THE BUG THIS FILE EXISTS FOR ────────────────────────────────────────
 *
 * GenerateQrBatchAction resumed from `codes()->count()`. Count equals the
 * high-water mark only while the serials form an unbroken run — and
 * QrInventoryController::destroy HARD-deletes codes (SmartQrCode has no
 * SoftDeletes). Delete one from the middle and the next generation re-issues a
 * serial that already exists, violating the GLOBAL unique index on
 * serial_number: the chunk rolls back and the whole batch flips to `failed`.
 *
 * It was unreachable while nothing re-ran generation after creation. This
 * feature re-runs it deliberately. The regression test below is written against
 * the exact shape — generate, delete a MIDDLE code, extend — because a test that
 * deletes the LAST code, or a prefix of the range, passes under both the broken
 * and the fixed logic.
 */
class SmartQrBatchAddCodesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-add-codes-test'],
            ['name' => 'QR Add Codes Test Role', 'description' => 'test']
        );

        foreach (['manage_qr_batches', 'view_qr_inventory'] as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'QR Management']
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    private function batch(
        int $quantity = 10,
        int $serialStart = 1,
        string $prefix = 'AX',
        string $status = 'generated',
    ): SmartQrBatch {
        return SmartQrBatch::create([
            'batch_number' => 'AX-BK-'.Str::upper(Str::random(6)),
            'batch_name' => 'Business Kit',
            'prefix' => $prefix,
            'quantity' => $quantity,
            'serial_start' => $serialStart,
            'status' => $status,
        ]);
    }

    private function action(): GenerateQrBatchAction
    {
        return app(GenerateQrBatchAction::class);
    }

    /** @return list<string> */
    private function serials(SmartQrBatch $batch): array
    {
        return SmartQrCode::where('batch_id', $batch->id)
            ->orderBy('serial_number')->pluck('serial_number')->all();
    }

    // ══ The offset bug ═════════════════════════════════════════════════════

    /**
     * ⚠️ THE REGRESSION. Under the count() logic this throws a duplicate-key
     * QueryException and leaves the batch `failed`.
     */
    #[Test]
    public function extending_after_a_middle_code_was_deleted_does_not_reissue_a_serial(): void
    {
        $batch = $this->batch(quantity: 10);
        $this->action()->execute($batch);

        // Exactly the hard delete QrInventoryController::destroy performs.
        SmartQrCode::where('serial_number', 'AX-000005')->delete();
        $this->assertSame(9, SmartQrCode::where('batch_id', $batch->id)->count());

        $batch->increment('quantity', 5);

        $committed = $this->action()->execute($batch->fresh());

        $this->assertSame(5, $committed, 'Should generate exactly the 5 new codes.');
        $this->assertSame('generated', $batch->fresh()->status,
            'The batch failed — generation re-issued a serial that already existed.');

        $this->assertSame(
            ['AX-000011', 'AX-000012', 'AX-000013', 'AX-000014', 'AX-000015'],
            array_values(array_diff($this->serials($batch), [
                'AX-000001', 'AX-000002', 'AX-000003', 'AX-000004', 'AX-000006',
                'AX-000007', 'AX-000008', 'AX-000009', 'AX-000010',
            ])),
            'The new codes must continue past the highest existing serial.'
        );
    }

    /**
     * ⚠️ The deleted serial is NOT refilled. Re-issuing it would print a second
     * sticker carrying a number an earlier sticker already used.
     */
    #[Test]
    public function a_deleted_serial_is_never_reissued(): void
    {
        $batch = $this->batch(quantity: 10);
        $this->action()->execute($batch);

        SmartQrCode::where('serial_number', 'AX-000005')->delete();

        $batch->increment('quantity', 3);
        $this->action()->execute($batch->fresh());

        $this->assertNotContains('AX-000005', $this->serials($batch));
    }

    /**
     * ⚠️ Generation must never emit a serial past the range the batch claims.
     * Topping up to restore a COUNT would spill one serial into whatever batch
     * owns the next range — a collision SerialRangeAvailable cannot see, because
     * the range on record still looks correct.
     */
    #[Test]
    public function generation_never_exceeds_the_declared_range_after_a_deletion(): void
    {
        $batch = $this->batch(quantity: 10);
        $this->action()->execute($batch);
        SmartQrCode::where('serial_number', 'AX-000005')->delete();

        $this->assertSame(0, $this->action()->execute($batch->fresh()),
            'The range is fully generated; a deletion must not license a serial past its end.');
        $this->assertNotContains('AX-000011', $this->serials($batch));
    }

    /**
     * ⚠️ nextOffset() extracts the number rather than taking MAX(serial_number).
     * serial_start has no upper bound, so a batch crossing into 7 digits sorts
     * 'AX-1000000' BELOW 'AX-999999' as a string and the resume point moves
     * backwards — straight into a duplicate.
     */
    #[Test]
    public function the_resume_point_is_numeric_not_lexicographic(): void
    {
        $batch = $this->batch(quantity: 3, serialStart: 999_999);
        $this->action()->execute($batch);

        $this->assertSame(['AX-1000000', 'AX-1000001', 'AX-999999'], $this->serials($batch),
            'Sanity: string ordering really does put the 7-digit serials first.');

        $this->assertSame(3, GenerateQrBatchAction::nextOffset($batch->fresh()),
            'Resuming from the string maximum would return 0 and duplicate every serial.');
    }

    // ══ generated_at ═══════════════════════════════════════════════════════

    #[Test]
    public function generated_at_is_not_overwritten_by_a_later_extension(): void
    {
        $batch = $this->batch(quantity: 5);
        $this->action()->execute($batch);

        $first = $batch->fresh()->generated_at;
        $this->assertNotNull($first);

        $this->travel(2)->days();

        $batch->increment('quantity', 5);
        $this->action()->execute($batch->fresh());

        $this->assertEquals($first, $batch->fresh()->generated_at,
            'generated_at answers "when was this batch made" — an extension must not restamp it.');
    }

    /**
     * ⚠️ Show.jsx renders failure_reason on its own truthiness, NOT gated on
     * status — so a stale reason paints a red error panel over a batch that has
     * just succeeded. Extension is allowed from `failed`, which is exactly how
     * that combination arises.
     */
    #[Test]
    public function a_successful_extension_clears_a_previous_failure_reason(): void
    {
        $batch = $this->batch(quantity: 5, status: 'failed');
        $batch->forceFill(['failure_reason' => 'Duplicate entry', 'failed_at' => now()])->save();

        $this->action()->execute($batch);

        $fresh = $batch->fresh();
        $this->assertNull($fresh->failure_reason);
        $this->assertNull($fresh->failed_at);
        $this->assertSame('generated', $fresh->status);
    }

    // ══ The endpoint ═══════════════════════════════════════════════════════

    #[Test]
    public function an_admin_can_add_codes_to_a_generated_batch(): void
    {
        Queue::fake();
        $batch = $this->batch(quantity: 10);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 40])
            ->assertSessionHasNoErrors();

        $this->assertSame(50, $batch->fresh()->quantity);
        Queue::assertPushed(GenerateQrBatchJob::class);
    }

    #[Test]
    public function the_new_codes_continue_contiguously_from_the_existing_range(): void
    {
        $batch = $this->batch(quantity: 5);
        $this->action()->execute($batch);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 3])
            ->assertSessionHasNoErrors();

        $this->action()->execute($batch->fresh());

        $this->assertSame(
            ['AX-000001', 'AX-000002', 'AX-000003', 'AX-000004', 'AX-000005',
                'AX-000006', 'AX-000007', 'AX-000008'],
            $this->serials($batch)
        );
    }

    // ══ Polling handoff ════════════════════════════════════════════════════

    /**
     * ⚠️ THE HANDOFF TO THE PAGE'S AUTO-REFRESH, AND IT DOES NOT COME FREE.
     *
     * Show.jsx polls while status is in ['draft','generating']. `generating` IS
     * already in that allow-list, which makes it tempting to conclude extension
     * is covered with no work — the conclusion this test exists to disprove.
     *
     * GenerateQrBatchAction writes `generating` on the WORKER. Before the
     * controller wrote it synchronously, the redirect re-rendered show() while
     * the batch still said `generated`, the guard evaluated false, and the admin
     * watched a static page while codes appeared in the database behind it.
     *
     * Asserted against the ACTUAL Inertia prop rather than the database row,
     * because the prop is what the guard reads.
     */
    #[Test]
    public function extending_leaves_the_batch_in_a_status_the_page_polls_on(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $batch = $this->batch(quantity: 10);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 5])
            ->assertSessionHasNoErrors();

        $this->assertSame('generating', $batch->fresh()->status,
            'The controller must move the status itself — the job does it on the worker, too late.');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.batches.show', $batch))
            ->assertInertia(fn ($page) => $page->where('batch.status', 'generating'));
    }

    /**
     * ⚠️ Pins the ALLOW-LIST MEMBERSHIP itself, so the two halves cannot drift
     * apart silently. If someone renames the in-flight status, or trims
     * Show.jsx's LIVE_BATCH_STATUSES, the handoff breaks with nothing failing —
     * the extension still works, the page just stops updating.
     */
    #[Test]
    public function the_post_extension_status_is_one_the_frontend_watches(): void
    {
        Queue::fake();
        $batch = $this->batch(quantity: 10);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 5]);

        $this->assertContains($batch->fresh()->status, ['draft', 'generating'],
            'Must match LIVE_BATCH_STATUSES in resources/js/Pages/Admin/SmartQr/Batches/Show.jsx.');
    }

    /**
     * ⚠️ THE RACE THE GATE CLAIMED TO PREVENT AND DID NOT. Measured before the
     * synchronous status write: two submits both passed, quantity went 10 -> 20
     * and TWO GenerateQrBatchJobs were queued against one batch — each resuming
     * from a high-water mark the other was still moving.
     */
    #[Test]
    public function a_second_extension_is_refused_while_the_first_is_still_generating(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $batch = $this->batch(quantity: 10);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 5])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 5])
            ->assertSessionHasErrors('additional_quantity');

        $this->assertSame(15, $batch->fresh()->quantity, 'The second submit must not add a second time.');
        Queue::assertPushed(GenerateQrBatchJob::class, 1);
    }

    // ══ Status gate ════════════════════════════════════════════════════════

    /**
     * ⚠️ ALL FIVE STATUSES, and the two permitted ones are asserted in the same
     * test as the three refused. A rejection test alone cannot distinguish "the
     * gate works" from "the endpoint refuses everyone".
     */
    #[Test]
    public function codes_can_only_be_added_to_a_generated_or_failed_batch(): void
    {
        Queue::fake();
        $admin = $this->admin();

        // ⚠️ EACH BATCH GETS ITS OWN SERIAL WINDOW. Five batches all starting at
        // 1 under one prefix overlap each other, and SerialRangeAvailable
        // (correctly) rejects the second — which would fail this test for a
        // reason that has nothing to do with the status gate it is testing.
        $window = 1;

        foreach (['generated' => true, 'failed' => true, 'draft' => false,
            'generating' => false, 'printed' => false] as $status => $allowed) {
            $batch = $this->batch(quantity: 10, serialStart: $window, status: $status);
            $window += 1_000;

            $response = $this->actingAs($admin, 'admin')
                ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 5]);

            if ($allowed) {
                $response->assertSessionHasNoErrors();
                $this->assertSame(15, $batch->fresh()->quantity, "[$status] should have been extended");
            } else {
                $response->assertSessionHasErrors('additional_quantity');
                $this->assertSame(10, $batch->fresh()->quantity, "[$status] must not change quantity");
            }
        }
    }

    // ══ Quantity ceiling ═══════════════════════════════════════════════════

    #[Test]
    public function the_combined_total_may_reach_but_not_exceed_the_maximum(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $exact = $this->batch(quantity: SmartQrBatch::MAX_QUANTITY - 100);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.batches.add-codes', $exact), ['additional_quantity' => 100])
            ->assertSessionHasNoErrors();
        $this->assertSame(SmartQrBatch::MAX_QUANTITY, $exact->fresh()->quantity,
            'Exactly the ceiling must be allowed — the limit is inclusive.');

        $over = $this->batch(quantity: SmartQrBatch::MAX_QUANTITY - 100);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.batches.add-codes', $over), ['additional_quantity' => 101])
            ->assertSessionHasErrors('additional_quantity');
        $this->assertSame(SmartQrBatch::MAX_QUANTITY - 100, $over->fresh()->quantity);
    }

    #[Test]
    public function the_ceiling_is_the_same_constant_batch_creation_enforces(): void
    {
        $this->assertSame(10_000, SmartQrBatch::MAX_QUANTITY);
    }

    // ══ SerialRangeAvailable / ignoreBatchId ═══════════════════════════════

    /**
     * ⚠️ THE ignoreBatchId PROOF. The batch being extended overlaps its OWN
     * range by definition; without the exclusion every extension would be
     * rejected as colliding with itself.
     */
    #[Test]
    public function extending_is_not_rejected_as_colliding_with_the_batch_own_range(): void
    {
        Queue::fake();
        $batch = $this->batch(quantity: 100, serialStart: 1);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 50])
            ->assertSessionHasNoErrors();

        $this->assertSame(150, $batch->fresh()->quantity);
    }

    /**
     * POSITIVE CONTROL for the test above: the exclusion must not have disabled
     * the check. A different batch holding the target serials still blocks it.
     */
    #[Test]
    public function extending_into_another_batch_range_is_rejected(): void
    {
        Queue::fake();
        $this->batch(quantity: 100, serialStart: 200);          // owns 200..299
        $batch = $this->batch(quantity: 100, serialStart: 1);   // owns 1..100

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 250])
            ->assertSessionHasErrors('serial_start');

        $this->assertSame(100, $batch->fresh()->quantity, 'A rejected extension must not raise quantity.');
    }

    /**
     * ⚠️ A DIFFERENT PREFIX IS A DIFFERENT NUMBER SPACE. Serials are globally
     * unique as STRINGS, so BX-000250 cannot collide with AX-000250.
     */
    #[Test]
    public function a_range_held_under_a_different_prefix_does_not_block_extension(): void
    {
        Queue::fake();
        $this->batch(quantity: 100, serialStart: 200, prefix: 'BX');
        $batch = $this->batch(quantity: 100, serialStart: 1, prefix: 'AX');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 250])
            ->assertSessionHasNoErrors();
    }

    /**
     * ⚠️ serial_start is injected from the BATCH. A forged value in the payload
     * must not steer the range check at a window the batch does not occupy.
     */
    #[Test]
    public function a_forged_serial_start_in_the_payload_is_ignored(): void
    {
        Queue::fake();
        $this->batch(quantity: 100, serialStart: 200);
        $batch = $this->batch(quantity: 100, serialStart: 1);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), [
                'additional_quantity' => 250,
                'serial_start' => 5_000,   // a window nobody holds
            ])
            ->assertSessionHasErrors('serial_start');

        $this->assertSame(1, $batch->fresh()->serial_start, 'serial_start must never be writable here.');
    }

    // ══ Validation + permission ════════════════════════════════════════════

    #[Test]
    public function additional_quantity_must_be_a_positive_integer(): void
    {
        Queue::fake();
        $admin = $this->admin();

        foreach ([0, -5, 'abc', null] as $bad) {
            $batch = $this->batch(quantity: 10);
            $this->actingAs($admin, 'admin')
                ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => $bad])
                ->assertSessionHasErrors('additional_quantity');
            $this->assertSame(10, $batch->fresh()->quantity);
        }
    }

    /**
     * ⚠️ RequirePermission redirects HTML requests rather than returning 403,
     * so this asserts the EFFECT — quantity unchanged — not a status code.
     */
    #[Test]
    public function an_admin_without_manage_qr_batches_cannot_add_codes(): void
    {
        Queue::fake();
        $batch = $this->batch(quantity: 10);

        $this->actingAs(AdminUser::factory()->create(), 'admin')
            ->post(route('admin.qr.batches.add-codes', $batch), ['additional_quantity' => 5]);

        $this->assertSame(10, $batch->fresh()->quantity);
        Queue::assertNotPushed(GenerateQrBatchJob::class);
    }

    // ══ Existing rows are untouched ════════════════════════════════════════

    /**
     * ⚠️ Extension is purely additive. Pins that an assigned/printed code keeps
     * its status, its token and its assignment across a generation re-run.
     */
    #[Test]
    public function existing_codes_and_their_statuses_survive_an_extension(): void
    {
        $batch = $this->batch(quantity: 5);
        $this->action()->execute($batch);

        $first = SmartQrCode::where('serial_number', 'AX-000001')->firstOrFail();
        $first->forceFill(['status' => 'printed', 'printed_at' => now()])->save();
        $before = $first->only(['id', 'serial_number', 'public_token', 'status']);

        $batch->increment('quantity', 5);
        $this->action()->execute($batch->fresh());

        $after = SmartQrCode::findOrFail($before['id'])->only(['id', 'serial_number', 'public_token', 'status']);

        $this->assertSame($before, $after, 'An extension must not modify any existing code row.');
        $this->assertSame(10, SmartQrCode::where('batch_id', $batch->id)->count());
    }
}
