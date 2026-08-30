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
use App\Modules\SmartQr\Services\SmartQrDeletability;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The per-QR detail page. §5.
 *
 * ─── ⚠️ WHAT THIS FILE PINS ─────────────────────────────────────────────────
 *
 *   the scoping   channelAccount() resolves for a code belonging to a DIFFERENT
 *                 workspace than the one the request happens to sit in — the
 *                 case that fails silently to null rather than erroring
 *   the preview   works for an ARBITRARY code, unlike the customer endpoint it
 *                 was copied from, which 404s outside its own workspace
 *   the export    returns real bytes in each format, checked by magic number
 *   the shape     name/qr_type/message appear only when assigned, because they
 *                 live on the assignment and nowhere else
 *
 * ⚠️ THE CROSS-WORKSPACE FIXTURE IS THE POINT OF THE SCOPING TESTS. An
 * assignment created in the same workspace the test is "in" passes whether or
 * not WorkspaceScope was removed, so it proves nothing. Every scoping assertion
 * here builds the channel account and assignment in a workspace the admin has
 * no relationship to.
 */
class SmartQrInventoryDetailTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $permissions */
    private function admin(array $permissions = ['view_qr_inventory', 'manage_qr_batches', 'assign_qr_codes']): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-detail-test-'.md5(implode(',', $permissions))],
            ['name' => 'QR Detail Test Role', 'description' => 'test']
        );

        foreach ($permissions as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'QR Management']
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /**
     * A code assigned inside a workspace the admin has nothing to do with.
     *
     * @return array{code: SmartQrCode, assignment: SmartQrAssignment, channel: ChannelAccount, workspace: Workspace}
     */
    private function assignedElsewhere(): array
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['smart_qr_max_assigned' => 50]]));

        $channel = ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Front Desk Line',
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
            'qr_type' => 'table-tent',
            'default_message' => 'Hi from table 4',
        ]);

        return compact('code', 'assignment', 'channel', 'workspace');
    }

    // ══ Route binding ══════════════════════════════════════════════════════

    /**
     * ⚠️ The route key is serial_number. Passing the id 404s at binding, before
     * any controller or permission check runs — which is how a "protected"
     * endpoint can look protected while never being exercised.
     */
    #[Test]
    public function the_detail_route_binds_on_serial_number_not_id(): void
    {
        $code = SmartQrCode::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.show', $code->serial_number))
            ->assertOk();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/qr/inventory/'.$code->id)
            ->assertNotFound();
    }

    /**
     * ⚠️ /inventory/exports is a literal route declared BEFORE /inventory/{code}.
     * Declared the other way round, it would bind as a code with the serial
     * "exports" and 404 — a route that exists returning not-found.
     */
    #[Test]
    public function the_literal_inventory_routes_are_not_swallowed_by_the_code_route(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.exports'))
            ->assertOk();
    }

    // ══ Props shape ════════════════════════════════════════════════════════

    #[Test]
    public function an_unassigned_code_renders_with_no_assignment_block(): void
    {
        $code = SmartQrCode::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.show', $code->serial_number))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SmartQr/Inventory/Show')
                ->where('code.serial_number', $code->serial_number)
                ->where('currentAssignment', null)
            );
    }

    /**
     * ⚠️ The id travels WITH the serial. Change Stage and Assign both post to the
     * existing bulk endpoints, whose validation is exists:smart_qr_codes,id — so
     * a page that knew only the serial could render but could do nothing.
     */
    #[Test]
    public function the_page_carries_the_code_id_for_the_bulk_endpoints(): void
    {
        $code = SmartQrCode::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.show', $code->serial_number))
            ->assertInertia(fn ($page) => $page->where('code.id', $code->id));
    }

    #[Test]
    public function the_public_url_is_the_scan_url_not_the_bare_token(): void
    {
        $code = SmartQrCode::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.show', $code->serial_number))
            ->assertInertia(fn ($page) => $page
                ->where('code.public_url', route('smartqr.scan', ['token' => $code->public_token]))
            );
    }

    /**
     * ⚠️ THE SCOPING TEST THAT DISCRIMINATES. Every nested load builds its own
     * query; WorkspaceScope removal does not propagate from currentAssignment to
     * workspace or channelAccount. A missing removal yields NULL, not an error,
     * so "Destination Phone: —" would read as absent data forever.
     */
    #[Test]
    public function an_assignment_in_another_workspace_resolves_all_its_nested_relations(): void
    {
        ['code' => $code, 'channel' => $channel, 'workspace' => $workspace] = $this->assignedElsewhere();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.show', $code->serial_number))
            ->assertInertia(fn ($page) => $page
                ->where('currentAssignment.name', 'Front counter')
                ->where('currentAssignment.qr_type', 'table-tent')
                ->where('currentAssignment.default_message', 'Hi from table 4')
                ->where('currentAssignment.workspace_name', $workspace->name)
                ->where('currentAssignment.destination_phone', $channel->display_name)
            );
    }

    /**
     * The relation in isolation, so a failure points at the model rather than at
     * the controller's eager-load list.
     */
    #[Test]
    public function the_channel_account_relation_is_not_workspace_scoped(): void
    {
        ['assignment' => $assignment, 'channel' => $channel] = $this->assignedElsewhere();

        $resolved = $assignment->fresh()->channelAccount;

        $this->assertNotNull($resolved,
            'channelAccount() resolved to null for a cross-workspace assignment — WorkspaceScope is still applied.');
        $this->assertSame($channel->id, $resolved->id);
    }

    /**
     * ⚠️ POSITIVE CONTROL for the scoping tests: proves the fixture really is
     * cross-workspace, so the assertions above are not passing because the scope
     * had nothing to filter.
     */
    #[Test]
    public function the_fixture_channel_account_is_hidden_by_the_scope_when_it_is_applied(): void
    {
        ['channel' => $channel] = $this->assignedElsewhere();

        $this->assertNotNull(
            ChannelAccount::withoutWorkspaceScope('reason: control')->find($channel->id),
            'The row must exist unscoped, or the control proves nothing.'
        );
    }

    // ══ Preview ════════════════════════════════════════════════════════════

    /**
     * ⚠️ THE DIFFERENCE FROM THE CUSTOMER ENDPOINT. client.smartqr.codes.preview
     * resolves through findForWorkspace() and 404s for anything outside the
     * caller's workspace — including every unassigned code. This one must not.
     */
    #[Test]
    public function the_preview_renders_for_an_unassigned_code(): void
    {
        $code = SmartQrCode::factory()->create();

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.preview', $code->serial_number));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');

        // ⚠️ getContent(), not streamedContent(). The renderer returns the whole
        // document as a string, so the controller returns a plain response —
        // matching SmartQrCodeController::download(), which does the same.
        // streamedContent() THROWS on a non-streamed response rather than
        // returning falsy, so a `?:` fallback never runs.
        $svg = $response->getContent();
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString($code->serial_number, $svg,
            'The serial band is the only thing tying a printed sticker back to a row.');
    }

    #[Test]
    public function the_preview_renders_for_a_code_assigned_to_another_workspace(): void
    {
        ['code' => $code] = $this->assignedElsewhere();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.preview', $code->serial_number))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
    }

    // ══ Single-code download ═══════════════════════════════════════════════

    /**
     * ⚠️ MAGIC BYTES, NOT "the response succeeded". A 200 with an error page in
     * the body passes any status-only assertion, and the file only fails when
     * somebody opens it — which for print artwork is after it is printed.
     */
    #[Test]
    public function each_format_downloads_a_real_file(): void
    {
        $code = SmartQrCode::factory()->create();
        $admin = $this->admin();

        $expected = [
            'svg' => ['mime' => 'image/svg+xml', 'magic' => '<svg'],
            'png' => ['mime' => 'image/png', 'magic' => "\x89PNG\r\n\x1a\n"],
            'pdf' => ['mime' => 'application/pdf', 'magic' => '%PDF'],
        ];

        foreach ($expected as $format => $spec) {
            $response = $this->actingAs($admin, 'admin')
                ->get(route('admin.qr.inventory.download', ['code' => $code->serial_number, 'format' => $format]));

            $response->assertOk();
            $body = $response->getContent();

            $this->assertStringContainsString($spec['magic'], substr($body, 0, 400),
                "[{$format}] body does not start with the format's magic bytes.");
            $this->assertGreaterThan(1000, strlen($body), "[{$format}] body is implausibly small.");
            $this->assertStringContainsString(
                $code->serial_number.'.'.$format,
                (string) $response->headers->get('Content-Disposition'),
                "[{$format}] filename must carry the serial and the real extension."
            );
        }
    }

    #[Test]
    public function an_unsupported_download_format_is_refused(): void
    {
        $code = SmartQrCode::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.download', ['code' => $code->serial_number, 'format' => 'exe']))
            ->assertStatus(422);
    }

    #[Test]
    public function the_download_defaults_to_svg_when_no_format_is_given(): void
    {
        $code = SmartQrCode::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.download', $code->serial_number))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
    }

    // ══ printed_at / status coherence ══════════════════════════════════════

    /**
     * ⚠️ THE REPORTED BUG. Change Stage → Printed set the status and nothing else,
     * so the badge said "Printed" while the panel beside it said "Not printed" —
     * because the display reads printed_at. Three such rows existed in the
     * development database, all produced by this action.
     */
    #[Test]
    public function changing_stage_to_printed_records_the_print_timestamp(): void
    {
        $code = SmartQrCode::factory()->create(['status' => 'generated', 'printed_at' => null]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.inventory.change-status'), [
                'code_ids' => [$code->id],
                'status' => 'printed',
            ])->assertSessionHasNoErrors();

        $fresh = $code->fresh();
        $this->assertSame('printed', $fresh->status);
        $this->assertNotNull($fresh->printed_at,
            'status=printed with no printed_at is the exact contradiction this fix removes.');
    }

    /**
     * ⚠️ NEVER OVERWRITTEN. Re-selecting Printed on an already-printed code must
     * not rewrite when the print actually happened.
     */
    #[Test]
    public function changing_stage_to_printed_again_does_not_rewrite_the_timestamp(): void
    {
        $original = now()->subDays(30);
        $code = SmartQrCode::factory()->create(['status' => 'printed', 'printed_at' => $original]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.inventory.change-status'), [
                'code_ids' => [$code->id],
                'status' => 'printed',
            ]);

        $this->assertSame(
            $original->toDateTimeString(),
            $code->fresh()->printed_at->toDateTimeString(),
            'An existing print timestamp is historical fact and must not move.'
        );
    }

    /**
     * ⚠️ HISTORICAL RETENTION, AND THE REASON THE FIX IS ASYMMETRIC. A code
     * printed and later damaged keeps its timestamp: SmartQrDeletability
     * ::everPrinted() reads it to REFUSE deletion, so clearing it would report a
     * genuinely printed sticker as never-printed and let destroy() take it —
     * a cosmetic bug turned destructive.
     */
    #[Test]
    public function moving_away_from_printed_never_clears_the_timestamp(): void
    {
        $code = SmartQrCode::factory()->create(['status' => 'printed', 'printed_at' => now()->subDay()]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.inventory.change-status'), [
                'code_ids' => [$code->id],
                'status' => 'damaged',
            ]);

        $fresh = $code->fresh();
        $this->assertSame('damaged', $fresh->status);
        $this->assertNotNull($fresh->printed_at, 'printed_at is history, not current state.');
        $this->assertTrue(app(SmartQrDeletability::class)->everPrinted($fresh),
            'Delete-protection must survive a status change away from printed.');
    }

    /**
     * ⚠️ Only the print event is recorded. Selecting any OTHER status must not
     * stamp printed_at onto a code that was never printed.
     */
    #[Test]
    public function changing_stage_to_a_non_printed_status_does_not_stamp_printed_at(): void
    {
        $admin = $this->admin();

        foreach (['generated', 'damaged', 'lost', 'retired'] as $status) {
            $code = SmartQrCode::factory()->create(['status' => 'generated', 'printed_at' => null]);

            $this->actingAs($admin, 'admin')
                ->post(route('admin.qr.inventory.change-status'), [
                    'code_ids' => [$code->id],
                    'status' => $status,
                ]);

            $this->assertNull($code->fresh()->printed_at, "[{$status}] must not stamp printed_at.");
        }
    }

    /** Bulk stays bulk: every selected code gets the timestamp, not just the first. */
    #[Test]
    public function a_bulk_stage_change_stamps_every_selected_code(): void
    {
        $codes = SmartQrCode::factory()->count(3)->create(['status' => 'generated', 'printed_at' => null]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.inventory.change-status'), [
                'code_ids' => $codes->pluck('id')->all(),
                'status' => 'printed',
            ]);

        foreach ($codes as $c) {
            $this->assertNotNull($c->fresh()->printed_at, "{$c->serial_number} was missed.");
        }
    }

    // ══ Active status ══════════════════════════════════════════════════════

    #[Test]
    public function the_assignment_status_is_exposed_for_the_active_status_row(): void
    {
        ['code' => $code] = $this->assignedElsewhere();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.show', $code->serial_number))
            ->assertInertia(fn ($page) => $page
                ->where('currentAssignment.status', SmartQrStatus::ASSIGNMENT_ACTIVE));
    }

    // ══ Permissions ════════════════════════════════════════════════════════

    /**
     * ⚠️ ASSERTS THE EFFECT, NOT A STATUS CODE. RequirePermission REDIRECTS an
     * HTML request rather than returning 403, so assertForbidden() would fail on
     * a correctly-protected route.
     */
    #[Test]
    public function all_three_new_routes_require_view_qr_inventory(): void
    {
        $code = SmartQrCode::factory()->create();
        $without = $this->admin(['manage_qr_batches']);

        foreach ([
            route('admin.qr.inventory.show', $code->serial_number),
            route('admin.qr.inventory.preview', $code->serial_number),
            route('admin.qr.inventory.download', $code->serial_number),
        ] as $url) {
            $this->actingAs($without, 'admin')->get($url)
                ->assertRedirect(route('admin.dashboard'));
        }
    }

    /** POSITIVE CONTROL: the same routes succeed with the permission. */
    #[Test]
    public function all_three_new_routes_succeed_with_view_qr_inventory(): void
    {
        $code = SmartQrCode::factory()->create();
        $with = $this->admin(['view_qr_inventory']);

        foreach ([
            route('admin.qr.inventory.show', $code->serial_number),
            route('admin.qr.inventory.preview', $code->serial_number),
            route('admin.qr.inventory.download', $code->serial_number),
        ] as $url) {
            $this->actingAs($with, 'admin')->get($url)->assertOk();
        }
    }

    #[Test]
    public function a_guest_cannot_reach_the_detail_page(): void
    {
        $code = SmartQrCode::factory()->create();

        $this->get(route('admin.qr.inventory.show', $code->serial_number))
            ->assertRedirect();
    }
}
