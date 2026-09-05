<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrDailyStat;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The QR Management > Dashboard admin overview.
 *
 * ─── ⚠️ WHAT THIS FILE PINS ─────────────────────────────────────────────────
 *
 *   the permission gate    matches the sibling QR screens: view_qr_inventory
 *   the trickier counts    Available, Active/Inactive, Assigned — each
 *                          seeded against a KNOWN state and asserted exactly,
 *                          not just "the page loaded"
 *   Retired                means status=retired SPECIFICALLY, not the union
 *                          of retired/lost/damaged — matches QrStatusBadge's
 *                          CODE_VARIANTS treating them as three distinct states
 *   Assigned               any current assignment, regardless of status or
 *                          whether its channel resolves to a dialable number
 */
class SmartQrDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $keys */
    private function adminWith(array $keys): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-dashboard-test-'.md5(implode(',', $keys))],
            ['name' => 'QR Dashboard Test', 'description' => 'test']
        );

        foreach ($keys as $key) {
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
     * A workspace with a plan, for the assignment rows below.
     *
     * @return array{workspace: Workspace, client: Client}
     */
    private function workspace(): array
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['smart_qr_max_assigned' => 50]]));

        return compact('workspace', 'client');
    }

    /** A channel whose phone_number_id resolves to a REAL WhatsappPhoneNumber row. */
    private function configuredChannel(int $workspaceId): ChannelAccount
    {
        $phoneNumberId = 'PN-'.uniqid();
        $channel = ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $workspaceId, 'channel' => 'whatsapp',
            'display_name' => 'Configured Line', 'phone_number_id' => $phoneNumberId, 'status' => 'active',
        ]);

        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $workspaceId]);
        WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => $phoneNumberId,
            'display_phone' => '+1 415-555-0100',
            'verified_name' => 'Configured Line',
        ]);

        return $channel;
    }

    /** A channel whose phone_number_id resolves to NOTHING — the UNCONFIGURED case. */
    private function unconfiguredChannel(int $workspaceId): ChannelAccount
    {
        return ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $workspaceId, 'channel' => 'whatsapp',
            'display_name' => 'Unconfigured Line', 'phone_number_id' => 'PN-'.uniqid(), 'status' => 'active',
        ]);
    }

    /**
     * The known, deterministic dataset every count in this file is checked
     * against: 2 batches, 10 codes across every physical + assignment state.
     */
    private function seedKnownState(): void
    {
        ['workspace' => $workspace] = $this->workspace();

        $batchOne = SmartQrBatch::factory()->create();
        SmartQrBatch::factory()->create(); // batch #2 — exercises total_batches > 1

        $code = fn (string $status) => SmartQrCode::factory()->create(['batch_id' => $batchOne->id, 'status' => $status]);

        // ── Physical states, none assigned ──────────────────────────────────
        $code(SmartQrStatus::CODE_RETIRED);
        $code(SmartQrStatus::CODE_RETIRED);
        $code(SmartQrStatus::CODE_DAMAGED);
        $code(SmartQrStatus::CODE_LOST);
        $code(SmartQrStatus::CODE_PRINTED); // printed, unassigned — counts toward Available
        $code(SmartQrStatus::CODE_GENERATED); // fully available — never touched by an assignment

        // ── Assigned, active, CONFIGURED (real phone) ───────────────────────
        $configuredChannel = $this->configuredChannel($workspace->id);
        $activeConfigured = $code(SmartQrStatus::CODE_GENERATED);
        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $activeConfigured->id, 'workspace_id' => $workspace->id,
            'channel_account_id' => $configuredChannel->id, 'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ]);

        // ── Assigned, active, UNCONFIGURED (no resolvable phone) ────────────
        $unconfiguredChannel = $this->unconfiguredChannel($workspace->id);
        $activeUnconfigured = $code(SmartQrStatus::CODE_GENERATED);
        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $activeUnconfigured->id, 'workspace_id' => $workspace->id,
            'channel_account_id' => $unconfiguredChannel->id, 'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ]);

        // ── Assigned, inactive, current ──────────────────────────────────────
        $inactiveCode = $code(SmartQrStatus::CODE_GENERATED);
        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $inactiveCode->id, 'workspace_id' => $workspace->id,
            'channel_account_id' => $configuredChannel->id, 'status' => SmartQrStatus::ASSIGNMENT_INACTIVE,
        ]);

        // ── ENDED assignment — must count as neither active/inactive, and its
        //    code must be AVAILABLE again (no current assignment) ────────────
        $endedCode = $code(SmartQrStatus::CODE_GENERATED);
        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $endedCode->id, 'workspace_id' => $workspace->id,
            'channel_account_id' => $configuredChannel->id, 'status' => SmartQrStatus::ASSIGNMENT_ENDED,
            'unassigned_at' => now()->subDay(),
        ]);

        // 10 codes total: 2 retired, 1 damaged, 1 lost, 1 printed-unassigned,
        // 1 fully-available-generated, 1 active-configured, 1 active-unconfigured,
        // 1 inactive-current, 1 ended.
        $this->assertSame(10, SmartQrCode::count(), 'Fixture must create exactly 10 codes.');
        $this->assertSame(2, SmartQrBatch::count(), 'Fixture must create exactly 2 batches.');
    }

    #[Test]
    public function viewing_the_dashboard_requires_the_view_permission(): void
    {
        $admin = $this->adminWith([]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.qr.dashboard'))
            ->assertForbidden();
    }

    /** POSITIVE CONTROL: same route, same verb, same admin type. */
    #[Test]
    public function viewing_the_dashboard_succeeds_with_the_view_permission(): void
    {
        $admin = $this->adminWith(['view_qr_inventory']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.dashboard'))
            ->assertOk();
    }

    #[Test]
    public function the_stat_cards_match_a_known_seeded_state_exactly(): void
    {
        $this->seedKnownState();
        $admin = $this->adminWith(['view_qr_inventory']);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.qr.dashboard'));

        $response->assertInertia(function ($page) {
            $stats = $page->toArray()['props']['stats'];

            $this->assertSame(10, $stats['total_codes']);
            $this->assertSame(2, $stats['total_batches']);

            // Retired means status=retired ONLY — damaged (1) and lost (1) are
            // NOT included, matching QR Inventory's own Retired badge/filter.
            $this->assertSame(2, $stats['retired'], 'Retired must not fold in damaged/lost.');

            $this->assertSame(1, $stats['printed']);

            // Available: printed-unassigned + fully-available-generated + the
            // ENDED code (its assignment closed, so it holds nothing current).
            $this->assertSame(3, $stats['available']);

            // Active/Inactive: CURRENT assignments only. The ended one counts
            // as neither.
            $this->assertSame(2, $stats['active']);
            $this->assertSame(1, $stats['inactive']);

            // Assigned: all three CURRENT assignments. The active assignment
            // without a dialable number and the inactive assignment with one
            // deliberately distinguish this from the former "Configured" query.
            $this->assertSame(3, $stats['assigned']);
        });
    }

    #[Test]
    public function assigned_equals_active_plus_inactive_for_current_assignments(): void
    {
        $this->seedKnownState();
        $stats = $this->dashboardProps()['stats'];

        $this->assertSame(3, $stats['assigned'],
            'Every current assignment must count even when inactive or missing a dialable number.');
        $this->assertSame($stats['assigned'], $stats['active'] + $stats['inactive'],
            'Assigned must be exactly the partition of current assignments into active and inactive.');
    }

    #[Test]
    public function recent_batches_and_assignments_are_present_with_expected_shape(): void
    {
        $this->seedKnownState();
        $admin = $this->adminWith(['view_qr_inventory']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.dashboard'))
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertNotEmpty($props['recentBatches'], 'No rows — the shape assertion below would pass vacuously.');
                $this->assertArrayHasKey('batch_name', $props['recentBatches'][0]);
                $this->assertArrayHasKey('quantity', $props['recentBatches'][0]);
                $this->assertArrayHasKey('status', $props['recentBatches'][0]);

                $this->assertNotEmpty($props['recentAssignments']);
                $this->assertArrayHasKey('workspace_name', $props['recentAssignments'][0]);
                $this->assertArrayHasKey('serial_number', $props['recentAssignments'][0]);
            });
    }

    #[Test]
    public function recent_activity_surfaces_smart_qr_audit_entries(): void
    {
        $admin = $this->adminWith(['manage_qr_batches', 'view_qr_inventory']);

        $this->actingAs($admin, 'admin')->post(route('admin.qr.batches.store'), [
            'batch_name' => 'Dashboard Activity Batch',
            'batch_number' => 'DASH-'.uniqid(),
            'prefix' => 'DASHACT',
            'quantity' => 5,
            'serial_start' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.dashboard'))
            ->assertInertia(function ($page) {
                $activity = $page->toArray()['props']['recentActivity'];

                $this->assertNotEmpty($activity);
                $this->assertContains('smart_qr.batch_created', array_column($activity, 'action'));
            });
    }

    // ══ Near Expire ════════════════════════════════════════════════════════

    /**
     * An assignment on its own code, with a given expiry and state.
     *
     * @param  array<string, mixed>  $over
     */
    private function expiring(int $workspaceId, string $serial, ?string $expiresAt, array $over = []): void
    {
        $this->makeAssignment($workspaceId, $serial, array_merge(['expires_at' => $expiresAt], $over));
    }

    /**
     * An assignment on its own code (the unique index allows one open period
     * per code), active by default, with any column overridable.
     *
     * @param  array<string, mixed>  $over
     */
    private function makeAssignment(int $workspaceId, string $serial, array $over = []): SmartQrAssignment
    {
        $code = SmartQrCode::factory()->create(['serial_number' => $serial]);

        return SmartQrAssignment::factory()->create(array_merge([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspaceId,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ], $over));
    }

    /** @return array<string, mixed> the dashboard's props, as the page receives them */
    private function dashboardProps(): array
    {
        $props = [];

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->get(route('admin.qr.dashboard'))
            ->assertInertia(function ($page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** @return list<string> the serials the panel returned, in order */
    private function nearExpiringSerials(): array
    {
        $serials = [];

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->get(route('admin.qr.dashboard'))
            ->assertInertia(function ($page) use (&$serials) {
                $serials = array_column($page->toArray()['props']['nearExpiring'], 'serial_number');
            });

        return $serials;
    }

    /**
     * ⚠️ EXCLUSIONS ASSERTED BY SERIAL, not by counting rows. A count would
     * pass if the wrong row were swapped for a right one.
     */
    #[Test]
    public function near_expire_includes_only_current_active_assignments_expiring_within_seven_days(): void
    {
        ['workspace' => $w] = $this->workspace();

        $this->expiring($w->id, 'AX-IN-3DAYS', now()->addDays(3)->toDateTimeString());
        $this->expiring($w->id, 'AX-OUT-10DAYS', now()->addDays(10)->toDateTimeString());
        $this->expiring($w->id, 'AX-OUT-INACTIVE', now()->addDays(2)->toDateTimeString(),
            ['status' => SmartQrStatus::ASSIGNMENT_INACTIVE]);
        $this->expiring($w->id, 'AX-OUT-ENDED', now()->addDays(2)->toDateTimeString(),
            ['status' => SmartQrStatus::ASSIGNMENT_ENDED, 'unassigned_at' => now()->subHour()]);

        // ⚠️ CLOSED PERIOD, STATUS STILL `active` — and this row is why the
        // `whereNull('unassigned_at')` filter is tested at all.
        //
        // The ENDED row above sets BOTH unassigned_at and status=ended, so the
        // status filter alone excludes it and the currency filter never gets
        // exercised: measured by deleting whereNull() and watching this test
        // still pass. Currency is `unassigned_at IS NULL` and is INDEPENDENT of
        // status — that is R-4's rule, stated on SmartQrCode::currentAssignment()
        // — so a row where the two disagree is what proves the filter is real.
        $this->expiring($w->id, 'AX-OUT-CLOSED-BUT-ACTIVE', now()->addDays(2)->toDateTimeString(),
            ['unassigned_at' => now()->subHour()]);

        // ⚠️ Already expired is excluded BY DESIGN — see the controller. It is
        // a state the public redirect already reflects, not a warning.
        $this->expiring($w->id, 'AX-OUT-ALREADY', now()->subDay()->toDateTimeString());

        // No expiry at all — the factory default, and the common case.
        $this->expiring($w->id, 'AX-OUT-NOEXPIRY', null);

        $serials = $this->nearExpiringSerials();

        $this->assertSame(['AX-IN-3DAYS'], $serials,
            'The panel must contain exactly the one current, active, within-7-days assignment.');
    }

    #[Test]
    public function near_expire_orders_soonest_first(): void
    {
        ['workspace' => $w] = $this->workspace();

        $this->expiring($w->id, 'AX-DAY-6', now()->addDays(6)->toDateTimeString());
        $this->expiring($w->id, 'AX-DAY-1', now()->addDay()->toDateTimeString());
        $this->expiring($w->id, 'AX-DAY-4', now()->addDays(4)->toDateTimeString());

        $this->assertSame(['AX-DAY-1', 'AX-DAY-4', 'AX-DAY-6'], $this->nearExpiringSerials(),
            'The most urgent expiry must be first.');
    }

    /**
     * ⚠️ POSITIVE CONTROL FOR THE EXCLUSIONS ABOVE. seedKnownState() creates ten
     * codes and six assignments, none with an expires_at (the factory sets
     * none) — so an implementation that ignored the expiry filter entirely
     * would fill this panel and fail here.
     */
    #[Test]
    public function near_expire_is_empty_when_nothing_carries_an_expiry(): void
    {
        $this->seedKnownState();

        $this->assertSame([], $this->nearExpiringSerials());
    }

    // ══ Locked codes ═══════════════════════════════════════════════════════

    /**
     * ⚠️ THE LOCKED+ENDED ROW IS THE WHOLE TEST. UnassignQrCodeAction writes
     * only unassigned_at and status — it never clears admin_locked — so this
     * row is reachable in production, and counting the flag alone would report
     * a lock that restrains nobody.
     */
    #[Test]
    public function locked_codes_counts_only_locks_on_current_assignments(): void
    {
        ['workspace' => $w] = $this->workspace();

        $this->makeAssignment($w->id, 'AX-LOCK-CURRENT', ['admin_locked' => true]);
        $this->makeAssignment($w->id, 'AX-LOCK-ENDED', [
            'admin_locked' => true,
            'unassigned_at' => now()->subDay(),
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
        ]);
        $this->makeAssignment($w->id, 'AX-UNLOCKED', ['admin_locked' => false]);

        $this->assertSame(1, $this->dashboardProps()['lockedCodes'],
            'Only the locked CURRENT assignment counts — not the locked-then-ended one.');
    }

    // ══ Recently ended ═════════════════════════════════════════════════════

    #[Test]
    public function recently_ended_orders_newest_first_and_computes_duration(): void
    {
        ['workspace' => $w] = $this->workspace();

        $this->makeAssignment($w->id, 'AX-END-OLDEST', [
            'assigned_at' => now()->subDays(20), 'unassigned_at' => now()->subDays(10),
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
        ]);
        $this->makeAssignment($w->id, 'AX-END-NEWEST', [
            'assigned_at' => now()->subDays(5), 'unassigned_at' => now()->subDay(),
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
        ]);

        // ⚠️ THE SAME-DAY EDGE CASE — opened and closed inside one day. It
        // really happens (measured in dev), and a bare 0 reads as a bug, so the
        // page renders it as "under a day". The controller must still report 0
        // rather than null, which would render nothing at all.
        $this->makeAssignment($w->id, 'AX-END-SAMEDAY', [
            'assigned_at' => now()->subDays(3), 'unassigned_at' => now()->subDays(3)->addHours(2),
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
        ]);

        $rows = $this->dashboardProps()['recentlyEnded'];

        $this->assertSame(
            ['AX-END-NEWEST', 'AX-END-SAMEDAY', 'AX-END-OLDEST'],
            array_column($rows, 'serial_number'),
            'Most recently ended must come first.'
        );

        // ⚠️ array_column(..., null, key) rather than collect()->keyBy():
        // the props array is untyped, and collect() on it cannot resolve its
        // template types under level 6.
        $byserial = array_column($rows, null, 'serial_number');
        $this->assertSame(4, $byserial['AX-END-NEWEST']['held_days']);
        $this->assertSame(10, $byserial['AX-END-OLDEST']['held_days']);
        $this->assertSame(0, $byserial['AX-END-SAMEDAY']['held_days'],
            'A sub-day period must be 0, not null — the page turns 0 into "under a day".');
    }

    /** ⚠️ Current assignments must never appear in a list of ENDED periods. */
    #[Test]
    public function recently_ended_excludes_current_assignments(): void
    {
        ['workspace' => $w] = $this->workspace();

        $this->makeAssignment($w->id, 'AX-STILL-HELD');

        $this->assertSame([], $this->dashboardProps()['recentlyEnded']);
    }

    // ══ Top workspaces ═════════════════════════════════════════════════════

    /**
     * ⚠️ CURRENT HOLDINGS, NOT LIFETIME. The workspace below holds 2 codes now
     * and has released 3 — a lifetime count would report 5 and rank it above a
     * tenant actually holding more today, under a label that says "Currently
     * Held".
     */
    #[Test]
    public function top_workspaces_counts_current_holdings_not_history(): void
    {
        ['workspace' => $a] = $this->workspace();
        ['workspace' => $b] = $this->workspace();

        $this->makeAssignment($a->id, 'AX-A-CUR-1');
        $this->makeAssignment($a->id, 'AX-A-CUR-2');
        foreach (['AX-A-END-1', 'AX-A-END-2', 'AX-A-END-3'] as $serial) {
            $this->makeAssignment($a->id, $serial, [
                'unassigned_at' => now()->subDay(), 'status' => SmartQrStatus::ASSIGNMENT_ENDED,
            ]);
        }

        $this->makeAssignment($b->id, 'AX-B-CUR-1');

        $rows = $this->dashboardProps()['topWorkspaces'];

        $this->assertSame(
            [[$a->name, 2], [$b->name, 1]],
            array_map(fn ($r) => [$r['name'], $r['value']], $rows),
            'Ended periods must not inflate the count, and the order is by current holdings desc.'
        );

        foreach ($rows as $r) {
            $this->assertFalse($r['is_other'], 'Two workspaces is well under the slice cap.');
        }
    }

    /**
     * ⚠️ THE SLICE CAP, which is a rendering constraint and not a taste call:
     * DonutChart's palette holds NINE colours and cycles
     * (`COLORS[index % COLORS.length]`), so a tenth slice silently repeats the
     * first colour. Eight named workspaces plus a summed "Other" fills it
     * exactly — and the remainder must be SUMMED, not dropped, or the chart
     * would total less than the platform actually holds.
     */
    #[Test]
    public function top_workspaces_caps_at_nine_slices_and_sums_the_remainder(): void
    {
        // 11 workspaces holding 11, 10, 9 … 1 codes — distinct so the order is
        // deterministic and the tail is unambiguous.
        $expectedTop = [];

        foreach (range(11, 1) as $holdings) {
            ['workspace' => $w] = $this->workspace();

            for ($i = 0; $i < $holdings; $i++) {
                $this->makeAssignment($w->id, "AX-W{$holdings}-{$i}");
            }

            if (count($expectedTop) < 8) {
                $expectedTop[] = [$w->name, $holdings];
            }
        }

        $rows = $this->dashboardProps()['topWorkspaces'];

        $this->assertCount(9, $rows, 'Eight named workspaces plus one Other bucket.');

        $this->assertSame(
            $expectedTop,
            array_map(fn ($r) => [$r['name'], $r['value']], array_slice($rows, 0, 8)),
            'The eight largest holders keep their own slices, in descending order.'
        );

        $other = $rows[8];
        $this->assertTrue($other['is_other']);
        $this->assertNull($other['name'], 'The bucket is FLAGGED, not named — the page supplies the word.');
        $this->assertSame(3 + 2 + 1, $other['value'], 'The tail must be summed, not dropped.');

        // Nothing may be lost between the query and the chart.
        $this->assertSame(
            array_sum(range(1, 11)),
            array_sum(array_column($rows, 'value')),
            'Every currently-held code must still be accounted for after bucketing.'
        );
    }

    // ══ Scan volume ════════════════════════════════════════════════════════

    /**
     * ⚠️ TODAY IS EXCLUDED AND GAPS ARE ZERO-FILLED — the two properties a
     * line chart silently misrepresents if either is wrong: a trailing zero
     * reads as collapsed traffic, and a missing point makes the line draw
     * straight through a day that had none.
     */
    #[Test]
    public function scan_volume_spans_thirty_days_ending_yesterday_and_zero_fills(): void
    {
        ['workspace' => $w] = $this->workspace();
        $a = $this->makeAssignment($w->id, 'AX-SCANNED');

        $yesterday = now()->startOfDay()->subDay();

        SmartQrDailyStat::create([
            'smart_qr_assignment_id' => $a->id, 'stat_date' => $yesterday->toDateString(),
            'scans' => 7, 'unique_scans' => 5,
        ]);
        // Outside the 30-day window — must not appear.
        SmartQrDailyStat::create([
            'smart_qr_assignment_id' => $a->id, 'stat_date' => now()->startOfDay()->subDays(45)->toDateString(),
            'scans' => 99, 'unique_scans' => 99,
        ]);
        // Today — the aggregator never writes one, but if it ever did the
        // window must still exclude it.
        SmartQrDailyStat::create([
            'smart_qr_assignment_id' => $a->id, 'stat_date' => now()->startOfDay()->toDateString(),
            'scans' => 1234, 'unique_scans' => 1234,
        ]);

        $series = $this->dashboardProps()['scanVolume'];
        $dates = array_column($series, 'date');

        $this->assertCount(30, $series);
        $this->assertSame($dates, array_unique($dates), 'No duplicate days.');
        $this->assertSame($yesterday->toDateString(), end($dates), 'The series must END yesterday.');
        $this->assertNotContains(now()->toDateString(), $dates, 'Today must be absent.');
        $this->assertNotContains(99, array_column($series, 'scans'), 'A 45-day-old row is outside the window.');
        $this->assertNotContains(1234, array_column($series, 'scans'), "Today's row must be excluded.");

        $last = $series[29];
        $this->assertSame(7, $last['scans']);
        $this->assertSame(5, $last['unique_scans']);

        // Every other day had no row at all and must be a real 0, not missing.
        $this->assertSame(0, $series[0]['scans']);
        $zeroDays = count(array_filter($series, fn (array $d) => $d['scans'] === 0));
        $this->assertSame(29, $zeroDays, '29 of 30 days are zero-filled.');
    }
}
