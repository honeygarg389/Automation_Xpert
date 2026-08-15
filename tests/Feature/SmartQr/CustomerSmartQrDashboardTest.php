<?php

namespace Tests\Feature\SmartQr;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Entitlements\Services\PlanPackageSynthesizer;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrConversionEvent;
use App\Modules\SmartQr\Models\SmartQrScanEvent;
use App\Modules\SmartQr\Services\SmartQrAccess;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 6 — §11's customer dashboard.
 *
 * ─── ⚠️ THE FOUR THIS FILE EXISTS FOR ───────────────────────────────────────
 *
 *   previous tenant     a reassigned code and its scans vanish from the old
 *                       tenant's PAGES, not just from the service
 *   cross-workspace     a customer cannot point their QR at another tenant's
 *                       WhatsApp channel
 *   R-14 membership     a teammate whose PRIMARY workspace differs is accepted
 *   entitlement gate    a customer without smart_qr_enabled sees 403
 *
 * ⚠️ And SmartQrAccess itself is re-proven against two tenants. It was built in
 * slice 1, has had a fail-closed bug caught by a positive control, and until
 * this slice its ONLY exercise was that canary — five slices of a grep guard
 * protecting a service with no production caller.
 */
class CustomerSmartQrDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->withoutVite();
    }

    /**
     * A tenant with the Smart QR entitlement and one assigned code.
     *
     * @return array{workspace: Workspace, user: User, code: SmartQrCode, assignment: SmartQrAssignment, channel: ChannelAccount}
     */
    private function tenant(bool $entitled = true, string $role = User::CLIENT_ROLE_ADMINISTRATOR): array
    {
        ['workspace' => $workspace, 'client' => $client, 'user' => $user] = $this->createWorkspaceContext();

        $user->forceFill(['client_role' => $role])->save();

        // ⚠️ The entitlement is DERIVED from the presence of the assignment
        // limit (R-22). A plan WITHOUT the key is a plan without the feature.
        $limits = $entitled ? ['smart_qr_max_assigned' => 50] : ['users' => 5];
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => $limits]));

        $channel = ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Main Line',
            'phone_number_id' => 'PN-'.uniqid(),
            'status' => 'active',
        ]);

        $code = SmartQrCode::factory()->create();

        $assignment = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channel->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
            'name' => 'Front counter',
        ]);

        return compact('workspace', 'user', 'code', 'assignment', 'channel');
    }

    private function scan(SmartQrAssignment $assignment, bool $unique = true, bool $bot = false): void
    {
        SmartQrScanEvent::create([
            'smart_qr_assignment_id' => $assignment->id,
            'scanned_at' => now(),
            'ip_hash' => str_repeat('a', 64),
            'ua_hash' => str_repeat('b', 64),
            'is_unique' => $unique,
            'is_bot' => $bot,
        ]);
    }

    // ══ ⚠️ SmartQrAccess re-proven — two tenants, every method ═════════════

    /**
     * ⚠️ Its only exercise in five slices was the slice-1 canary, and
     * `boundedTo()` has had a fail-CLOSED bug before — every method returned an
     * empty set for a workspace that genuinely owned codes, and only the
     * positive control caught it.
     */
    #[Test]
    public function every_smart_qr_access_method_is_bounded_to_its_own_tenant(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->scan($a['assignment']);
        $this->scan($a['assignment']);
        $this->scan($b['assignment']);

        $access = app(SmartQrAccess::class);

        // codesFor — positive AND negative, so "nobody sees anything" fails.
        $this->assertSame(1, $access->codesFor($a['workspace']->id)->count(),
            'A workspace cannot see its OWN code. That is the fail-closed shape the slice-1 '
            .'canary caught, and a cross-tenant assertion alone would not distinguish it.');
        $this->assertSame(1, $access->codesFor($b['workspace']->id)->count());

        // findForWorkspace
        $this->assertNotNull($access->findForWorkspace($a['workspace']->id, $a['code']->serial_number));
        $this->assertNull($access->findForWorkspace($b['workspace']->id, $a['code']->serial_number),
            "Workspace B resolved workspace A's code by serial.");

        // assignmentsFor
        $this->assertSame(1, $access->assignmentsFor($a['workspace']->id)->count());
        $this->assertSame(1, $access->assignmentsFor($b['workspace']->id)->count());

        // scanEventsFor
        $this->assertSame(2, $access->scanEventsFor($a['workspace']->id)->count());
        $this->assertSame(1, $access->scanEventsFor($b['workspace']->id)->count(),
            "Workspace B can see workspace A's scans.");
    }

    // ══ ⚠️ STASH-CHECK 1 — the previous tenant, at the HTTP layer ══════════

    /**
     * ⚠️ R-4's whole reason for existing, proven where a customer would see it.
     *
     * Slice 1 proved this at the service level. This proves it on the PAGES: a
     * reassigned code and its scans must vanish from the previous tenant's
     * dashboard, and the new tenant must not inherit the old scans.
     */
    #[Test]
    public function a_reassigned_code_and_its_scans_leave_the_previous_tenants_pages(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        // Two scans while A holds it.
        $this->scan($a['assignment']);
        $this->scan($a['assignment']);

        $this->actingAs($a['user'])->get(route('client.smartqr.overview'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('kpis.total_codes', 1)->where('kpis.total_scans', 2));

        // Reassign to B: close A's period, open B's.
        $a['assignment']->forceFill(['unassigned_at' => now(), 'status' => SmartQrStatus::ASSIGNMENT_ENDED])->saveQuietly();

        $bAssignment = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $a['code']->id,
            'workspace_id' => $b['workspace']->id,
            'channel_account_id' => $b['channel']->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ]);
        $this->scan($bAssignment);

        // ⚠️ A: the CODE is gone from their list — but their two scans REMAIN.
        //
        // That asymmetry is R-4 exactly, and the first version of this test got
        // it backwards. R-4: "reassignment must hide the old scans from the NEW
        // tenant, not delete them", and the slice-1 canary asserts the previous
        // tenant still sees its own two. Those scans happened while A held the
        // code and are A's data; zeroing them would be deleting their history
        // from view, not protecting anybody.
        $this->actingAs($a['user'])->get(route('client.smartqr.overview'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('kpis.total_codes', 0)
                ->where('kpis.total_scans', 2));

        // ⚠️ B sees ONE scan, not three. Inheriting the previous tenant's scan
        // history is the leak R-4 and the assignment-period model exist to make
        // unreachable — and it would show one company another company's traffic.
        $this->actingAs($b['user'])->get(route('client.smartqr.overview'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('kpis.total_codes', 2)      // its own, plus the reassigned one
                ->where('kpis.total_scans', 1));

        // The old rows still EXIST — hidden from the new tenant, not deleted.
        $this->assertSame(3, (int) DB::table('smart_qr_scan_events')->count(),
            "The previous tenant's scans were deleted rather than hidden. R-4 requires the "
            .'period to survive.');
    }

    /** …and the Activity feed obeys the same boundary. */
    #[Test]
    public function the_activity_feed_shows_only_the_current_tenants_scans(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->scan($a['assignment']);
        $this->scan($b['assignment']);
        $this->scan($b['assignment']);

        $this->actingAs($a['user'])->get(route('client.smartqr.activity'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('scans.data', 1));

        $this->actingAs($b['user'])->get(route('client.smartqr.activity'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('scans.data', 2));
    }

    // ══ ⚠️ STASH-CHECK 2 — the cross-workspace channel refusal ═════════════

    #[Test]
    public function a_customer_cannot_point_their_qr_at_another_tenants_channel(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->actingAs($a['user'])
            ->from(route('client.smartqr.codes.index'))
            ->patch(route('client.smartqr.codes.update', $a['code']->serial_number), [
                'channel_account_id' => $b['channel']->id,
            ])
            ->assertSessionHasErrors('channel_account_id');

        $this->assertSame($a['channel']->id, (int) $a['assignment']->fresh()->channel_account_id,
            "A customer pointed their QR at another tenant's WhatsApp line. Every scan would "
            .'then deliver their customers into somebody else\'s inbox.');
    }

    /** POSITIVE CONTROL: their OWN channel is accepted on the same route. */
    #[Test]
    public function a_customer_can_switch_to_their_own_channel(): void
    {
        $a = $this->tenant();

        $second = ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $a['workspace']->id,
            'channel' => 'whatsapp',
            'display_name' => 'Second Line',
            'phone_number_id' => 'PN-'.uniqid(),
            'status' => 'active',
        ]);

        $this->actingAs($a['user'])
            ->patch(route('client.smartqr.codes.update', $a['code']->serial_number), [
                'channel_account_id' => $second->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($second->id, (int) $a['assignment']->fresh()->channel_account_id,
            'The endpoint refuses every channel, so the cross-tenant test proves nothing.');
    }

    // ══ ⚠️ STASH-CHECK 3 — R-14 membership ════════════════════════════════

    /**
     * ⚠️ The only test standing between this and the `users.workspace_id`
     * re-implementation, which reads simpler and is wrong: a teammate whose
     * PRIMARY workspace is a different one would silently vanish from the
     * picker and be refused by the form.
     */
    #[Test]
    public function a_member_whose_primary_workspace_is_different_can_be_assigned(): void
    {
        ['user' => $user, 'client' => $client, 'home' => $home, 'other' => $other] =
            $this->createTwoWorkspaceUser(['client_role' => User::CLIENT_ROLE_ADMINISTRATOR]);

        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['smart_qr_max_assigned' => 50]]));

        $channel = ChannelAccount::withoutWorkspaceScope('reason: fixture')->create([
            'workspace_id' => $other->id, 'channel' => 'whatsapp',
            'display_name' => 'Other Line', 'phone_number_id' => 'PN-'.uniqid(), 'status' => 'active',
        ]);

        $code = SmartQrCode::factory()->create();
        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $other->id,
            'channel_account_id' => $channel->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ]);

        $this->assertNotSame((int) $user->workspace_id, (int) $other->id,
            'Fixture broken: the user\'s primary workspace IS the target, so this test could not '
            .'distinguish membership from primary workspace.');

        WorkspaceContext::for($other->id, function () use ($user, $code) {
            $this->actingAs($user)
                ->patch(route('client.smartqr.codes.update', $code->serial_number), [
                    'assigned_user_id' => $user->id,
                ])
                ->assertSessionHasNoErrors();
        });

        $this->assertSame($user->id, (int) SmartQrAssignment::withoutWorkspaceScope('r')->first()->assigned_user_id,
            'A legitimate member was refused because their PRIMARY workspace is a different one. '
            .'Membership is Workspace::isAccessibleBy() (R-14), not users.workspace_id.');
    }

    // ══ ⚠️ STASH-CHECK 4 — the entitlement gate ════════════════════════════

    #[Test]
    public function a_customer_without_the_entitlement_gets_403_on_every_page(): void
    {
        $t = $this->tenant(entitled: false);

        foreach (['overview', 'codes.index', 'activity'] as $name) {
            $this->actingAs($t['user'])->get(route('client.smartqr.'.$name))->assertForbidden();
        }
    }

    /** POSITIVE CONTROL: with the entitlement, the same pages load. */
    #[Test]
    public function a_customer_with_the_entitlement_reaches_every_page(): void
    {
        $t = $this->tenant(entitled: true);

        foreach (['overview', 'codes.index', 'activity'] as $name) {
            $this->actingAs($t['user'])->get(route('client.smartqr.'.$name))->assertOk();
        }
    }

    /** The flag is DERIVED from the limit's presence, not from a column. */
    #[Test]
    public function the_entitlement_is_derived_from_the_assignment_limit(): void
    {
        $withLimit = Plan::factory()->create(['limits' => ['smart_qr_max_assigned' => 0]]);
        $without = Plan::factory()->create(['limits' => ['users' => 5]]);

        $synth = app(PlanPackageSynthesizer::class);

        // ⚠️ A limit of ZERO still grants the FEATURE — bounded at zero is a
        // granted feature the customer cannot use yet, not an absent one
        // (BUG-030's pinned semantics).
        $this->assertTrue($synth->forPlan($withLimit)->flags['smart_qr_enabled'] ?? false,
            'A plan carrying the limit did not grant the feature, so the gate can never open '
            .'and Smart QR is hidden from every customer — R-13 inverted.');
        $this->assertArrayNotHasKey('smart_qr_enabled', $synth->forPlan($without)->flags,
            'A plan without the limit granted the feature anyway.');
    }

    // ══ §11's "cannot" list ════════════════════════════════════════════════

    /** ⚠️ The serial, the token and the workspace are unreachable from this form. */
    #[Test]
    public function a_customer_cannot_edit_the_serial_token_or_workspace(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $originalSerial = $a['code']->serial_number;
        $originalToken = $a['code']->public_token;

        $this->actingAs($a['user'])
            ->patch(route('client.smartqr.codes.update', $originalSerial), [
                'name' => 'Legit rename',
                'serial_number' => 'AX-999999',
                'public_token' => 'attacker-chosen',
                'workspace_id' => $b['workspace']->id,
            ])
            ->assertSessionHasNoErrors();

        $a['code']->refresh();
        $this->assertSame($originalSerial, $a['code']->serial_number, 'The printed serial changed.');
        $this->assertSame($originalToken, $a['code']->public_token, 'The public token changed.');
        $this->assertSame((int) $a['workspace']->id, (int) $a['assignment']->fresh()->workspace_id,
            'A customer transferred their QR to another tenant through an edit form. That is a '
            .'reassignment, and it must not be reachable here (§11, R-4).');
        $this->assertSame('Legit rename', $a['assignment']->fresh()->name,
            'Positive control: the request DID go through, so the assertions above are about '
            .'ignored fields rather than a rejected request.');
    }

    /** A customer cannot edit a code they do not hold. */
    #[Test]
    public function a_customer_cannot_edit_another_tenants_code(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->actingAs($a['user'])
            ->patch(route('client.smartqr.codes.update', $b['code']->serial_number), ['name' => 'Mine now'])
            ->assertNotFound();

        $this->assertNotSame('Mine now', $b['assignment']->fresh()->name);
    }

    /** Staff can read; only administrators can write. */
    #[Test]
    public function staff_can_view_but_not_edit(): void
    {
        $t = $this->tenant(role: User::CLIENT_ROLE_STAFF);

        $this->actingAs($t['user'])->get(route('client.smartqr.codes.index'))->assertOk();

        $this->actingAs($t['user'])
            ->patch(route('client.smartqr.codes.update', $t['code']->serial_number), ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertSame('Front counter', $t['assignment']->fresh()->name);
    }

    // ══ R-19 — the labels ══════════════════════════════════════════════════

    /**
     * ⚠️ The prop names carry R-19, because a prop called `customers_messaged`
     * becomes a card called "Customers Messaged".
     */
    #[Test]
    public function the_kpi_props_are_named_attributed_not_customers_messaged(): void
    {
        $t = $this->tenant();
        $this->scan($t['assignment']);

        SmartQrConversionEvent::create([
            'smart_qr_assignment_id' => $t['assignment']->id,
            'attribution_session_id' => DB::table('smart_qr_attribution_sessions')->insertGetId([
                'token' => 'TESTTOKEN', 'smart_qr_assignment_id' => $t['assignment']->id,
                'issued_at' => now(), 'expires_at' => now()->addHour(),
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'type' => SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED,
            'occurred_at' => now(),
        ]);

        $this->actingAs($t['user'])->get(route('client.smartqr.overview'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('kpis.attributed_messages')
                ->has('kpis.attributed_new_contacts')
                ->has('kpis.attributed_message_rate')
                ->missing('kpis.customers_messaged')
                ->where('kpis.attributed_messages', 1));
    }

    /** Bot scans are excluded from the customer's counters. */
    #[Test]
    public function bot_scans_are_not_counted_on_the_overview(): void
    {
        $t = $this->tenant();
        $this->scan($t['assignment'], bot: false);
        $this->scan($t['assignment'], bot: true);

        $this->actingAs($t['user'])->get(route('client.smartqr.overview'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('kpis.total_scans', 1));
    }
}
