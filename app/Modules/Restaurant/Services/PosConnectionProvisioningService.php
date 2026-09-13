<?php

namespace App\Modules\Restaurant\Services;

use App\Models\AdminUser;
use App\Modules\Restaurant\Exceptions\ConnectionHasHistoryException;
use App\Modules\Restaurant\Exceptions\ConnectionNotMovableException;
use App\Modules\Restaurant\Exceptions\OutletAlreadyConnectedException;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1C — the ONLY place a Petpooja webhook token's plaintext form ever
 * exists outside the immediate HTTP response that returns it.
 *
 * ⚠️ THE CENTRAL GUARANTEE: every method here that generates a token returns
 * the plaintext to its caller EXACTLY ONCE and never persists it anywhere —
 * not on the model (only webhook_secret_hash is ever written), not in a log
 * line, not in an audit record, not in cache, not in a queued job. A caller
 * that loses the returned string has lost the token; there is no recovery
 * path, by design (see PosConnection::verifyToken() — only the hash is
 * ever compared against).
 *
 * Reuses PosConnection::verifyToken()'s exact hashing convention
 * (`hash('sha256', $plaintext)`, no HMAC, no salt) rather than inventing a
 * second algorithm — the same reasoning as WhatsappBusinessAccount's
 * webhook_verify_token_hash this codebase already established.
 */
class PosConnectionProvisioningService
{
    /** 32 bytes = 256 bits of entropy, matching random_bytes' own security guidance for tokens. */
    private const TOKEN_BYTES = 32;

