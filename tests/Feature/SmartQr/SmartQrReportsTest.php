<?php

namespace Tests\Feature\SmartQr;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrDailyStat;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 7b — §12's reports.
 *
 * ⚠️ Every figure here comes from the AGGREGATES, never raw scans. That is
 * R-26's reasoning generalised beyond the export: raw rows stop at the 90-day
 * retention boundary, so a date-ranged chart built on them would show a cliff
 * that looks like the product stopped working.
 */
class SmartQrReportsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user: User, assignment: SmartQrAssignment, workspace: Workspace} */
    private function tenant(bool $entitled = true): array
    {
        ['workspace' => $workspace, 'client' => $client, 'user' => $user] = $this->createWorkspaceContext();

        $user->forceFill(['client_role' => User::CLIENT_ROLE_ADMINISTRATOR])->save();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'limits' => $entitled ? ['smart_qr_max_assigned' => 50] : ['users' => 5],
        ]));

        $channel = ChannelAccount::withoutWorkspaceScope('reason: fixture')->create([
            'workspace_id' => $workspace->id, 'channel' => 'whatsapp',
            'display_name' => 'Line', 'phone_number_id' => 'PN-'.uniqid(), 'status' => 'active',
        ]);

        $assignment = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => SmartQrCode::factory()->create(['serial_number' => 'AX-'.uniqid()])->id,
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channel->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
            'name' => 'Front counter',
            'qr_type' => 'Counter',
        ]);

        return compact('user', 'assignment', 'workspace');
    }

    private function stat(SmartQrAssignment $a, string $date, array $attrs = []): SmartQrDailyStat
    {
        return SmartQrDailyStat::create(array_merge([
            'smart_qr_assignment_id' => $a->id,
            'stat_date' => $date,
            'scans' => 10, 'unique_scans' => 8, 'bot_scans' => 1,
            'attributed_messages' => 4, 'attributed_unique_contacts' => 2,
            'attributed_new_contacts' => 1, 'attributed_conversations_started' => 1,
        ], $attrs));
    }

    // ══ ⚠️ The entitlement gate — same as slice 6's pages ══════════════════

    /**
     * ⚠️ Without this the Reports nav entry would lead a customer without the
     * feature straight into a 403. Hiding the nav is presentation; this is the
     * protection.
     */
    #[Test]
    public function a_customer_without_the_entitlement_cannot_reach_the_report_or_the_export(): void
    {
        $t = $this->tenant(entitled: false);

        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index'))->assertForbidden();
        $this->actingAs($t['user'])->get(route('client.reports.smartqr.export'))->assertForbidden();
    }

    /** POSITIVE CONTROL: with it, both load. */
    #[Test]
    public function a_customer_with_the_entitlement_reaches_the_report_and_the_export(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index'))->assertOk();
        $this->actingAs($t['user'])->get(route('client.reports.smartqr.export'))->assertOk();
    }

    // ══ R-24 — the rate ════════════════════════════════════════════════════

    /**
     * ⚠️ Unique contacts ÷ unique valid scans, not messages ÷ scans.
     *
     * 4 messages from 2 people over 8 unique scans is 25% by §12 and 50% by the
     * formula slice 6 originally shipped. Distinct numbers, so the wrong one
     * cannot pass by coincidence.
     */
    #[Test]
    public function the_report_rate_follows_the_spec_definition(): void
    {
        $t = $this->tenant();
        $this->stat($t['assignment'], now()->subDays(2)->toDateString());

        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('report.totals.unique_scans', 8)
                ->where('report.totals.attributed_messages', 4)
                // ⚠️ 25, not 25.0 — the value crosses JSON, where a float with
                // no fractional part serialises as an integer. Asserting 25.0
                // here tests the encoder, not the formula.
                ->where('report.rate', 25));
    }

    /** null on zero, never 0 — "0%" claims nobody responded. */
    #[Test]
    public function the_rate_is_null_when_there_are_no_scans(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('report.rate', null));
    }

    // ══ R-25 — three funnel stages ═════════════════════════════════════════

    /**
     * ⚠️ §12 asks for four. Scans and redirects are the same number by
     * construction — `recordScan()` runs only in the REDIRECT branch — so a
     * fourth stage would draw two identical bars.
     */
    #[Test]
    public function the_funnel_has_three_stages_and_no_redirect_stage(): void
    {
        $t = $this->tenant();
        $this->stat($t['assignment'], now()->subDays(2)->toDateString());

        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('report.funnel', 3)
                ->where('report.funnel.0.name', 'valid_scans')
                ->where('report.funnel.1.name', 'attributed_messages')
                ->where('report.funnel.2.name', 'attributed_new_contacts'));
    }

    // ══ Tenant boundary ════════════════════════════════════════════════════

    #[Test]
    public function the_report_never_includes_another_tenants_figures(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->stat($a['assignment'], now()->subDays(2)->toDateString(), ['scans' => 10]);
        $this->stat($b['assignment'], now()->subDays(2)->toDateString(), ['scans' => 999]);

        $this->actingAs($a['user'])->get(route('client.reports.smartqr.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('report.totals.scans', 10));
    }

    // ══ Filters ════════════════════════════════════════════════════════════

    #[Test]
    public function the_date_range_filter_excludes_days_outside_it(): void
    {
        $t = $this->tenant();
        $this->stat($t['assignment'], now()->subDays(2)->toDateString(), ['scans' => 10]);
        $this->stat($t['assignment'], now()->subDays(40)->toDateString(), ['scans' => 500]);

        // Default range is the last 30 days ending yesterday — the older day is out.
        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('report.totals.scans', 10));

        // Widened explicitly, it comes back.
        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index', [
            'from' => now()->subDays(60)->toDateString(),
            'to' => now()->subDay()->toDateString(),
        ]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('report.totals.scans', 510));
    }

    #[Test]
    public function the_qr_type_filter_narrows_the_report(): void
    {
        $t = $this->tenant();
        $other = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => SmartQrCode::factory()->create()->id,
            'workspace_id' => $t['workspace']->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
            'qr_type' => 'Table',
        ]);

        $this->stat($t['assignment'], now()->subDays(2)->toDateString(), ['scans' => 10]);
        $this->stat($other, now()->subDays(2)->toDateString(), ['scans' => 7]);

        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index', ['qr_type' => 'Table']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('report.totals.scans', 7));
    }

    // ══ The trend is zero-filled ═══════════════════════════════════════════

    /**
     * ⚠️ A line chart that skips empty days draws a straight line between two
     * points weeks apart and implies activity that did not happen.
     */
    #[Test]
    public function the_trend_covers_every_day_in_the_range_including_empty_ones(): void
    {
        $t = $this->tenant();
        $this->stat($t['assignment'], now()->subDays(2)->toDateString());

        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('report.trend', 30));
    }

    // ══ ⚠️ R-19 + R-26 — the export ════════════════════════════════════════

    /**
     * ⚠️ The CSV HEADER carries R-19.
     *
     * This file outlives the page that produced it and will be opened in a
     * spreadsheet with no tooltip to explain the column, so the word
     * "Attributed" has to be in the column NAME.
     */
    #[Test]
    public function the_export_header_says_attributed_not_customers_messaged(): void
    {
        $t = $this->tenant();
        $this->stat($t['assignment'], now()->subDays(2)->toDateString());

        $csv = $this->actingAs($t['user'])
            ->get(route('client.reports.smartqr.export'))
            ->streamedContent();

        $this->assertStringContainsString('Attributed Messages', $csv,
            'The export header does not carry R-19. A column called "Customers Messaged" in a '
            .'spreadsheet is quoted as a total by someone who never saw the caveat.');
        $this->assertStringNotContainsString('Customers Messaged', $csv);
        $this->assertStringContainsString('AX-', $csv, 'Positive control: the rows are present.');
    }

    /** The export is a CSV download, not an HTML page. */
    #[Test]
    public function the_export_is_a_csv_attachment(): void
    {
        $t = $this->tenant();

        $response = $this->actingAs($t['user'])->get(route('client.reports.smartqr.export'));

        // Laravel appends the charset, so match the prefix rather than pinning
        // a header the framework owns.
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    // ══ The user-wise report ═══════════════════════════════════════════════

    /** Empty when no code has an assigned user — the usual case. */
    #[Test]
    public function the_user_report_is_empty_when_no_code_has_an_assigned_user(): void
    {
        $t = $this->tenant();
        $this->stat($t['assignment'], now()->subDays(2)->toDateString());

        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('report.perUser', 0));
    }

    /** POSITIVE CONTROL: it populates once a user IS assigned. */
    #[Test]
    public function the_user_report_populates_when_a_user_is_assigned(): void
    {
        $t = $this->tenant();
        $t['assignment']->forceFill(['assigned_user_id' => $t['user']->id])->save();
        $this->stat($t['assignment'], now()->subDays(2)->toDateString(), ['scans' => 12]);

        $this->actingAs($t['user'])->get(route('client.reports.smartqr.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('report.perUser', 1)
                ->where('report.perUser.0.scans', 12)
                ->where('report.perUser.0.name', $t['user']->name));
    }
}
