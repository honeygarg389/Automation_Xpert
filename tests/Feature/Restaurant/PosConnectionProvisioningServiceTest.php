<?php

namespace Tests\Feature\Restaurant;

use App\Models\User;
use App\Modules\Restaurant\Exceptions\DpaNotAcceptedException;
use App\Modules\Restaurant\Exceptions\NoConnectedWabaException;
use App\Modules\Restaurant\Exceptions\OutletAlreadyConnectedException;
use App\Modules\Restaurant\Exceptions\OutletNotAuthorizedForLivePosException;
use App\Modules\Restaurant\Exceptions\RestaurantDeclarationNotAcceptedException;
use App\Modules\Restaurant\Exceptions\TermsNotAcceptedException;
use App\Modules\Restaurant\Models\LegalAcceptance;
use App\Modules\Restaurant\Models\LegalDocumentVersion;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\LegalDocumentPublishingService;
use App\Modules\Restaurant\Services\PosConnectionProvisioningService;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1C — the provisioning service's core guarantees: a plaintext token
 * is returned exactly once and is never written to the DB, and rotation
 * genuinely invalidates the old token rather than merely adding a new one.
 *
 * Phase 2A Slice 1 adds createLiveConnection()/activateLive() coverage
 * below, alongside the untouched sandbox tests — proving the sandbox path's
 * behaviour truly is unchanged, not just assumed so from not editing it.
 *
 * The gate-hardening pass afterward expanded activateLive() from one
 * compliance gate (restaurant declaration) to the complete six-gate live
 * activation invariant — see PosConnectionProvisioningService::activateLive()'s
 * own docblock for the numbered list. Each gate below is tested in
 * ISOLATION: satisfyAllLiveActivationGatesExcept() sets up every gate other
 * than the one under test, so a negative test can only be passing because of
 * the specific gate it names, never an accident of some other gate also
 * being unsatisfied.
 */
class PosConnectionProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PosConnectionProvisioningService
    {
        return app(PosConnectionProvisioningService::class);
    }

    /**
     * Records a CURRENT acceptance of $documentType for $workspaceId — the
     * exact real, persisted mechanism activateLive() checks
     * (LegalAcceptance::currentFor()), built via the same publish-then-accept
     * pattern LegalAcceptanceTest.php already establishes. Not a
     * fake/shortcut: this exercises the genuine
     * LegalDocumentVersion/LegalAcceptance models and
     * LegalDocumentPublishingService, the same ones a real (not yet built)
     * client-facing acceptance flow would eventually call. Shared by all
     * three legal-acceptance gates (terms, dpa, restaurant_declaration) —
     * they differ only in `document_type`.
     */
    private function acceptLegalDocumentFor(int $workspaceId, string $documentType): LegalAcceptance
    {
        $version = LegalDocumentVersion::factory()->create(['document_type' => $documentType]);
        app(LegalDocumentPublishingService::class)->publish($version);

        return LegalAcceptance::create([
            'workspace_id' => $workspaceId,
            'document_type' => $documentType,
            'legal_document_version_id' => $version->id,
            'document_version' => $version->version,
            'content_sha256' => $version->content_sha256,
            'accepted_by_user_id' => User::factory()->create()->id,
            'accepted_at' => now(),
        ]);
    }

    private function acceptTermsFor(int $workspaceId): LegalAcceptance
    {
        return $this->acceptLegalDocumentFor($workspaceId, LegalDocumentVersion::TYPE_TERMS);
    }

    private function acceptDpaFor(int $workspaceId): LegalAcceptance
    {
        return $this->acceptLegalDocumentFor($workspaceId, LegalDocumentVersion::TYPE_DPA);
    }

    private function acceptRestaurantDeclarationFor(int $workspaceId): LegalAcceptance
    {
        return $this->acceptLegalDocumentFor($workspaceId, LegalDocumentVersion::TYPE_RESTAURANT_DECLARATION);
    }

    /** Gate 4's real, already-established persisted source — see WhatsappBusinessAccount::resolveAccessTokenForWorkspace(). */
    private function connectWabaFor(int $workspaceId): WhatsappBusinessAccount
    {
        return WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspaceId,
            'status' => 'active',
        ]);
    }

    /** Gate 5's real, new admin action — see RestaurantOutletService::authorizeForLivePos(). */
    private function authorizeOutletForLivePos(RestaurantOutlet $outlet): RestaurantOutlet
    {
        return app(RestaurantOutletService::class)->authorizeForLivePos($outlet);
    }

    /**
     * Satisfies every one of the five compliance/authorization gates
     * activateLive() checks (gate 6, the configured token, is set up
     * separately by every caller via generateToken() — it is a property of
     * the CONNECTION, not the outlet/workspace) EXCEPT whichever gate keys
     * are named in $except, so a negative test can isolate exactly one gate
     * while every other gate is genuinely satisfied — never passing only
     * because some unrelated gate was also left unsatisfied.
     *
     * @param  list<'terms'|'dpa'|'restaurant_declaration'|'connected_waba'|'outlet_authorization'>  $except
     */
    private function satisfyAllLiveActivationGatesExcept(RestaurantOutlet $outlet, array $except = []): void
    {
        if (! in_array('terms', $except, true)) {
            $this->acceptTermsFor($outlet->workspace_id);
        }
        if (! in_array('dpa', $except, true)) {
            $this->acceptDpaFor($outlet->workspace_id);
        }
        if (! in_array('restaurant_declaration', $except, true)) {
            $this->acceptRestaurantDeclarationFor($outlet->workspace_id);
        }
        if (! in_array('connected_waba', $except, true)) {
            $this->connectWabaFor($outlet->workspace_id);
        }
        if (! in_array('outlet_authorization', $except, true)) {
            $this->authorizeOutletForLivePos($outlet);
        }
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

    // ══ Phase 2A Slice 1 — live connection creation/activation ═══════════

    #[Test]
    public function create_live_connection_creates_a_pending_production_connection_with_no_token(): void
    {
        $outlet = RestaurantOutlet::factory()->create();

        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-100', null, 'IN');

        $this->assertSame($outlet->id, $connection->outlet_id);
        $this->assertSame($outlet->workspace_id, $connection->workspace_id);
        $this->assertSame(PosConnection::ENVIRONMENT_PRODUCTION, $connection->environment);
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->status,
            'A live connection must not become connected merely because it was created.');
        $this->assertNull($connection->webhook_secret_hash);
        $this->assertSame('IN', $connection->default_phone_country);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.created',
            'auditable_id' => $connection->id,
        ]);
    }

    #[Test]
    public function create_live_connection_accepts_no_default_phone_country(): void
    {
        $outlet = RestaurantOutlet::factory()->create();

        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-101', null, null);

        $this->assertNull($connection->default_phone_country,
            'Unset must persist as null, never a guessed value — not even India.');
    }

    /**
     * The DB-level "one non-archived connection per outlet" backstop
     * (UNIQUE(outlet_id, active_slot)) must protect a live creation exactly
     * as it already protects a sandbox one — the invariant is
     * environment-agnostic.
     */
    #[Test]
    public function create_live_connection_is_blocked_by_the_same_one_connection_per_outlet_invariant(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $this->service()->createSandboxConnection($outlet, 'REST-LIVE-EXISTING', null);

        $this->expectException(OutletAlreadyConnectedException::class);
        $this->service()->createLiveConnection($outlet, 'REST-LIVE-102', null, null);
    }

    #[Test]
    public function activate_live_requires_a_configured_token(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = PosConnection::factory()->create([
            'outlet_id' => $outlet->id,
            'workspace_id' => $outlet->workspace_id,
            'environment' => PosConnection::ENVIRONMENT_PRODUCTION,
        ]);
        $this->assertNull($connection->webhook_secret_hash);
        // Gate 6 isolated: every OTHER gate is genuinely satisfied, so this
        // failure can only be the missing token.
        $this->satisfyAllLiveActivationGatesExcept($outlet);

        $this->expectException(\RuntimeException::class);
        $this->service()->activateLive($connection->fresh());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.live_activation_blocked',
            'auditable_id' => $connection->id,
        ]);
        $log = DB::table('audit_logs')->where('action', 'restaurant.pos_connection.live_activation_blocked')->where('auditable_id', $connection->id)->first();
        $this->assertStringContainsString('"blocked_gate": "connection_token"', (string) $log->meta);
    }

    /**
     * ⚠️ Gate 1 isolated. This is the real, already-persisted
     * LegalAcceptance/LegalDocumentVersion mechanism (TYPE_TERMS existed
     * since Phase 1A with zero callers before this pass) — enforced here,
     * not bypassed. Audit meta must name the blocked gate without leaking
     * the legal document's own content.
     */
    #[Test]
    public function activate_live_refuses_when_terms_has_not_been_accepted(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-103A', null, null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['terms']);

        $this->expectException(TermsNotAcceptedException::class);
        $this->service()->activateLive($connection->fresh());
    }

    /** Gate 1's audit trail: category only, never token/legal content. */
    #[Test]
    public function activate_live_blocked_by_missing_terms_is_audited_by_category_only(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-103B', null, null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['terms']);

        try {
            $this->service()->activateLive($connection->fresh());
        } catch (TermsNotAcceptedException) {
            // expected
        }

        $log = DB::table('audit_logs')
            ->where('action', 'restaurant.pos_connection.live_activation_blocked')
            ->where('auditable_id', $connection->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('"blocked_gate": "terms"', (string) $log->meta);
        $this->assertStringNotContainsString($connection->webhook_secret_hash ?? '__none__', (string) $log->meta);
    }

    /** Gate 2 isolated — same mechanism as gate 1, different document_type. */
    #[Test]
    public function activate_live_refuses_when_dpa_has_not_been_accepted(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-104A', null, null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['dpa']);

        $this->expectException(DpaNotAcceptedException::class);
        $this->service()->activateLive($connection->fresh());
    }

    /**
     * ⚠️ Gate 3 isolated, mirroring
     * activate_sandbox_refuses_a_production_connection_even_when_called_directly():
     * even with every other gate satisfied, a live connection cannot
     * activate without a CURRENT restaurant-declaration acceptance for its
     * workspace. This is the real, already-persisted compliance gate — see
     * RestaurantDeclarationNotAcceptedException's docblock — enforced here,
     * not bypassed.
     */
    #[Test]
    public function activate_live_refuses_when_the_restaurant_declaration_has_not_been_accepted(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-103', null, null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['restaurant_declaration']);

        $this->expectException(RestaurantDeclarationNotAcceptedException::class);
        $this->service()->activateLive($connection->fresh());
    }

    /** Gate 4 isolated — the workspace has no connected (active) WhatsApp Business Account. */
    #[Test]
    public function activate_live_refuses_when_no_connected_waba_exists(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-105A', null, null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['connected_waba']);

        $this->expectException(NoConnectedWabaException::class);
        $this->service()->activateLive($connection->fresh());
    }

    /**
     * Gate 4's boundary: an INACTIVE WhatsappBusinessAccount row existing
     * for the workspace must not satisfy the gate — only status='active'
     * counts, matching resolveAccessTokenForWorkspace()'s own query exactly.
     */
    #[Test]
    public function activate_live_refuses_when_the_only_waba_for_the_workspace_is_inactive(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-105B', null, null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['connected_waba']);
        WhatsappBusinessAccount::factory()->create(['workspace_id' => $outlet->workspace_id, 'status' => 'inactive']);

        $this->expectException(NoConnectedWabaException::class);
        $this->service()->activateLive($connection->fresh());
    }

    /**
     * Gate 5 isolated — "outlet-specific authorization". Unlike gates 1-4,
     * this concept has NO prior persisted representation anywhere in the
     * codebase; this proves the new admin action
     * (RestaurantOutletService::authorizeForLivePos()) is what actually
     * gates activation, not a database-only field nobody checks.
     */
    #[Test]
    public function activate_live_refuses_when_the_outlet_has_not_been_authorized_for_live_pos(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-106A', null, null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet, except: ['outlet_authorization']);
        $this->assertFalse($outlet->fresh()->isAuthorizedForLivePos());

        $this->expectException(OutletNotAuthorizedForLivePosException::class);
        $this->service()->activateLive($connection->fresh());
    }

    /**
     * The positive control for every negative gate test above: activation
     * succeeds once ALL SIX gates are genuinely satisfied — a live
     * connection is not merely "not blocked for one reason", it actually
     * transitions to CONNECTED and is audited as such.
     */
    #[Test]
    public function activate_live_succeeds_once_every_gate_is_satisfied(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-104', null, null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet);

        $activated = $this->service()->activateLive($connection->fresh());

        $this->assertSame(PosConnection::STATUS_CONNECTED, $activated->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.live_activated',
            'auditable_id' => $connection->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'restaurant.pos_connection.live_activation_blocked',
            'auditable_id' => $connection->id,
        ]);
    }

    /**
     * The mirror of activate_sandbox_refuses_a_production_connection_even_when_called_directly():
     * activateLive() must equally refuse a SANDBOX connection, even with
     * every other gate satisfied — the two activation paths are strictly
     * separate, neither can activate the other's environment.
     */
    #[Test]
    public function activate_live_refuses_a_sandbox_connection_even_when_called_directly(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-LIVE-105', null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet);

        $this->expectException(\RuntimeException::class);
        $this->service()->activateLive($connection->fresh());
    }

    #[Test]
    public function activate_live_refuses_an_archived_connection(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-106', null, null);
        $this->service()->generateToken($connection);
        $this->satisfyAllLiveActivationGatesExcept($outlet);
        $this->service()->archiveConnection($connection->fresh());

        $this->expectException(\RuntimeException::class);
        $this->service()->activateLive($connection->fresh());
    }

    /**
     * Confirms sandbox activation remains completely EXEMPT from every live
     * compliance gate, unchanged by this pass — a sandbox connection
     * activates with a token alone, even though NONE of terms/dpa/restaurant
     * declaration/connected WABA/outlet authorization are satisfied for its
     * workspace or outlet.
     */
    #[Test]
    public function activate_sandbox_remains_exempt_from_every_live_compliance_gate(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-SANDBOX-EXEMPT', null);
        $this->service()->generateToken($connection);

        $this->assertFalse(LegalAcceptance::currentFor($outlet->workspace_id, LegalDocumentVersion::TYPE_TERMS) !== null);
        $this->assertFalse($outlet->fresh()->isAuthorizedForLivePos());

        $activated = $this->service()->activateSandbox($connection->fresh());

        $this->assertSame(PosConnection::STATUS_CONNECTED, $activated->status);
    }

    #[Test]
    public function update_default_phone_country_persists_and_audits_the_new_value(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createSandboxConnection($outlet, 'REST-LIVE-107', null);

        $updated = $this->service()->updateDefaultPhoneCountry($connection, 'GB');

        $this->assertSame('GB', $updated->default_phone_country);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.pos_connection.default_phone_country_changed',
            'auditable_id' => $connection->id,
        ]);
    }

    #[Test]
    public function update_default_phone_country_can_clear_a_previously_set_value(): void
    {
        $outlet = RestaurantOutlet::factory()->create();
        $connection = $this->service()->createLiveConnection($outlet, 'REST-LIVE-108', null, 'IN');

        $updated = $this->service()->updateDefaultPhoneCountry($connection, null);

        $this->assertNull($updated->default_phone_country);
    }
}
