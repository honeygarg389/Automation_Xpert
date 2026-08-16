<?php

namespace Tests\Feature\SmartQr;

use App\Modules\SmartQr\Console\Commands\AggregateSmartQrStatsCommand;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrConversionEvent;
use App\Modules\SmartQr\Models\SmartQrDailyStat;
use App\Modules\SmartQr\Models\SmartQrScanEvent;
use App\Modules\SmartQr\Services\SmartQrAggregator;
use App\Modules\SmartQr\Services\SmartQrMetrics;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 7 — aggregates, retention, and the interlock between them.
 *
 * ─── ⚠️ THE TEST THIS FILE EXISTS FOR ───────────────────────────────────────
 *
 * `aggregating_a_pruned_day_refuses_and_leaves_the_existing_row_untouched`.
 *
 * HAZARD H-4: two individually correct jobs that together erase the history the
 * prune exists to preserve. The prune deletes raw rows older than the window;
 * the aggregator recomputes a day from raw rows. Run the second on a day the
 * first has cleared and it computes ZERO and overwrites the only surviving copy
 * of that day's numbers. Nothing errors, nothing logs.
 *
 * ⚠️ The discriminator is THE EXISTING ROW UNCHANGED, not that an exception was
 * thrown — an implementation that throws AFTER writing zeros passes an
 * exception-only assertion.
 */
class SmartQrAggregateAndPruneTest extends TestCase
{
    use RefreshDatabase;

