<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Exceptions\ConnectionHasHistoryException;
use App\Modules\Restaurant\Exceptions\ConnectionNotMovableException;
use App\Modules\Restaurant\Exceptions\OutletAlreadyConnectedException;
use App\Modules\Restaurant\Exceptions\OutletHasActiveConnectionException;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Models\PosWebhookRejection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\PosConnectionProvisioningService;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1C correction — the real defect this pins: outlet_id=1 accumulated
 * THREE live Petpooja connections in the working database because nothing
 * below the UI prevented it. These tests prove the DB-level fix
 * (UNIQUE(outlet_id, active_slot)) actually rejects a second non-archived
 * connection, including under concurrency, and prove every new lifecycle
 * action (pause/resume/archive/delete/move) behaves as the corrected spec
 * requires.
 */
class PosConnectionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PosConnectionProvisioningService
    {
        return app(PosConnectionProvisioningService::class);
    }

    // ══ Duplicate prevention — the actual reported defect ═══════════════

    #[Test]
    public function a_second_connection_on_the_same_outlet_is_rejected_gracefully(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $this->service()->createSandboxConnection($outlet, 'REST-DUP-1', null);

        $this->expectException(OutletAlreadyConnectedException::class);
        $this->service()->createSandboxConnection($outlet, 'REST-DUP-2', null);
    }

    #[Test]
    public function two_distinct_outlets_in_one_workspace_can_each_connect_to_a_distinct_restid(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outletA = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $outletB = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);

        $connectionA = $this->service()->createSandboxConnection($outletA, 'REST-A', null);
        $connectionB = $this->service()->createSandboxConnection($outletB, 'REST-B', null);

        $this->assertSame($outletA->id, $connectionA->outlet_id);
        $this->assertSame($outletB->id, $connectionB->outlet_id);
        $this->assertSame(2, PosConnection::query()->where('workspace_id', $workspace->id)->count());
    }

    /**
     * The exact-repeated-request / concurrency-bypass case: even calling the
     * service directly a second time for the same outlet — not going
     * through any UI filtering at all — is rejected, proving the DB
     * constraint is the real guarantee, not the dropdown filtering.
     */
    #[Test]
    public function a_repeated_or_concurrent_attempt_is_still_rejected_at_the_db_layer(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $this->service()->createSandboxConnection($outlet, 'REST-RACE-1', null);

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->service()->createSandboxConnection($outlet, "REST-RACE-{$i}-retry", null);
                $this->fail('Expected OutletAlreadyConnectedException on repeated attempt '.$i);
            } catch (OutletAlreadyConnectedException) {
                // expected every time
            }
        }

        $this->assertSame(1, PosConnection::query()->where('outlet_id', $outlet->id)->count());
    }

    #[Test]
    public function archiving_a_connection_frees_the_outlet_for_a_new_one(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $first = $this->service()->createSandboxConnection($outlet, 'REST-ARCHIVE-FREE-1', null);

        $this->service()->archiveConnection($first);

        $second = $this->service()->createSandboxConnection($outlet, 'REST-ARCHIVE-FREE-2', null);
        $this->assertSame($outlet->id, $second->outlet_id);
        $this->assertSame(2, PosConnection::query()->where('outlet_id', $outlet->id)->count());
    }

    // ══ Pause / Resume ═══════════════════════════════════════════════════

    #[Test]
    public function pause_persists_paused_and_the_phase_1b_endpoint_rejects_it(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-PAUSE-1', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());

        $paused = $this->service()->pauseConnection($connection->fresh());
        $this->assertSame(PosConnection::STATUS_PAUSED, $paused->status);

        $response = $this->postToWebhook('REST-PAUSE-1', $token);
        $response->assertStatus(403)->assertExactJson(['status' => 'rejected']);
        $this->assertSame(0, PosWebhookEvent::query()->where('connection_id', $connection->id)->count());
    }

    #[Test]
    public function resume_persists_connected_and_the_phase_1b_endpoint_accepts_it(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESUME-1', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        $this->service()->pauseConnection($connection->fresh());

        $resumed = $this->service()->activateSandbox($connection->fresh());
        $this->assertSame(PosConnection::STATUS_CONNECTED, $resumed->status);

        $response = $this->postToWebhook('REST-RESUME-1', $token);
        $response->assertStatus(200)->assertExactJson(['status' => 'ok']);
        $this->assertSame(1, PosWebhookEvent::query()->where('connection_id', $connection->id)->count());
    }

    // ══ Archive ══════════════════════════════════════════════════════════

    #[Test]
    public function archive_persists_archived_and_the_phase_1b_endpoint_rejects_it_while_history_is_retained(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-ARCHIVE-1', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());

        // One real accepted event before archiving, to prove history survives.
        $this->postToWebhook('REST-ARCHIVE-1', $token)->assertStatus(200);
        $this->assertSame(1, PosWebhookEvent::query()->where('connection_id', $connection->id)->count());

        $archived = $this->service()->archiveConnection($connection->fresh());
        $this->assertSame(PosConnection::STATUS_ARCHIVED, $archived->status);

        $response = $this->postToWebhook('REST-ARCHIVE-1', $token);
        $response->assertStatus(403)->assertExactJson(['status' => 'rejected']);

        $this->assertSame(1, PosWebhookEvent::query()->where('connection_id', $connection->id)->count(),
            'Archiving must retain existing webhook history, not delete it.');
    }

    // ══ Reversible Archive / Restore ═════════════════════════════════════

    /**
     * The core contract: restore lands on PAUSED, never straight back to
     * CONNECTED — ingress must stay blocked until an explicit Resume — and
     * the SAME row (restID, uuid, id) is reused, never a new one created.
     */
    #[Test]
    public function restoring_an_archived_connection_lands_on_paused_not_connected_and_ingress_stays_blocked(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-1', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        $this->service()->archiveConnection($connection->fresh());

        $restored = $this->service()->restoreConnection($connection->fresh());

        $this->assertSame(PosConnection::STATUS_PAUSED, $restored->status);
        $this->assertSame($connection->id, $restored->id, 'Restore must reuse the same row, never create a new one.');
        $this->assertSame('REST-RESTORE-1', $restored->external_ref);

        $this->postToWebhook('REST-RESTORE-1', $token)
            ->assertStatus(403)->assertExactJson(['status' => 'rejected']);
    }

    /** Resume from the restored PAUSED state uses the existing canonical STATUS_CONNECTED and the ORIGINAL token — no forced rotation. */
    #[Test]
    public function resuming_a_restored_connection_reaches_connected_and_accepts_the_original_token(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-2', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        $this->service()->archiveConnection($connection->fresh());
        $this->service()->restoreConnection($connection->fresh());

        $resumed = $this->service()->activateSandbox($connection->fresh());

        $this->assertSame(PosConnection::STATUS_CONNECTED, $resumed->status);
        $this->postToWebhook('REST-RESTORE-2', $token)->assertStatus(200);
        $this->assertTrue(PosConnection::verifyToken($resumed->fresh(), $token),
            'The ORIGINAL token must still verify — restore/resume must never force rotation.');
    }

    /**
     * History and restID survive the whole Archive → Restore → Resume
     * cycle attached to the SAME row — nothing is recreated at any step.
     */
    #[Test]
    public function history_and_restid_remain_attached_to_the_same_row_through_archive_restore_and_resume(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-3', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        $this->postToWebhook('REST-RESTORE-3', $token)->assertStatus(200);

        $this->service()->archiveConnection($connection->fresh());
        $this->service()->restoreConnection($connection->fresh());
        $this->service()->activateSandbox($connection->fresh());

        $this->assertSame(1, PosConnection::query()->where('external_ref', 'REST-RESTORE-3')->count(),
            'Exactly one row must exist for this restID — restore must never duplicate it.');
        $this->assertSame(1, PosWebhookEvent::query()->where('connection_id', $connection->id)->count(),
            'The pre-archive accepted event must remain attached to the restored row.');
    }

    /** Restoring the outlet alone (not its connection) must never resume ingress. */
    #[Test]
    public function restoring_the_outlet_alone_does_not_resume_the_connections_ingress(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-4', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        $this->service()->archiveConnection($connection->fresh());
        app(RestaurantOutletService::class)->archiveOutlet($outlet->fresh());

        app(RestaurantOutletService::class)->restoreOutlet($outlet->fresh());

        $this->assertSame(RestaurantOutlet::STATUS_ACTIVE, $outlet->fresh()->status);
        $this->assertSame(PosConnection::STATUS_ARCHIVED, $connection->fresh()->status,
            'The connection must stay archived — restoring the outlet is not restoring the connection.');
        $this->postToWebhook('REST-RESTORE-4', $token)
            ->assertStatus(403)->assertExactJson(['status' => 'rejected']);
    }

    /** A second, still-live connection on the same outlet must block restoring the archived one. */
    #[Test]
    public function restoring_a_connection_is_blocked_if_the_outlet_already_has_a_non_archived_connection(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-5', null);
        $this->service()->archiveConnection($connection->fresh());

        // The archive freed the outlet's active_slot — a second, distinct
        // connection now legitimately occupies it.
        $this->service()->createSandboxConnection($outlet->fresh(), 'REST-RESTORE-5B', null);

        $this->expectException(OutletAlreadyConnectedException::class);
        $this->service()->restoreConnection($connection->fresh());
    }

    /** Restoring a connection whose outlet is itself archived is refused — it would leave a live connection under a retired outlet. */
    #[Test]
    public function restoring_a_connection_is_blocked_while_its_outlet_is_archived(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-6', null);
        $this->service()->archiveConnection($connection->fresh());
        app(RestaurantOutletService::class)->archiveOutlet($outlet->fresh());

        $this->expectException(\RuntimeException::class);
        $this->service()->restoreConnection($connection->fresh());
    }

    #[Test]
    public function restoring_a_non_archived_connection_is_refused(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-RESTORE-7', null);

        $this->expectException(\RuntimeException::class);
        $this->service()->restoreConnection($connection);
    }

    // ══ Outlet archive safety invariant (Section H.3) ════════════════════

    /**
     * The chosen invariant: outlet archival is BLOCKED while a non-archived
     * connection exists, rather than silently cascading — so this proves
     * the archived END STATE (outlet.status === archived) is never reached
     * while its connection can still accept ingress, by exercising the
     * actual Phase 1B webhook endpoint before and after each step.
     */
    #[Test]
    public function archiving_an_outlet_never_leaves_a_non_archived_connection_still_accepting_ingress(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-OUTLET-SAFE-1', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());

        $this->postToWebhook('REST-OUTLET-SAFE-1', $token)->assertStatus(200);

        // Blocked while the connection is still connected — the outlet must
        // NOT archive, and ingress must remain untouched by the attempt.
        $this->expectException(OutletHasActiveConnectionException::class);

        try {
            app(RestaurantOutletService::class)->archiveOutlet($outlet);
        } finally {
            $this->assertNotSame(RestaurantOutlet::STATUS_ARCHIVED, $outlet->fresh()->status);
            $this->assertSame(PosConnection::STATUS_CONNECTED, $connection->fresh()->status);
            $this->postToWebhook('REST-OUTLET-SAFE-1', $token)->assertStatus(200);
        }
    }

    #[Test]
    public function archiving_the_connection_first_lets_the_outlet_archive_and_ingress_then_stays_rejected(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-OUTLET-SAFE-2', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());

        $this->service()->archiveConnection($connection->fresh());

        $archivedOutlet = app(RestaurantOutletService::class)->archiveOutlet($outlet);
        $this->assertSame(RestaurantOutlet::STATUS_ARCHIVED, $archivedOutlet->status);

        // The invariant, end to end: an archived outlet's connection is
        // ALSO archived, and the real webhook endpoint rejects it.
        $this->assertSame(PosConnection::STATUS_ARCHIVED, $connection->fresh()->status);
        $this->postToWebhook('REST-OUTLET-SAFE-2', $token)
            ->assertStatus(403)->assertExactJson(['status' => 'rejected']);
    }

    // ══ Delete test connection ═══════════════════════════════════════════

    #[Test]
    public function delete_test_connection_succeeds_with_zero_events(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-DELETE-1', null);

        $this->service()->deleteTestConnection($connection);

        $this->assertSame(0, PosConnection::query()->where('external_ref', 'REST-DELETE-1')->count());
    }

    #[Test]
    public function delete_test_connection_is_blocked_once_an_event_exists(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-DELETE-2', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        $this->postToWebhook('REST-DELETE-2', $token)->assertStatus(200);

        $this->expectException(ConnectionHasHistoryException::class);
        $this->service()->deleteTestConnection($connection->fresh());
    }

    #[Test]
    public function delete_test_connection_is_blocked_once_a_rejection_exists(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-DELETE-3', null);
        $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());

        // A wrong token creates a rejection row referencing this connection.
        $this->postToWebhook('REST-DELETE-3', 'totally-wrong-token')->assertStatus(403);
        $this->assertSame(1, PosWebhookRejection::query()->where('connection_id', $connection->id)->count());

        $this->expectException(ConnectionHasHistoryException::class);
        $this->service()->deleteTestConnection($connection->fresh());
    }

    // ══ Guarded move ═════════════════════════════════════════════════════

    #[Test]
    public function moving_a_pre_event_connection_succeeds_and_rotates_the_token(): void
    {
        $sourceOutlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($sourceOutlet, 'REST-MOVE-1', null);
        $oldToken = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());

        ['workspace' => $targetWorkspace] = $this->createWorkspaceContext();
        $targetOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $targetWorkspace->id]);

        [$moved, $newToken] = $this->service()->moveConnection($connection->fresh(), $targetOutlet);

        $this->assertSame($targetWorkspace->id, $moved->workspace_id);
        $this->assertSame($targetOutlet->id, $moved->outlet_id);
        $this->assertSame(PosConnection::STATUS_PAUSED, $moved->status, 'A move must not leave the connection live at the new identity automatically.');
        $this->assertNotSame($oldToken, $newToken);
        $this->assertFalse(PosConnection::verifyToken($moved, $oldToken), 'The old token must be invalidated by the move.');
        $this->assertTrue(PosConnection::verifyToken($moved, $newToken));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.moved',
            'auditable_id' => $connection->id,
        ]);
    }

    #[Test]
    public function moving_a_connection_with_history_is_blocked(): void
    {
        $sourceOutlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($sourceOutlet, 'REST-MOVE-2', null);
        $token = $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        $this->postToWebhook('REST-MOVE-2', $token)->assertStatus(200);

        $targetOutlet = RestaurantOutlet::factory()->create();

        $this->expectException(ConnectionHasHistoryException::class);
        $this->service()->moveConnection($connection->fresh(), $targetOutlet);
    }

    #[Test]
    public function moving_a_connection_to_an_already_connected_target_outlet_is_blocked(): void
    {
        $sourceOutlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($sourceOutlet, 'REST-MOVE-3', null);

        $targetOutlet = RestaurantOutlet::factory()->create();
        $this->service()->createSandboxConnection($targetOutlet, 'REST-MOVE-3-TARGET', null);

        $this->expectException(ConnectionNotMovableException::class);
        $this->service()->moveConnection($connection->fresh(), $targetOutlet->fresh());
    }

    // ══ Shared WhatsApp sender is untouched by any of this ═══════════════

    #[Test]
    public function outlet_and_connection_lifecycle_actions_never_touch_channel_accounts(): void
    {
        $this->assertSame(0, ChannelAccount::query()->count());

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outletA = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $outletB = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $connection = $this->service()->createSandboxConnection($outletA, 'REST-WABA-1', null);
        $this->service()->generateToken($connection);
        $this->service()->activateSandbox($connection->fresh());
        $this->service()->pauseConnection($connection->fresh());
        $this->service()->archiveConnection($connection->fresh());

        $this->assertSame(0, ChannelAccount::query()->count(),
            'Outlet Management must never create/touch a WhatsApp channel account — one workspace shares one sender.');
    }

    /**
     * Posts a valid-shaped Petpooja orderdetails payload through the real
     * Phase 1B route, matching PetpoojaWebhookIngressTest's fixture shape.
     */
    /** @return TestResponse<Response> */
    private function postToWebhook(string $restId, string $token): TestResponse
    {
        $payload = [
            'event' => 'orderdetails',
            'orderID' => 'ORD-'.uniqid(),
            'properties' => ['Restaurant' => ['restID' => $restId]],
            'token' => $token,
        ];

        return $this->call(
            'POST',
            '/webhooks/pos/petpooja',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload),
        );
    }
}
