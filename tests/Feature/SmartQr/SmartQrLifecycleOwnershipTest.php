<?php

namespace Tests\Feature\SmartQr;

use App\Models\Client;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrScanEvent;
use App\Modules\SmartQr\Services\SmartQrAccess;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE CANARY. Slice 1 exists to pass this file.
 *
 * A QR code is PLATFORM-OWNED AT BIRTH and TENANT-OWNED ON ASSIGNMENT — a shape
 * no other model in this codebase has. Three properties make it safe, and each
 * is asserted here rather than argued:
 *
 *   1. an unassigned code is invisible to EVERY workspace, and visible to admin
 *   2. two workspaces cannot hold active assignments for one code
 *   3. a reassignment hides the previous tenant's scans
 *
 * If those hold, the ownership model is proven before anything depends on it.
 */
class SmartQrLifecycleOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function batch(): SmartQrBatch
    {
        return SmartQrBatch::create([
            'batch_number' => 'AX-BK-'.Str::upper(Str::random(6)),
            'batch_name' => 'Test Kit',
            'prefix' => 'AX',
            'quantity' => 10,
        ]);
    }

    private function code(?SmartQrBatch $batch = null): SmartQrCode
    {
        return SmartQrCode::create([
            'serial_number' => 'AX-'.Str::upper(Str::random(8)),
            'public_token' => Str::random(32),
            'batch_id' => ($batch ?? $this->batch())->id,
        ]);
    }

    private function workspace(): Workspace
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $ws = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $ws->id]);

        return $ws;
    }

    private function assign(SmartQrCode $code, Workspace $ws): SmartQrAssignment
    {
        return SmartQrAssignment::withoutWorkspaceScope('reason: test fixture setup')->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $ws->id,
            'name' => 'Counter QR',
        ]) ?? SmartQrAssignment::first();
    }

    private function access(): SmartQrAccess
    {
        return app(SmartQrAccess::class);
    }

    // ══ ⚠️ PROPERTY 1 — unassigned is invisible to tenants, visible to admin ══

    /**
     * The case that rules out `BelongsToWorkspace` entirely.
     *
     * Under the trait an unassigned code (no workspace) would be invisible to
     * every tenant AND to the Super Admin inventory screen that exists to manage
     * exactly those rows — because the scope fails closed and NULL satisfies no
     * equality.
     */
    #[Test]
    public function an_unassigned_code_is_invisible_to_every_workspace_and_visible_to_admin(): void
    {
        $code = $this->code();
        $a = $this->workspace();
        $b = $this->workspace();

        $this->assertFalse($code->isAssigned(), 'Precondition: the code is unassigned.');

        foreach ([$a, $b] as $ws) {
            $this->assertSame(0, $this->access()->codesFor($ws->id)->count(),
                'An unassigned code was visible to a tenant. It belongs to the platform until '
                .'somebody assigns it.');
            $this->assertNull($this->access()->findForWorkspace($ws->id, $code->serial_number));
        }

        // ⚠️ THE HALF THE TRAIT WOULD HAVE BROKEN. Admin inventory sees it.
        $this->assertSame(1, SmartQrCode::query()->count(),
            'The unassigned code is invisible to the admin inventory too. That is what a '
            .'workspace scope would have done, and it is why this model has none.');
    }

    /** POSITIVE CONTROL: once assigned, its own workspace sees it — and only it. */
    #[Test]
    public function an_assigned_code_is_visible_only_to_its_workspace(): void
    {
        $code = $this->code();
        $a = $this->workspace();
        $b = $this->workspace();

        $this->assign($code, $a);

        $this->assertSame(1, $this->access()->codesFor($a->id)->count(),
            'The assigned workspace cannot see its own code — the boundary is refusing '
            .'everyone, which a cross-tenant test alone could not distinguish.');
        $this->assertSame(0, $this->access()->codesFor($b->id)->count(),
            'Another workspace can see a code assigned elsewhere.');
    }

    // ══ ⚠️ PROPERTY 2 — one active assignment ══════════════════════════════

    /**
     * A code assigned to two workspaces at once would redirect one tenant's
     * customers to another tenant's WhatsApp number.
     */
    #[Test]
    public function a_code_cannot_hold_two_current_assignments(): void
    {
        $code = $this->code();
        $a = $this->workspace();
        $b = $this->workspace();

        $this->assign($code, $a);

        // A second assignment WITHOUT closing the first must be refused by the
        // database, not merely discouraged.
        try {
            $this->assign($code, $b);
            $this->fail('A code accepted two current assignments. Two tenants would both '
                .'believe they own it, and a scan would redirect to whichever row was read '
                .'first — the BUG-019 shape.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->getMessage()));
        }

        $current = SmartQrAssignment::withoutWorkspaceScope('reason: assert across tenants')
            ->where('smart_qr_code_id', $code->id)
            ->whereNull('unassigned_at')
            ->count();

        $this->assertSame(1, $current,
            "The code holds {$current} current assignments. Two tenants would both believe they "
            .'own it, and a scan would redirect to whichever row was read first.');
    }

    // ══ ⚠️ PROPERTY 3 — reassignment hides the prior tenant's scans ════════

    /**
     * Scans are keyed by ASSIGNMENT, not workspace, so the period is intrinsic.
     *
     * The wrong design — `workspace_id` on the scan row — would leave the old
     * scans asserting the old workspace while the code belongs to a new one:
     * two sources of truth that disagree from the moment of reassignment.
     */
    #[Test]
    public function a_reassignment_hides_the_previous_tenants_scans(): void
    {
        $code = $this->code();
        $a = $this->workspace();
        $b = $this->workspace();

        $first = $this->assign($code, $a);
        SmartQrScanEvent::create(['smart_qr_assignment_id' => $first->id, 'scanned_at' => now()->subDay()]);
        SmartQrScanEvent::create(['smart_qr_assignment_id' => $first->id, 'scanned_at' => now()->subDay()]);

        $this->assertSame(2, $this->access()->scanEventsFor($a->id)->count(),
            'Precondition: the first tenant can see its own scans.');

        // Reassign: close the first period, open a second.
        $first->forceFill(['unassigned_at' => now(), 'status' => 'ended'])->saveQuietly();
        $second = $this->assign($code, $b);
        SmartQrScanEvent::create(['smart_qr_assignment_id' => $second->id, 'scanned_at' => now()]);

        $this->assertSame(1, $this->access()->scanEventsFor($b->id)->count(),
            'The new tenant can see the previous tenant\'s scans. That is the leak the spec '
            .'names explicitly, and it is why scans are keyed by assignment rather than by '
            .'workspace.');

        $this->assertSame(2, $this->access()->scanEventsFor($a->id)->count(),
            'The previous tenant lost its own history. Reassignment must hide the old scans '
            .'from the NEW tenant, not delete them.');

        $this->assertSame(0, $this->access()->codesFor($a->id)->count(),
            'The previous tenant still sees the code itself after reassignment.');
        $this->assertSame(1, $this->access()->codesFor($b->id)->count());
    }

    // ══ The ownership model, asserted structurally ═════════════════════════

    /**
     * ⚠️ The lifecycle-owned tables must NOT carry workspace_id.
     *
     * Adding one later would look like an improvement and would reintroduce
     * exactly the failure this design avoids, so it is pinned.
     */
    #[Test]
    public function lifecycle_owned_tables_carry_no_workspace_id(): void
    {
        foreach (['smart_qr_batches', 'smart_qr_codes', 'smart_qr_scan_events'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'workspace_id'),
                "{$table} gained a workspace_id. For codes that makes unassigned inventory "
                .'invisible to everyone; for scans it creates a second source of truth that '
                .'disagrees the moment a QR is reassigned.');
        }

        $this->assertTrue(Schema::hasColumn('smart_qr_assignments', 'workspace_id'),
            'Positive control: the assignment IS where tenancy lives, so it must have one.');
    }

    /** …and the trait sits on exactly the model whose rows never change owner. */
    #[Test]
    public function only_the_assignment_is_workspace_scoped(): void
    {
        foreach ([SmartQrBatch::class, SmartQrCode::class, SmartQrScanEvent::class] as $model) {
            $this->assertNotContains(BelongsToWorkspace::class, class_uses_recursive($model),
                $model.' is workspace-scoped. It is lifecycle-owned or platform-owned; the '
                .'scope fails closed and would hide it from the admin surfaces that manage it.');
        }

        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(SmartQrAssignment::class),
            'The assignment lost its scope. It is unambiguously tenant data for its whole '
            .'life, and it is what every other query joins through.');
    }

    /** The two identifiers are different values with different jobs. */
    #[Test]
    public function the_serial_and_the_public_token_are_different_values(): void
    {
        $code = $this->code();

        $this->assertNotSame($code->serial_number, $code->public_token);
        $this->assertSame('serial_number', $code->getRouteKeyName(),
            'Admin routes must bind by the PRINTED serial. Binding by public_token would put '
            .'the unguessable identifier into URLs, logs and referer headers.');
    }

    /** Both are globally unique — the token addresses a tenant with no session. */
    #[Test]
    public function the_serial_and_the_token_are_globally_unique(): void
    {
        $batch = $this->batch();
        $code = $this->code($batch);

        foreach ([['serial_number', $code->serial_number], ['public_token', $code->public_token]] as [$col, $val]) {
            try {
                SmartQrCode::create([
                    'serial_number' => $col === 'serial_number' ? $val : 'AX-'.Str::random(8),
                    'public_token' => $col === 'public_token' ? $val : Str::random(32),
                    'batch_id' => $batch->id,
                ]);
                $this->fail("A duplicate {$col} was accepted. That column addresses a tenant "
                    .'from an unauthenticated request, so uniqueness is a tenant boundary.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('duplicate', strtolower($e->getMessage()));
            }
        }
    }

    /** The workspace scope on assignments works with no ambient context. */
    #[Test]
    public function assignments_are_scoped_even_with_no_workspace_context(): void
    {
        $a = $this->workspace();
        $b = $this->workspace();
        $this->assign($this->code(), $a);
        $this->assign($this->code(), $b);

        WorkspaceContext::flush();

        $this->assertSame(2, DB::table('smart_qr_assignments')->count(), 'Positive control.');
        $this->assertSame(1, WorkspaceContext::for($a->id, fn () => SmartQrAssignment::count()));
        $this->assertSame(1, WorkspaceContext::for($b->id, fn () => SmartQrAssignment::count()));
    }
}