    private function assignment(): SmartQrAssignment
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        return SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => SmartQrCode::factory()->create()->id,
            'workspace_id' => $workspace->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ]);
    }

    private function scan(SmartQrAssignment $a, string $at, bool $unique = true, bool $bot = false): void
    {
        SmartQrScanEvent::create([
            'smart_qr_assignment_id' => $a->id,
            'scanned_at' => $at,
            'ip_hash' => str_repeat('a', 64),
            'ua_hash' => str_repeat('b', 64),
            'is_unique' => $unique,
            'is_bot' => $bot,
        ]);
    }

    private function conversion(SmartQrAssignment $a, string $type, string $at, ?int $contactId = 1): void
    {
        $sessionId = DB::table('smart_qr_attribution_sessions')->insertGetId([
            'token' => strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'smart_qr_assignment_id' => $a->id,
            'issued_at' => $at, 'expires_at' => $at,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        SmartQrConversionEvent::create([
            'smart_qr_assignment_id' => $a->id,
            'attribution_session_id' => $sessionId,
            'type' => $type,
            'contact_id' => $contactId,
            'occurred_at' => $at,
        ]);
    }

    // ══ ⚠️ HAZARD H-4 — THE INTERLOCK ═════════════════════════════════════

    /**
     * ⚠️ THE ONE. A day outside the retention window is refused, and the
     * aggregate that already exists for it is left exactly as it was.
     */
    #[Test]
    public function aggregating_a_pruned_day_refuses_and_leaves_the_existing_row_untouched(): void
    {
        $a = $this->assignment();
        $old = now()->subDays(200)->startOfDay();

        // The correct historical aggregate — the only surviving copy of that day.
        $row = SmartQrDailyStat::create([
            'smart_qr_assignment_id' => $a->id,
            'stat_date' => $old->toDateString(),
            'scans' => 412, 'unique_scans' => 380, 'bot_scans' => 9,
            'attributed_messages' => 44, 'attributed_unique_contacts' => 41,
            'attributed_new_contacts' => 30, 'attributed_conversations_started' => 28,
        ]);

        // ⚠️ THE REALISTIC POST-PRUNE STATE, and the first version of this test
        // got it wrong.
        //
        // The prune deletes SCAN rows only — conversion events are never pruned.
        // So a pruned day still has conversions, which means the assignment IS
        // still in the aggregator's id set and its scan figures DO get
        // recomputed to zero. With no conversions at all the aggregator writes
        // nothing and the hazard cannot fire, which made the original fixture
        // unfalsifiable: removing the refusal left the test green.
        $this->conversion($a, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED, $old->copy()->addHours(9)->toDateTimeString(), contactId: 5);

        $this->assertSame(0, (int) DB::table('smart_qr_scan_events')->count(), 'Precondition: scans pruned.');
        $this->assertSame(1, (int) DB::table('smart_qr_conversion_events')->count(), 'Precondition: conversions survive the prune.');

        try {
            app(SmartQrAggregator::class)->aggregate($old);
            $this->fail('The aggregator recomputed a day outside the retention window. With the '
                .'raw rows pruned it can only produce ZERO, and updateOrCreate would overwrite '
                .'the only copy of that day that still exists.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('retention window', $e->getMessage());
        }

        // ⚠️ THE DISCRIMINATOR. An implementation that throws AFTER writing
        // zeros passes the assertion above and fails this one.
        $row->refresh();
        $this->assertSame(412, $row->scans,
            'The historical aggregate was overwritten with zeros before the refusal. That is '
            .'HAZARD H-4: two individually correct jobs erasing the history the prune exists to '
            .'preserve, silently.');
        $this->assertSame(41, $row->attributed_unique_contacts);
    }

    /** POSITIVE CONTROL: a day INSIDE the window is computed normally. */
    #[Test]
    public function a_day_inside_the_retention_window_is_aggregated(): void
    {
        $a = $this->assignment();
        $day = now()->subDay()->startOfDay();

        $this->scan($a, $day->copy()->addHours(9)->toDateTimeString());
        $this->scan($a, $day->copy()->addHours(10)->toDateTimeString(), unique: false);
        $this->scan($a, $day->copy()->addHours(11)->toDateTimeString(), bot: true);

        $written = app(SmartQrAggregator::class)->aggregate($day);

        $this->assertSame(1, $written);

        $row = SmartQrDailyStat::firstOrFail();
        $this->assertSame(2, $row->scans, 'Bots must be excluded from scans.');
        $this->assertSame(1, $row->unique_scans);
        $this->assertSame(1, $row->bot_scans, 'Bots are counted separately, not discarded.');
    }

    /** ⚠️ No --force exists for the window, and none may be added. */
    #[Test]
    public function the_retention_refusal_has_no_override(): void
    {
        $signature = (new \ReflectionClass(AggregateSmartQrStatsCommand::class))
            ->newInstanceWithoutConstructor();

        $r = new \ReflectionProperty($signature, 'signature');
        $this->assertStringNotContainsString('force', $r->getValue($signature),
            'A --force option appeared on smartqr:aggregate. There is no correct reason to '
            .'recompute a pruned day: the raw data is gone, so the result can only be zero.');
    }

    // ══ Idempotency ════════════════════════════════════════════════════════

    #[Test]
    public function re_running_a_day_overwrites_rather_than_duplicating(): void
    {
        $a = $this->assignment();
        $day = now()->subDay()->startOfDay();
        $this->scan($a, $day->copy()->addHours(9)->toDateTimeString());

        $agg = app(SmartQrAggregator::class);
        $agg->aggregate($day);
        $agg->aggregate($day);

        $this->assertSame(1, SmartQrDailyStat::count(), 'A re-run duplicated the day.');
        $this->assertSame(1, SmartQrDailyStat::first()->scans);
    }

    /** A late-arriving scan is corrected by a re-run, not double-counted. */
    #[Test]
    public function a_re_run_corrects_a_late_arriving_scan(): void
    {
        $a = $this->assignment();
        $day = now()->subDay()->startOfDay();
        $this->scan($a, $day->copy()->addHours(9)->toDateTimeString());

        $agg = app(SmartQrAggregator::class);
        $agg->aggregate($day);
        $this->assertSame(1, SmartQrDailyStat::first()->scans);

        $this->scan($a, $day->copy()->addHours(23)->toDateTimeString());
        $agg->aggregate($day);

        $this->assertSame(2, SmartQrDailyStat::first()->scans,
            'The re-run did not pick up the late scan, or it added a second row.');
    }

    // ══ §12's rate ═════════════════════════════════════════════════════════

    /**
     * ⚠️ DISTINCT contacts, not a count of events.
     *
     * Two messages from one person are ONE customer. Counting events inflates
     * the numerator and can push §12's rate above 100%.
     */
    #[Test]
    public function unique_contacts_counts_people_not_messages(): void
    {
        $a = $this->assignment();
        $day = now()->subDay()->startOfDay();
        $at = $day->copy()->addHours(9)->toDateTimeString();

        $this->conversion($a, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED, $at, contactId: 7);
        $this->conversion($a, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED, $at, contactId: 7);
        $this->conversion($a, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED, $at, contactId: 8);

        app(SmartQrAggregator::class)->aggregate($day);

        $row = SmartQrDailyStat::firstOrFail();
        $this->assertSame(3, $row->attributed_messages, 'Messages count events.');
        $this->assertSame(2, $row->attributed_unique_contacts,
            'Unique contacts counted messages rather than people. §12 divides by this, so the '
            .'rate would exceed 100% for any customer who sent two messages.');
    }

    /**
     * ⚠️ §12's rate divides UNIQUE CONTACTS by unique valid scans — not
     * messages by scans.
     *
     * The numbers are chosen so the two formulas give DIFFERENT answers: 4
     * messages from 2 people over 8 unique scans is 25% by §12's definition and
     * 50% by slice 6's original one. Equal numbers would let the wrong formula
     * pass by coincidence.
     */
    #[Test]
    public function the_rate_uses_unique_contacts_not_message_count(): void
    {
        $a = $this->assignment();
        $at = now()->toDateTimeString();

        for ($i = 0; $i < 8; $i++) {
            $this->scan($a, $at);
        }

        // 4 messages, 2 distinct people.
        $this->conversion($a, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED, $at, contactId: 11);
        $this->conversion($a, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED, $at, contactId: 11);
        $this->conversion($a, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED, $at, contactId: 12);
        $this->conversion($a, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED, $at, contactId: 12);

        $kpis = app(SmartQrMetrics::class)
            ->overview((int) $a->workspace_id);

        $this->assertSame(8, $kpis['unique_scans']);
        $this->assertSame(4, $kpis['attributed_messages']);
        $this->assertSame(25.0, $kpis['attributed_message_rate'],
            'The rate divided MESSAGES by scans (50%) rather than UNIQUE CONTACTS by unique '
            .'valid scans (25%). §12 is explicit, and counting messages lets one talkative '
            .'customer push a conversion rate above 100%.');
    }

    /** ⚠️ null on zero scans, never 0 — "0%" claims nobody responded. */
    #[Test]
    public function the_rate_is_null_rather_than_zero_when_nothing_has_happened(): void
    {
        $a = $this->assignment();

        $kpis = app(SmartQrMetrics::class)
            ->overview((int) $a->workspace_id);

        $this->assertNull($kpis['attributed_message_rate'],
            '0% reads as "nobody responded"; null reads as "nothing has happened yet", which is '
            .'the truth for a QR nobody has scanned.');
    }

    // ══ ⚠️ THE PRUNE ═══════════════════════════════════════════════════════

    /** ⚠️ GUARD 2 — a day with no aggregate row is refused outright. */
    #[Test]
    public function the_prune_refuses_a_day_that_was_never_aggregated(): void
    {
        $a = $this->assignment();
        $old = now()->subDays(120)->startOfDay();
        $this->scan($a, $old->copy()->addHours(9)->toDateTimeString());

        $this->artisan('smartqr:prune-scans --force')
            ->expectsOutputToContain('no aggregate row')
            ->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('smart_qr_scan_events')->count(),
            'Raw scans were deleted for a day nothing had summarised. That destroys the data AND '
            .'the summary that was meant to outlive it — the whole reason this command was '
            .'deferred to the slice that builds the aggregates.');
    }

    /** POSITIVE CONTROL: once aggregated, the same day IS pruned. */
    #[Test]
    public function the_prune_deletes_an_aggregated_day_outside_the_window(): void
    {
        $a = $this->assignment();
        $old = now()->subDays(120)->startOfDay();
        $this->scan($a, $old->copy()->addHours(9)->toDateTimeString());

        SmartQrDailyStat::create([
            'smart_qr_assignment_id' => $a->id,
            'stat_date' => $old->toDateString(),
            'scans' => 1, 'unique_scans' => 1,
        ]);

        $this->artisan('smartqr:prune-scans --force')->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('smart_qr_scan_events')->count(),
            'The prune refuses everything, so the guard test above proves nothing.');
        $this->assertSame(1, SmartQrDailyStat::count(), 'The aggregate must survive the prune.');
    }

    /** ⚠️ GUARD 5 — dry run touches nothing. */
    #[Test]
    public function the_prune_dry_run_deletes_nothing(): void
    {
        $a = $this->assignment();
        $old = now()->subDays(120)->startOfDay();
        $this->scan($a, $old->copy()->addHours(9)->toDateTimeString());
        SmartQrDailyStat::create([
            'smart_qr_assignment_id' => $a->id, 'stat_date' => $old->toDateString(), 'scans' => 1,
        ]);

        $this->artisan('smartqr:prune-scans --dry-run')
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        $this->assertSame(1, (int) DB::table('smart_qr_scan_events')->count(),
            'A dry run deleted rows.');
    }

    /** ⚠️ GUARD 3 — the window cannot be SHORTENED, and --force does not widen it. */
    #[Test]
    public function the_prune_refuses_a_window_shorter_than_configured(): void
    {
        $a = $this->assignment();
        $recent = now()->subDays(10)->startOfDay();
        $this->scan($a, $recent->copy()->addHours(9)->toDateTimeString());

        $this->artisan('smartqr:prune-scans --days=5 --force')
            ->expectsOutputToContain('shorter than the configured retention')
            ->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('smart_qr_scan_events')->count(),
            'A --days shorter than the retention policy deleted data inside the window. --force '
            .'bypasses the production confirmation; it must never widen what is deleted.');
    }

    /** Scans INSIDE the window are never touched. */
    #[Test]
    public function the_prune_leaves_data_inside_the_window_alone(): void
    {
        $a = $this->assignment();
        $this->scan($a, now()->subDays(10)->toDateTimeString());

        $this->artisan('smartqr:prune-scans --force')->assertExitCode(0);

        $this->assertSame(1, (int) DB::table('smart_qr_scan_events')->count());
    }
}
