<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-040 — demo mode must not leak the real WhatsApp number.
 *
 * ─── ⚠️ WHY THIS FILE EXISTS ────────────────────────────────────────────────
 *
 * MasksDemoData masks in toArray() — the serialization choke point — and
 * deliberately NOT on attribute access, so internal logic keeps the real value.
 * Every natural way of fetching the number therefore returns it UNMASKED:
 *
 *     WhatsappPhoneNumber::query()->…->value('display_phone')   the original bug
 *     $channel->phoneNumber->display_phone                      the obvious "fix"
 *
 * Only routing the value through serialization masks it. That is a subtle enough
 * distinction that the fix looks equivalent to the bug at a glance, which is
 * exactly why it needs a guard.
 *
 * ⚠️ THE EXISTING destination_phone TESTS CANNOT CATCH THIS. They run with demo
 * mode off (the default), where the buggy and fixed code return an identical
 * value. Every assertion here therefore sets demo_mode explicitly, and each
 * masked assertion is paired with a demo-off control proving the same route
 * still returns the real number — without that pair, a route that returned null
 * for an unrelated reason would pass as "masked".
 */
class SmartQrDemoMaskingTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_PHONE = '+91 88828 33998';

    /** The digits masking must remove; asserting on these, not on a mask shape. */
    private const SECRET_DIGITS = '88828';

    private function admin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'R_'.uniqid(), 'name' => 'R', 'description' => '']);
        $perm = Permission::firstOrCreate(['key' => 'view_qr_inventory'],
            ['name' => 'View QR inventory', 'category' => 'test']);
        $role->permissions()->syncWithoutDetaching([$perm->id]);
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /** A code assigned in a workspace the admin has nothing to do with. */
    private function assignedElsewhere(): SmartQrCode
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['smart_qr_max_assigned' => 50]]));

        $phoneNumberId = 'PN-'.uniqid();

        $channel = ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Front Desk Line',
            'phone_number_id' => $phoneNumberId,
            'status' => 'active',
        ]);

        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $workspace->id]);

        WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => $phoneNumberId,
            'display_phone' => self::REAL_PHONE,
            'verified_name' => 'Front Desk Line',
        ]);

        $code = SmartQrCode::factory()->create();

        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channel->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
            'name' => 'Front counter',
        ]);

        return $code;
    }

    // ══ QR Inventory detail — the panel the bug was found on ═════════════════

    #[Test]
    public function the_inventory_panel_masks_the_phone_in_demo_mode(): void
    {
        config(['app.demo_mode' => true]);
        $code = $this->assignedElsewhere();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.show', $code->serial_number))
            ->assertInertia(function ($page) {
                $value = $page->toArray()['props']['currentAssignment']['destination_phone'] ?? null;

                $this->assertNotNull($value, 'The field vanished rather than being masked.');
                $this->assertNotSame(self::REAL_PHONE, $value, 'The REAL number reached the browser in demo mode.');
                $this->assertStringNotContainsString(self::SECRET_DIGITS, $value);
            });
    }

    /** ⚠️ Positive control: the same route, same fixture, demo off. */
    #[Test]
    public function the_inventory_panel_shows_the_real_phone_when_demo_is_off(): void
    {
        config(['app.demo_mode' => false]);
        $code = $this->assignedElsewhere();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.show', $code->serial_number))
            ->assertInertia(fn ($page) => $page
                ->where('currentAssignment.destination_phone', self::REAL_PHONE));
    }

    // ══ QR Assignments list — proves serialization masks it for free ═════════

    /**
     * ⚠️ THE CLAIM UNDER TEST is that eager-loading the relation and letting
     * Inertia serialize it gets masking without any mapping code. If someone
     * later "simplifies" this into a flattened string field, this fails.
     */
    #[Test]
    public function the_assignments_list_masks_the_phone_in_demo_mode(): void
    {
        config(['app.demo_mode' => true]);
        $this->assignedElsewhere();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.assignments.index'))
            ->assertInertia(function ($page) {
                $rows = $page->toArray()['props']['assignments']['data'] ?? [];
                $this->assertNotEmpty($rows, 'No rows — the assertion below would pass vacuously.');

                $value = $rows[0]['channel_account']['phone_number']['display_phone'] ?? null;
                $this->assertNotNull($value, 'The relation was not serialized at all.');
                $this->assertNotSame(self::REAL_PHONE, $value, 'The REAL number reached the browser in demo mode.');
                $this->assertStringNotContainsString(self::SECRET_DIGITS, $value);
            });
    }

    /** ⚠️ Positive control for the list. */
    #[Test]
    public function the_assignments_list_shows_the_real_phone_when_demo_is_off(): void
    {
        config(['app.demo_mode' => false]);
        $this->assignedElsewhere();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.assignments.index'))
            ->assertInertia(function ($page) {
                $rows = $page->toArray()['props']['assignments']['data'] ?? [];
                $this->assertNotEmpty($rows);
                $this->assertSame(self::REAL_PHONE, $rows[0]['channel_account']['phone_number']['display_phone'] ?? null);
            });
    }
}
