<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\PosConnectionProvisioningService;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1C — the provisioning service's core guarantees: a plaintext token
 * is returned exactly once and is never written to the DB, and rotation
 * genuinely invalidates the old token rather than merely adding a new one.
 */
class PosConnectionProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PosConnectionProvisioningService
    {
        return app(PosConnectionProvisioningService::class);
    }

    #[Test]
    public function create_sandbox_connection_creates_a_pending_sandbox_connection_with_no_token(): void
    {
        $outlet = RestaurantOutlet::factory()->create();

        $connection = $this->service()->createSandboxConnection($outlet, 'REST-100', null);

        $this->assertSame($outlet->id, $connection->outlet_id);
        $this->assertSame($outlet->workspace_id, $connection->workspace_id);
        $this->assertSame(PosConnection::PROVIDER_PETPOOJA, $connection->provider);
        $this->assertSame(PosConnection::ENVIRONMENT_SANDBOX, $connection->environment);
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->status);
        $this->assertNull($connection->webhook_secret_hash, 'Creation must not generate a token — that is a separate, explicit step.');
        $this->assertNull($connection->webhook_secret_rotated_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.created',
            'auditable_id' => $connection->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'restaurant.pos_connection.token_generated',
            'auditable_id' => $connection->id,
        ]);
    }

    #[Test]
    public function generate_token_returns_a_verifying_plaintext_and_audits_without_the_secret(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-101', null);

        $plaintext = $this->service()->generateToken($connection);

        $fresh = $connection->fresh();
        $this->assertTrue(PosConnection::verifyToken($fresh, $plaintext));
        $this->assertNotNull($fresh->webhook_secret_rotated_at);

        $logs = DB::table('audit_logs')->where('auditable_id', $connection->id)->get();
        $this->assertGreaterThan(0, $logs->count());

        foreach ($logs as $log) {
            $this->assertStringNotContainsString($plaintext, (string) $log->meta);
            $this->assertStringNotContainsString($fresh->webhook_secret_hash, (string) $log->meta);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.token_generated',
            'auditable_id' => $connection->id,
        ]);
    }

    #[Test]
    public function rotate_token_invalidates_the_old_token_and_the_new_one_verifies(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-102', null);
        $oldToken = $this->service()->generateToken($connection->fresh());

        $newToken = $this->service()->rotateToken($connection->fresh());

        $this->assertNotSame($oldToken, $newToken);
        $fresh = $connection->fresh();
        $this->assertFalse(PosConnection::verifyToken($fresh, $oldToken), 'The rotated-out token must no longer verify.');
        $this->assertTrue(PosConnection::verifyToken($fresh, $newToken));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.token_rotated',
            'auditable_id' => $connection->id,
        ]);
    }

    #[Test]
    public function activate_sandbox_requires_a_configured_token(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = PosConnection::factory()->create([
            'outlet_id' => $outlet->id,
            'workspace_id' => $outlet->workspace_id,
            'environment' => PosConnection::ENVIRONMENT_SANDBOX,
        ]);
        $this->assertNull($connection->webhook_secret_hash);

        $this->expectException(\RuntimeException::class);
        $this->service()->activateSandbox($connection);
    }

    #[Test]
    public function activate_sandbox_succeeds_once_a_token_exists(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-103', null);
        $this->service()->generateToken($connection);

        $activated = $this->service()->activateSandbox($connection->fresh());

        $this->assertSame(PosConnection::STATUS_CONNECTED, $activated->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.sandbox_activated',
            'auditable_id' => $connection->id,
        ]);
    }

    /**
     * ⚠️ A hard backstop, not merely a UI omission: even called directly,
     * bypassing whatever the admin UI would or would not show, a production
     * connection cannot be activated through this action.
     */
    #[Test]
    public function activate_sandbox_refuses_a_production_connection_even_when_called_directly(): void
    {
        $connection = PosConnection::factory()->create(['environment' => PosConnection::ENVIRONMENT_PRODUCTION]);
        $connection->forceFill(['webhook_secret_hash' => hash('sha256', 'x')])->save();

        $this->expectException(\RuntimeException::class);
        $this->service()->activateSandbox($connection);
    }

    #[Test]
    public function update_allowed_ips_persists_and_audits_without_the_ip_values_in_meta(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-104', null);

        $updated = $this->service()->updateAllowedIps($connection, ['203.0.113.5']);

        $this->assertSame(['203.0.113.5'], $updated->allowed_ips);

        $log = DB::table('audit_logs')->where('action', 'restaurant.pos_connection.allowed_ips_changed')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('203.0.113.5', (string) $log->meta);
    }

    /**
     * The webhook_secret_hash UNIQUE constraint makes a collision
     * structurally impossible to silently overwrite — this proves the
     * retry loop actually engages and succeeds rather than merely
     * asserting the constraint exists. random_bytes() cannot be forced to
     * collide, so this uses the service's one designed extension point
     * (generatePlaintextToken(), protected specifically for this).
     */
    #[Test]
    public function a_webhook_secret_hash_collision_is_retried_transparently(): void
    {
        $colliding = PosConnection::factory()->create();
        $colliding->forceFill(['webhook_secret_hash' => hash('sha256', 'colliding-token')])->save();

        $service = new class(app(AuditLogService::class)) extends PosConnectionProvisioningService
        {
            private int $calls = 0;

            protected function generatePlaintextToken(): string
            {
                $this->calls++;

                return $this->calls === 1 ? 'colliding-token' : 'fresh-token-after-retry';
            }
        };

        $target = PosConnection::factory()->create();
        $token = $service->generateToken($target);

        $this->assertSame('fresh-token-after-retry', $token, 'The first (colliding) attempt must have been retried, not accepted or fatal.');
        $this->assertTrue(PosConnection::verifyToken($target->fresh(), $token));
    }
}