    private const MAX_TOKEN_GENERATION_ATTEMPTS = 5;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Creates a new SANDBOX connection for an already-resolved outlet, with
     * NO token — deliberately. The connection begins in 'pending' status
     * with `webhook_secret_hash` null, and stays that way until the admin
     * takes the separate, explicit "Generate Webhook Token" action on the
     * detail page. An earlier version of this method generated a token
     * immediately at creation, which was wrong: it made "Generate Webhook
     * Token" a button that would never actually have anything to do,
     * because every connection would already have one the moment it
     * existed — collapsing two deliberately separate steps into one.
     *
     * There is no code path in this method (or anywhere in this class) that
     * can create or activate a 'production' environment connection —
     * Phase 1C's UI never offers one.
     *
     * ⚠️ Concurrency-safe duplicate prevention: the DB's
     * UNIQUE(outlet_id, active_slot) constraint (see the migration that
     * added it) is the actual backstop — this method's caller
     * (StorePosConnectionRequest / the controller) is expected to have
     * already filtered the outlet picker to eligible outlets, but that is a
     * UI convenience, not the guarantee. A repeated/concurrent/UI-bypassed
     * request that races past that filtering still hits this constraint and
     * gets turned into a friendly OutletAlreadyConnectedException here,
     * never a raw duplicate-key exception.
     *
     * @param  list<string>|null  $allowedIps
     *
     * @throws OutletAlreadyConnectedException
     */
    public function createSandboxConnection(
        RestaurantOutlet $outlet,
        string $externalRef,
        ?array $allowedIps,
        ?AdminUser $actor = null,
    ): PosConnection {
        try {
            return DB::transaction(function () use ($outlet, $externalRef, $allowedIps, $actor) {
                $connection = PosConnection::create([
                    'workspace_id' => $outlet->workspace_id,
                    'outlet_id' => $outlet->id,
                    'provider' => PosConnection::PROVIDER_PETPOOJA,
                    'external_ref' => $externalRef,
                    'allowed_ips' => $allowedIps,
                    'status' => PosConnection::STATUS_PENDING,
                    'environment' => PosConnection::ENVIRONMENT_SANDBOX,
                ]);

                $this->auditLog->logAdmin(
                    action: 'restaurant.pos_connection.created',
                    targetType: PosConnection::class,
                    targetId: $connection->id,
                    meta: [
                        'workspace_id' => $connection->workspace_id,
                        'outlet_id' => $connection->outlet_id,
                        'provider' => $connection->provider,
                        'environment' => $connection->environment,
                    ],
                    admin: $actor,
                );

                return $connection->refresh();
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateActiveSlot($e)) {
                throw new OutletAlreadyConnectedException(
                    'This outlet already has an active Petpooja connection. Open its existing configuration instead of creating a new one.'
                );
            }

            throw $e;
        }
    }

    /**
     * Generates the FIRST token for a connection that does not have one yet.
     * Mechanically identical to rotate() — the method name is a caller-facing
     * distinction ("Generate" vs "Rotate" in the UI), not a different
     * algorithm or a different guarantee.
     */
    public function generateToken(PosConnection $connection, ?AdminUser $actor = null): string
    {
        return DB::transaction(fn () => $this->assignNewToken(
            $connection,
            auditAction: 'restaurant.pos_connection.token_generated',
            actor: $actor,
        ));
    }

    /**
     * Replaces an existing token. The OLD token is invalid the instant this
     * transaction commits: verifyToken() only ever compares against the
     * single webhook_secret_hash column, so overwriting it IS revocation —
     * there is no separate "revoke" step to forget.
     */
    public function rotateToken(PosConnection $connection, ?AdminUser $actor = null): string
    {
        return DB::transaction(fn () => $this->assignNewToken(
            $connection,
            auditAction: 'restaurant.pos_connection.token_rotated',
            actor: $actor,
        ));
    }

    /**
     * Flips a sandbox connection to CONNECTED (the ONE canonical persisted
     * "ingress accepted" value — see the STATUS_CONNECTED constant) so
     * Petpooja's sandbox deliveries can reach the Phase 1B ingress endpoint.
     * "Activate" from pending and "Resume" from paused are the SAME action —
     * both just mean "this connection should now accept deliveries."
     *
     * Hard backstop, not just a UI affordance: refuses outright for a
     * non-sandbox connection, an archived connection (terminal — archiving
     * is a one-way door in this phase), or one with no token configured,
     * even though the admin UI is expected to never show this action in any
     * of those cases — the same belt-and-suspenders posture as this
     * module's other guarantees (defense in depth, not decoration).
     */
    public function activateSandbox(PosConnection $connection, ?AdminUser $actor = null): PosConnection
    {
        if ($connection->environment !== PosConnection::ENVIRONMENT_SANDBOX) {
            throw new \RuntimeException('Only a sandbox connection can be activated through this action. Production activation is not implemented.');
        }

        if ($connection->status === PosConnection::STATUS_ARCHIVED) {
            throw new \RuntimeException('An archived connection cannot be resumed. Create a new connection instead.');
        }

        if ($connection->webhook_secret_hash === null) {
            throw new \RuntimeException('Cannot activate a connection with no webhook token configured.');
        }

        return DB::transaction(function () use ($connection, $actor) {
            $connection->update(['status' => PosConnection::STATUS_CONNECTED]);

            $this->auditLog->logAdmin(
                action: 'restaurant.pos_connection.sandbox_activated',
                targetType: PosConnection::class,
                targetId: $connection->id,
                meta: [
                    'workspace_id' => $connection->workspace_id,
                    'environment' => $connection->environment,
                ],
                admin: $actor,
            );

            return $connection->refresh();
        });
    }

    /**
     * Persists PAUSED. Phase 1B's ingress check
     * (`$connection->status !== PosConnection::STATUS_CONNECTED`) already
     * rejects anything that is not exactly STATUS_CONNECTED, so a paused
     * connection is refused automatically — no change to the webhook
     * controller was needed for this to work.
     */
    public function pauseConnection(PosConnection $connection, ?AdminUser $actor = null): PosConnection
    {
        if ($connection->status === PosConnection::STATUS_ARCHIVED) {
            throw new \RuntimeException('An archived connection cannot be paused.');
        }

        return DB::transaction(function () use ($connection, $actor) {
            $connection->update(['status' => PosConnection::STATUS_PAUSED]);

            $this->auditLog->logAdmin(
                action: 'restaurant.pos_connection.paused',
                targetType: PosConnection::class,
                targetId: $connection->id,
                meta: ['workspace_id' => $connection->workspace_id],
                admin: $actor,
            );

            return $connection->refresh();
        });
    }

    /**
     * Permanently blocks ingress (same rejected-by-Phase-1B reasoning as
     * pause) while retaining the row, its webhook history and its audit
     * trail. Also the ONLY transition that frees the outlet's active_slot —
     * see the model's saving() hook — so a new connection can be created
     * for the same outlet afterward. Allowed from any non-archived status;
     * archiving is always safe, unlike activating or pausing.
     */
    public function archiveConnection(PosConnection $connection, ?AdminUser $actor = null): PosConnection
    {
        return DB::transaction(function () use ($connection, $actor) {
            $connection->update(['status' => PosConnection::STATUS_ARCHIVED]);

            $this->auditLog->logAdmin(
                action: 'restaurant.pos_connection.archived',
                targetType: PosConnection::class,
                targetId: $connection->id,
                meta: ['workspace_id' => $connection->workspace_id],
                admin: $actor,
            );

            return $connection->refresh();
        });
    }

    /**
     * Reverses archiveConnection() — a reversible soft-archive, not a
     * deletion. Restores the SAME row to PAUSED, never straight back to
     * CONNECTED, so ingress stays blocked until a Super Admin explicitly
     * clicks Resume (activateSandbox() — the existing "Activate/Resume"
     * action already handles paused → connected; no new method is needed
     * for that step). The restID, webhook history and audit trail all
     * belong to this row already and are left untouched — nothing new is
     * ever created, so the global (provider, external_ref) uniqueness rule
     * cannot be violated by a restore.
     *
     * ⚠️ Token is DELIBERATELY left as-is — no forced rotation. Archiving is
     * not itself evidence of compromise, and forcing rotation on every
     * ordinary restore would make "restore" indistinguishable from "start
     * over" for an admin who simply archived by mistake. The existing
     * Rotate Token action remains one click away and should be recommended
     * — not required — when the archive was security-motivated.
     *
     * @throws OutletAlreadyConnectedException
     */
    public function restoreConnection(PosConnection $connection, ?AdminUser $actor = null): PosConnection
    {
        if ($connection->status !== PosConnection::STATUS_ARCHIVED) {
            throw new \RuntimeException('Only an archived connection can be restored.');
        }

        // `withoutWorkspaceScope()`: PosConnection::outlet() is a plain,
        // unscoped relation (see its own docblock), but the RELATED model
        // (RestaurantOutlet) carries BelongsToWorkspace's global scope —
        // which applies to ANY query for that model, including one reached
        // through another model's relation. With no ambient admin/tenant
        // context (e.g. this service called directly, as tests do — the
        // same shape already documented on eligibleForNewConnection()),
        // `$connection->outlet` fails CLOSED to null even for a valid
        // outlet_id. Looked up explicitly here rather than via the relation
        // so this method behaves identically whether or not an admin HTTP
        // request happens to be the caller.
        $outlet = $connection->outlet_id !== null
            ? RestaurantOutlet::withoutWorkspaceScope('reason: resolves the connection\'s outlet for an admin-only lifecycle action; the relation would fail closed to null with no ambient admin/tenant context, same shape as eligibleForNewConnection().')
                ->find($connection->outlet_id)
            : null;

        if ($outlet === null) {
            throw new \RuntimeException('This connection has no outlet and cannot be restored.');
        }

        if ($outlet->status !== RestaurantOutlet::STATUS_ACTIVE) {
            throw new \RuntimeException('The outlet for this connection is archived. Restore the outlet first.');
        }

        // Friendly pre-check: archiving is the only transition that frees an
        // outlet's active_slot, so restoring reoccupies it. If some OTHER
        // connection has since taken that slot, restoring this one would
        // create exactly the "two live connections on one outlet" defect
        // Task D exists to prevent — checked ahead of the write for a clean
        // error message; the UNIQUE(outlet_id, active_slot) constraint below
        // is the real backstop for a concurrent/repeated attempt that races
        // past this check.
        if ($outlet->hasNonArchivedConnection()) {
            throw new OutletAlreadyConnectedException(
                'This outlet already has an active Petpooja connection. Archive or move it before restoring this one.'
            );
        }

        try {
            return DB::transaction(function () use ($connection, $actor) {
                $connection->update(['status' => PosConnection::STATUS_PAUSED]);

                $this->auditLog->logAdmin(
                    action: 'restaurant.pos_connection.restored',
                    targetType: PosConnection::class,
                    targetId: $connection->id,
                    meta: ['workspace_id' => $connection->workspace_id, 'outlet_id' => $connection->outlet_id],
                    admin: $actor,
                );

                return $connection->refresh();
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateActiveSlot($e)) {
                throw new OutletAlreadyConnectedException(
                    'This outlet already has an active Petpooja connection. Archive or move it before restoring this one.'
                );
            }

            throw $e;
        }
    }

    /**
     * Hard-deletes a connection — the only destructive action in this
     * service. Guarded twice: only a SANDBOX connection, and only one with
     * ZERO webhook history (accepted or rejected). A connection Petpooja has
     * actually talked to must be archived instead, never deleted — deletion
     * exists purely to clean up accidental test rows, and history is
     * exactly what makes a row not "accidental" anymore.
     *
     * @throws ConnectionHasHistoryException
     */
    public function deleteTestConnection(PosConnection $connection, ?AdminUser $actor = null): void
    {
        if ($connection->environment !== PosConnection::ENVIRONMENT_SANDBOX) {
            throw new \RuntimeException('Only a sandbox connection can be deleted through this action.');
        }

        if ($connection->hasWebhookHistory()) {
            throw new ConnectionHasHistoryException(
                'This connection has webhook history and cannot be deleted. Archive it instead to retain the record.'
            );
        }

        DB::transaction(function () use ($connection, $actor) {
            $meta = [
                'workspace_id' => $connection->workspace_id,
                'outlet_id' => $connection->outlet_id,
                'external_ref' => $connection->external_ref,
            ];
            $connectionId = $connection->id;

            $connection->delete();

            $this->auditLog->logAdmin(
                action: 'restaurant.pos_connection.test_connection_deleted',
                targetType: PosConnection::class,
                targetId: $connectionId,
                meta: $meta,
                admin: $actor,
            );
        });
    }

    /**
     * The GUARDED Super Admin correction flow for an accidental workspace/
     * outlet mapping. There is deliberately NO direct "disconnect
     * workspace" or unrestricted reassignment action anywhere in this
     * module — this is the only path, and it is blocked outright once any
     * webhook history exists: a connection Petpooja has actually talked to
     * needs manual review, not a self-service button.
     *
     * The connection is PAUSED as part of the move (not left connected, and
     * not automatically reconnected at the destination) — an admin
     * reviewing a corrected mapping should have to deliberately reactivate
     * it, the same "nothing dangerous happens silently" posture as
     * everything else in this service.
     *
     * @return array{0: PosConnection, 1: string} [the moved connection, the new one-time plaintext token]
     *
     * @throws ConnectionNotMovableException
     * @throws ConnectionHasHistoryException
     */
    public function moveConnection(PosConnection $connection, RestaurantOutlet $targetOutlet, ?AdminUser $actor = null): array
    {
        if ($connection->environment !== PosConnection::ENVIRONMENT_SANDBOX) {
            throw new ConnectionNotMovableException('Only a sandbox connection can be moved through this action.');
        }

        if ($connection->hasWebhookHistory()) {
            throw new ConnectionHasHistoryException(
                'This connection has webhook history and cannot be self-service moved. It requires Super Admin review.'
            );
        }

        if ($targetOutlet->workspace_id === $connection->workspace_id && $targetOutlet->id === $connection->outlet_id) {
            throw new ConnectionNotMovableException('The target outlet is the same as the connection\'s current outlet.');
        }

        if ($targetOutlet->status !== RestaurantOutlet::STATUS_ACTIVE || $targetOutlet->hasNonArchivedConnection()) {
            throw new ConnectionNotMovableException('The target outlet is not eligible — it must be active and have no existing Petpooja connection.');
        }

        return DB::transaction(function () use ($connection, $targetOutlet, $actor) {
            $sourceWorkspaceId = $connection->workspace_id;
            $sourceOutletId = $connection->outlet_id;

            // Pause FIRST, in the same update as the reassignment — the
            // connection must never keep accepting deliveries under its old
            // identity for even one request while being relocated.
            $connection->update([
                'status' => PosConnection::STATUS_PAUSED,
                'workspace_id' => $targetOutlet->workspace_id,
                'outlet_id' => $targetOutlet->id,
            ]);

            $newToken = $this->assignNewToken(
                $connection,
                auditAction: 'restaurant.pos_connection.token_rotated',
                actor: $actor,
            );

            $this->auditLog->logAdmin(
                action: 'restaurant.pos_connection.moved',
                targetType: PosConnection::class,
                targetId: $connection->id,
                meta: [
                    'source_workspace_id' => $sourceWorkspaceId,
                    'destination_workspace_id' => $targetOutlet->workspace_id,
                    'source_outlet_id' => $sourceOutletId,
                    'destination_outlet_id' => $targetOutlet->id,
                ],
                admin: $actor,
            );

            return [$connection->refresh(), $newToken];
        });
    }

    /**
     * @param  list<string>|null  $allowedIps
     */
    public function updateAllowedIps(PosConnection $connection, ?array $allowedIps, ?AdminUser $actor = null): PosConnection
    {
        return DB::transaction(function () use ($connection, $allowedIps, $actor) {
            $connection->update(['allowed_ips' => $allowedIps]);

            $this->auditLog->logAdmin(
                action: 'restaurant.pos_connection.allowed_ips_changed',
                targetType: PosConnection::class,
                targetId: $connection->id,
                meta: [
                    'workspace_id' => $connection->workspace_id,
                    // Count only, never the IP values themselves in the audit
                    // trail's meta — the values are visible on the connection
                    // itself to anyone with permission to view it; this just
                    // records THAT a change happened.
                    'allowed_ip_count' => $allowedIps === null ? 0 : count($allowedIps),
                ],
                admin: $actor,
            );

            return $connection->refresh();
        });
    }

    /**
     * The one place a plaintext token is ever generated. Retries on the
     * astronomically unlikely webhook_secret_hash UNIQUE collision — a
     * SHA-256 digest of a 256-bit random value colliding with an existing
     * row is a ~2^-128-scale birthday-bound event, but the constraint exists
     * precisely so this can never silently overwrite someone else's token,
     * so a collision is retried rather than ignored.
     */
    private function assignNewToken(PosConnection $connection, string $auditAction, ?AdminUser $actor): string
    {
        for ($attempt = 1; $attempt <= self::MAX_TOKEN_GENERATION_ATTEMPTS; $attempt++) {
            $plaintext = $this->generatePlaintextToken();

            try {
                $connection->forceFill([
                    'webhook_secret_hash' => hash('sha256', $plaintext),
                    'webhook_secret_rotated_at' => now(),
                ])->save();

                $this->auditLog->logAdmin(
                    action: $auditAction,
                    targetType: PosConnection::class,
                    targetId: $connection->id,
                    meta: [
                        'workspace_id' => $connection->workspace_id,
                        // Deliberately absent: token value, token hash, any
                        // substring/prefix of either. This meta array is the
                        // entire audit record for this action — nothing else
                        // is written anywhere.
                    ],
                    admin: $actor,
                );

                return $plaintext;
            } catch (QueryException $e) {
                if ($this->isDuplicateHash($e) && $attempt < self::MAX_TOKEN_GENERATION_ATTEMPTS) {
                    continue;
                }

                throw $e;
            }
        }

        throw new \RuntimeException('Failed to generate a unique webhook token after '.self::MAX_TOKEN_GENERATION_ATTEMPTS.' attempts.');
    }

    /**
     * base64url (RFC 4648 §5), unpadded: safe to select and copy from a UI
     * with no `+`, `/`, or `=` characters that a double-click or a URL
     * context could mangle, while staying more compact than hex for the
     * same entropy.
     *
     * `protected`, not `private`: the sole extension point a test uses to
     * force the collision-retry path deterministically (PHP's random_bytes
     * cannot otherwise be made to return a chosen value) — see
     * PosConnectionProvisioningServiceTest's collision-forcing subclass.
     */
    protected function generatePlaintextToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    private function isDuplicateHash(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'pos_connections_webhook_secret_hash_unique');
    }

    /**
     * The DB-level backstop behind "a physical outlet may have only one
     * non-archived Petpooja connection at a time" — matches ONLY the
     * specific UNIQUE(outlet_id, active_slot) violation, not integrity
     * violations in general, so an unrelated constraint failure still
     * surfaces as a genuine error rather than being misreported as
     * "already connected".
     */
    private function isDuplicateActiveSlot(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'pos_connections_outlet_id_active_slot_unique');
    }
}
