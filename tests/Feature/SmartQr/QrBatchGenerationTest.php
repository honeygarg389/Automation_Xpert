<?php

namespace Tests\Feature\SmartQr;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\SmartQr\Actions\GenerateQrBatchAction;
use App\Modules\SmartQr\Jobs\GenerateQrBatchJob;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smart QR slice 2 — batch generation.
 *
 * ─── ⚠️ WHAT THE "CONCURRENCY" TESTS HERE ACTUALLY PROVE ────────────────────
 *
 * **A real race cannot be tested in this suite, and pretending otherwise would
 * be the dishonest version of this file.**
 *
 * `RefreshDatabase` wraps every test in a transaction on ONE connection. Two
 * "racing" jobs invoked from a test therefore run sequentially inside that same
 * transaction — the second sees the first's uncommitted rows, and the unique
 * index is never contended by two sessions. A second connection would escape the
 * transaction, but its writes would then survive the test and pollute every
 * subsequent one; that is exactly how the slice-1 migration test broke three
 * unrelated tests, and it is not worth repeating for a simulation.
 *
 * So these tests prove:
 *
 *   ✅ the unique constraints EXIST and reject a duplicate at the database
 *   ✅ the generator's serials are collision-free by construction within a batch
 *   ✅ tokens are non-sequential and unpredictable
 *   ✅ the generator behaves correctly WHEN a constraint fires — which is the
 *      observable a real race produces
 *
 * They do NOT prove:
 *
 *   ❌ that two OS processes cannot interleave in a way not considered here
 *
 * **The actual guarantee is the database constraint**, not the test. A race
 * manifests as a unique-violation on insert, so injecting that violation
 * exercises the same code path a race would reach — without the race. That is
 * the compensation, and it is why the constraint is stash-checked separately.
 */
class QrBatchGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function batch(int $quantity = 10, int $serialStart = 1, string $prefix = 'AX'): SmartQrBatch
    {
        return SmartQrBatch::create([
            'batch_number' => 'AX-BK-'.Str::upper(Str::random(6)),
            'batch_name' => 'Business Kit',
            'prefix' => $prefix,
            'quantity' => $quantity,
            'serial_start' => $serialStart,
        ]);
    }

    private function action(): GenerateQrBatchAction
    {
        return app(GenerateQrBatchAction::class);
    }

    // ══ The happy path, only as a baseline ═════════════════════════════════

    #[Test]
    public function it_generates_the_requested_quantity_with_formatted_serials(): void
    {
        $batch = $this->batch(10, 1);

        $this->assertSame(10, $this->action()->execute($batch));

        $codes = SmartQrCode::where('batch_id', $batch->id)->orderBy('id')->get();
        $this->assertCount(10, $codes);
        $this->assertSame('AX-000001', $codes->first()->serial_number);
        $this->assertSame('AX-000010', $codes->last()->serial_number);

        $batch->refresh();
        $this->assertSame(10, (int) $batch->generated_count);
        $this->assertSame('generated', $batch->status);
        $this->assertNotNull($batch->generated_at);
    }

    /** serial_start is honoured, so a second print run continues the sequence. */
    #[Test]
    public function serials_begin_at_serial_start(): void
    {
        $batch = $this->batch(3, 500);
        $this->action()->execute($batch);

        $this->assertSame(
            ['AX-000500', 'AX-000501', 'AX-000502'],
            SmartQrCode::where('batch_id', $batch->id)->orderBy('id')->pluck('serial_number')->all()
        );
    }

    /** Chunking must not change the result — 250 crosses the 100 boundary twice. */
    #[Test]
    public function a_large_batch_crossing_chunk_boundaries_generates_exactly_once(): void
    {
        $batch = $this->batch(250, 1);

        $this->assertSame(250, $this->action()->execute($batch));
        $this->assertSame(250, SmartQrCode::where('batch_id', $batch->id)->count());
        $this->assertSame(250, SmartQrCode::where('batch_id', $batch->id)->distinct()->count('serial_number'));
        $this->assertSame(250, SmartQrCode::where('batch_id', $batch->id)->distinct()->count('public_token'));
        $this->assertSame(250, (int) $batch->fresh()->generated_count);
    }

    // ══ ⚠️ THE CONSTRAINT — the actual guarantee ═══════════════════════════

    /**
     * A duplicate serial is rejected by the DATABASE, not by application code.
     *
     * This is what a real race would produce: two workers computing the same
     * serial and both attempting an insert. One wins, the other gets this.
     */
    #[Test]
    public function the_database_rejects_a_duplicate_serial(): void
    {
        $batch = $this->batch(1);
        $this->action()->execute($batch);
        $existing = SmartQrCode::where('batch_id', $batch->id)->first();

        try {
            SmartQrCode::create([
                'serial_number' => $existing->serial_number,   // the collision
                'public_token' => GenerateQrBatchAction::token(),
                'batch_id' => $batch->id,
            ]);
            $this->fail('A duplicate serial was accepted. The unique index is the ONLY thing '
                .'standing between two concurrent workers and two codes bearing one printed '
                .'identifier.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->getMessage()));
        }
    }

    /** …and a duplicate public token, which is the tenant-addressing one. */
    #[Test]
    public function the_database_rejects_a_duplicate_public_token(): void
    {
        $batch = $this->batch(1);
        $this->action()->execute($batch);
        $existing = SmartQrCode::where('batch_id', $batch->id)->first();

        $this->expectException(QueryException::class);

        SmartQrCode::create([
            'serial_number' => 'AX-999999',
            'public_token' => $existing->public_token,   // the collision
            'batch_id' => $batch->id,
        ]);
    }

    /**
     * ⚠️ The generator must FAIL LOUDLY when the constraint fires, not swallow it.
     *
     * A race's observable is a unique violation mid-generation. If the action
     * caught and continued, a batch would silently generate fewer codes than
     * ordered and report success.
     */
    #[Test]
    public function a_constraint_violation_mid_generation_fails_the_batch(): void
    {
        $batch = $this->batch(5, 1);

        // Pre-plant the serial the 3rd code will want. This is precisely what a
        // competing worker would have committed.
        SmartQrCode::create([
            'serial_number' => GenerateQrBatchAction::serialFor($batch, 2),
            'public_token' => GenerateQrBatchAction::token(),
            'batch_id' => $this->batch(1, 9000)->id,
        ]);

        try {
            $this->action()->execute($batch);
            $this->fail('Generation swallowed a unique violation. The batch would report '
                .'success while holding fewer codes than ordered.');
        } catch (QueryException) {
            // expected
        }

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
        $this->assertNotNull($batch->failure_reason);
    }

    // ══ Tokens ═════════════════════════════════════════════════════════════

    /**
     * ⚠️ Non-sequential and unpredictable. The token addresses a tenant from an
     * unauthenticated request; a guessable one enumerates every customer's
     * destination.
     */
    #[Test]
    public function tokens_are_non_sequential_and_unrelated_to_the_serial(): void
    {
        $batch = $this->batch(20, 1);
        $this->action()->execute($batch);

        $codes = SmartQrCode::where('batch_id', $batch->id)->orderBy('id')->get();
        $tokens = $codes->pluck('public_token');

        $this->assertCount(20, $tokens->unique(), 'Tokens collided within one batch.');
        $this->assertCount(1, $tokens->map(fn ($t) => strlen($t))->unique(), 'Uneven token length.');
        $this->assertSame(32, strlen($tokens->first()), '128 bits of entropy expected.');

        // Sequential tokens would sort into the same order as the serials.
        $bySerial = $codes->sortBy('serial_number')->pluck('public_token')->values()->all();
        $sorted = $tokens->sort()->values()->all();
        $this->assertNotSame($sorted, $bySerial,
            'Token order tracks serial order — the tokens are sequential, and a stranger who '
            .'sees one can guess the next.');

        // ⚠️ The first version of this asserted the token did not contain the
        // serial's digits — nonsense, since a 32-char hex string contains "1"
        // essentially always. It failed immediately, which is the only reason it
        // was not shipped as a passing-but-meaningless assertion.
        //
        // The real question is whether the token is DERIVED from the serial. Two
        // batches sharing a serial range answer it: derived tokens would match.
        $other = $this->batch(20, 1, 'ZZ');
        $this->action()->execute($other);

        $mine = $codes->pluck('public_token')->sort()->values()->all();
        $theirs = SmartQrCode::where('batch_id', $other->id)
            ->pluck('public_token')->sort()->values()->all();

        $this->assertSame([], array_intersect($mine, $theirs),
            'Two batches over the SAME serial range produced overlapping tokens — the token is '
            .'derived from the serial rather than drawn from random_bytes, so knowing a printed '
            .'serial would yield the secret token.');
    }

    /**
     * ⚠️ A GAP SLICE 1 DID NOT ANTICIPATE, found by writing this file.
     *
     * `serial_number` is GLOBALLY unique, so `prefix + serial_start + quantity`
     * defines a range that must not overlap any other batch's. Nothing prevents
     * an administrator creating two batches with prefix `AX` starting at 1.
     *
     * The failure is safe — the unique index refuses, generation rolls back and
     * records the reason — but it arrives LATE: with a 500-code batch the
     * collision may not surface until the fifth chunk, after four have
     * committed. The operator sees a half-generated batch and a duplicate-key
     * message rather than "that range is taken".
     *
     * Recorded rather than fixed: the fix is a validation at batch CREATION
     * (slice 3's admin surface, which does not exist yet), not in the generator.
     */
    #[Test]
    public function overlapping_serial_ranges_collide_at_generation_time(): void
    {
        $first = $this->batch(5, 1, 'AX');
        $this->action()->execute($first);

        $overlapping = $this->batch(5, 1, 'AX');   // same prefix, same range

        try {
            $this->action()->execute($overlapping);
            $this->fail('Two batches over the same serial range both generated. serial_number '
                .'is globally unique, so one of these codes would carry a printed identifier '
                .'that belongs to another batch.');
        } catch (QueryException) {
            // expected — the DB refuses
        }

        $this->assertSame('failed', $overlapping->fresh()->status);
        $this->assertSame(0, SmartQrCode::where('batch_id', $overlapping->id)->count(),
            'The overlapping batch left orphans behind.');
    }

    // ══ ⚠️ ROLLBACK — the discriminator first ══════════════════════════════

    /**
     * ⚠️ WHAT A MISSING ROLLBACK PRODUCES, and therefore what to assert.
     *
     * Without the per-chunk transaction, a failure on the 3rd code of a 5-code
     * batch leaves the first two COMMITTED — orphans belonging to a batch that
     * failed. With it, zero rows survive.
     *
     * So the discriminator is `count == 0`, not "an exception was thrown": the
     * exception is thrown either way, and a test asserting only that would pass
     * with the transaction removed. That is the vacuous shape.
     *
     * ─── ⚠️ AND THIS TEST IS STILL VACUOUS FOR THE TRANSACTION. MEASURED. ───
     *
     * Stash-check: removing `DB::transaction` leaves this whole file GREEN.
     *
     * The reason is that a chunk is written by ONE multi-row `insert`, and a
     * single INSERT statement is already atomic in InnoDB — if any row violates
     * a constraint the entire statement fails and nothing lands. The "no
     * orphans" property is therefore guaranteed by the STATEMENT, not by the
     * transaction, and no assertion about orphaned rows can tell the two apart.
     *
     * What the transaction actually buys is binding the insert to the
     * `generated_count` increment that follows it: without it, a failure between
     * the two would leave the counter disagreeing with the rows. That is a
     * narrow window this suite cannot inject a failure into, so it is stated
     * here rather than asserted falsely.
     *
     * Keeping the transaction is still correct — the day a chunk becomes more
     * than one statement, it is the only thing standing between a partial write
     * and a consistent one. But this test does not prove it, and saying it does
     * would be exactly the dressing-up this project keeps catching.
     */
    #[Test]
    public function a_failure_mid_chunk_leaves_no_orphaned_codes(): void
    {
        $batch = $this->batch(5, 1);

        // Collide on the 3rd. The first two are inserted before it in the same
        // chunk, so they are exactly the rows a missing transaction would strand.
        SmartQrCode::create([
            'serial_number' => GenerateQrBatchAction::serialFor($batch, 2),
            'public_token' => GenerateQrBatchAction::token(),
            'batch_id' => $this->batch(1, 9000)->id,
        ]);

        try {
            $this->action()->execute($batch);
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, SmartQrCode::where('batch_id', $batch->id)->count(),
            'Codes from the failed chunk survived. Without the transaction the first two '
            .'inserts commit and the batch is left holding orphans it never finished — which '
            .'is what this assertion, and not the exception, detects.');

        $this->assertSame(0, (int) $batch->fresh()->generated_count,
            'generated_count advanced past rows that do not exist. It must move only on '
            .'commit, never optimistically.');
    }

    /**
     * ⚠️ THE FAILURE REASON MUST SURVIVE THE ROLLBACK.
     *
     * The obvious implementation writes it inside the transaction, where it is
     * rolled back with everything else — leaving a batch stuck at `generating`
     * with no explanation, indistinguishable from a crashed worker. Recording it
     * AFTER the rollback is the whole subtlety.
     */
    #[Test]
    public function the_failure_reason_survives_the_rollback(): void
    {
        $batch = $this->batch(5, 1);

        SmartQrCode::create([
            'serial_number' => GenerateQrBatchAction::serialFor($batch, 2),
            'public_token' => GenerateQrBatchAction::token(),
            'batch_id' => $this->batch(1, 9000)->id,
        ]);

        try {
            $this->action()->execute($batch);
        } catch (QueryException) {
            // expected
        }

        $batch->refresh();
        $this->assertSame('failed', $batch->status,
            'The batch is still marked generating. An operator sees a stuck batch with no '
            .'reason — worse than no rollback, because it is silent.');
        $this->assertNotNull($batch->failure_reason, 'No reason recorded.');
        $this->assertNotNull($batch->failed_at);
        $this->assertStringContainsString('duplicate', strtolower($batch->failure_reason));
    }

    // ══ Idempotency ════════════════════════════════════════════════════════

    /** A retried job tops up rather than duplicating. */
    #[Test]
    public function regenerating_a_partially_generated_batch_tops_up(): void
    {
        $batch = $this->batch(10, 1);

        SmartQrCode::create([
            'serial_number' => GenerateQrBatchAction::serialFor($batch, 0),
            'public_token' => GenerateQrBatchAction::token(),
            'batch_id' => $batch->id,
        ]);

        $this->assertSame(9, $this->action()->execute($batch), 'Should generate only the remainder.');
        $this->assertSame(10, SmartQrCode::where('batch_id', $batch->id)->count());
    }

    #[Test]
    public function the_job_delegates_to_the_action(): void
    {
        $batch = $this->batch(5, 1);
        $this->runJob(new GenerateQrBatchJob($batch->id), [$this->action()]);

        $this->assertSame(5, SmartQrCode::where('batch_id', $batch->id)->count());
    }

    // ══ ⚠️ R-5 — generation consumes NO entitlement ════════════════════════

    /**
     * Asserted, not assumed. Generation must touch no workspace's entitlement —
     * and structurally cannot, because `smart_qr_codes` has no `workspace_id`
     * and there is therefore no tenant to resolve one for.
     */
    #[Test]
    public function generation_consumes_no_workspace_entitlement(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $ws = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);

        $metersBefore = DB::table('usage_meters')->count();
        $cacheBefore = DB::table('workspace_entitlements')->count();

        $queries = [];
        DB::listen(fn ($q) => $queries[] = $q->sql);

        $this->action()->execute($this->batch(20, 1));

        $tenantTouching = array_filter($queries, fn ($sql) => str_contains($sql, 'usage_meters')
            || str_contains($sql, 'workspace_entitlements')
            || str_contains($sql, 'entitlement_grants'));

        $this->assertSame([], array_values($tenantTouching),
            'Generation queried an entitlement table. Codes are platform inventory at '
            .'generation; nothing is consumed until assignment (R-5).');

        $this->assertSame($metersBefore, DB::table('usage_meters')->count());
        $this->assertSame($cacheBefore, DB::table('workspace_entitlements')->count());
    }
}
