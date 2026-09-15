<?php

namespace App\Modules\Restaurant\Services;

use App\Models\AdminUser;
use App\Modules\Restaurant\Exceptions\ConnectionHasHistoryException;
use App\Modules\Restaurant\Exceptions\ConnectionNotMovableException;
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
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
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
     * ⚠️ UNCHANGED SIGNATURE, ON PURPOSE (Phase 2A Slice 1). This method is
     * called from 50+ existing test call sites across six files. Live
     * ("Live Petpooja") connections now go through the separate
     * createLiveConnection() below rather than this method growing an
     * environment parameter — keeping this one sandbox-only, unchanged, and
     * every existing caller compiling and passing unmodified was the
     * deliberate design choice over a single environment-aware method that
     * would have required touching every one of those call sites for no
     * behavioural benefit. $defaultPhoneCountry is a new, purely additive,
     * trailing optional parameter — no existing 3-arg call site is affected.
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
        ?string $defaultPhoneCountry = null,
    ): PosConnection {
        return $this->createConnection(
            $outlet,
            $externalRef,
            PosConnection::ENVIRONMENT_SANDBOX,
            $allowedIps,
            $defaultPhoneCountry,
            $actor,
        );
    }

    /**
     * Creates a new LIVE ("Live Petpooja") connection — Phase 2A Slice 1.
     * Petpooja has confirmed it provides no sandbox; this is the real,
     * customer-facing integration path, as distinct from
     * createSandboxConnection()'s AutomationXpert-only test connections.
     *
     * Exactly like createSandboxConnection(): begins 'pending', no token,
     * stays that way until the admin explicitly generates one, and does NOT
     * become 'connected' merely by being created — activateLive() (below)
     * is the separate, deliberate step, and it additionally requires the
     * workspace to have accepted the current Petpooja restaurant
     * declaration. That gate is checked at ACTIVATION, not here, so a live
     * connection can be configured (outlet, restID, token, default phone
     * country) well before compliance is satisfied — exactly mirroring how
     * a sandbox connection is configured before its own activation.
     *
     * @param  list<string>|null  $allowedIps
     *
     * @throws OutletAlreadyConnectedException
     */
    public function createLiveConnection(
        RestaurantOutlet $outlet,
        string $externalRef,
        ?array $allowedIps,
        ?string $defaultPhoneCountry,
        ?AdminUser $actor = null,
    ): PosConnection {
        return $this->createConnection(
            $outlet,
            $externalRef,
            PosConnection::ENVIRONMENT_PRODUCTION,
            $allowedIps,
            $defaultPhoneCountry,
            $actor,
        );
    }

    /**
     * The shared creation path behind both public create*() methods above.
     * Environment-agnostic by design: every invariant here (pending status,
     * no token, the active-slot duplicate guard) applies identically to a
     * sandbox or a live connection — only the persisted `environment` value
     * and the audit meta it carries differ.
     *
     * ⚠️ Concurrency-safe duplicate prevention: the DB's
     * UNIQUE(outlet_id, active_slot) constraint (see the migration that
     * added it) is the actual backstop — the caller (StorePosConnectionRequest
     * / the controller) is expected to have already filtered the outlet
     * picker to eligible outlets, but that is a UI convenience, not the
     * guarantee. A repeated/concurrent/UI-bypassed request that races past
     * that filtering still hits this constraint and gets turned into a
     * friendly OutletAlreadyConnectedException here, never a raw
     * duplicate-key exception. Applies to a live connection exactly as it
     * always did to a sandbox one — an outlet may have only one non-archived
     * Petpooja connection, live or test, at a time.
     *
     * @param  list<string>|null  $allowedIps
     *
     * @throws OutletAlreadyConnectedException
     */
    private function createConnection(
        RestaurantOutlet $outlet,
        string $externalRef,
        string $environment,
        ?array $allowedIps,
        ?string $defaultPhoneCountry,
        ?AdminUser $actor,
    ): PosConnection {
        try {
            return DB::transaction(function () use ($outlet, $externalRef, $environment, $allowedIps, $defaultPhoneCountry, $actor) {
                $connection = PosConnection::create([
                    'workspace_id' => $outlet->workspace_id,
                    'outlet_id' => $outlet->id,
                    'provider' => PosConnection::PROVIDER_PETPOOJA,
                    'external_ref' => $externalRef,
                    'allowed_ips' => $allowedIps,
                    'default_phone_country' => $defaultPhoneCountry,
                    'status' => PosConnection::STATUS_PENDING,
                    'environment' => $environment,
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
     *
     * ⚠️ UNCHANGED BEHAVIOUR (Phase 2A Slice 1). Still refuses a
     * non-sandbox connection — that is now correct and no longer
     * misleading: activateLive() (below) is the live counterpart, so a
     * sandbox-only activation method legitimately staying sandbox-only is
     * not the "Sandbox-only dead end" this slice removes. Only the
     * rejection MESSAGE changed, from a "production is not implemented"
     * claim that is no longer true to a pointer at the method that now
     * handles it. No existing test asserts the old message text.
     */
    public function activateSandbox(PosConnection $connection, ?AdminUser $actor = null): PosConnection
    {
        if ($connection->environment !== PosConnection::ENVIRONMENT_SANDBOX) {
            throw new \RuntimeException('Only a sandbox connection can be activated through this action. Use activateLive() for a live Petpooja connection.');
        }

        $this->assertCommonActivationPreconditions($connection);

        return $this->finalizeActivation($connection, $actor, 'restaurant.pos_connection.sandbox_activated');
    }

    /**
     * Flips a LIVE ("Live Petpooja") connection to CONNECTED — Phase 2A
     * Slice 1 introduced this method with only ONE compliance gate
     * (the restaurant declaration); the gate-hardening pass that added this
     * docblock enforces the COMPLETE six-gate production activation
     * invariant AutomationXpert requires before a live connection may go
     * live:
     *
     *   1. current Terms acceptance                — assertTermsAccepted()
     *   2. current DPA acceptance                   — assertDpaAccepted()
     *   3. current Restaurant Declaration acceptance — assertRestaurantDeclarationAccepted()
     *   4. a connected sender/WABA for the workspace — assertConnectedWabaExists()
     *   5. outlet-specific authorization             — assertOutletAuthorizedForLivePos()
     *   6. a unique configured connection token       — assertCommonActivationPreconditions()
     *
     * Gates 1–3 reuse LegalAcceptance/LegalDocumentVersion, the real,
     * already-persisted Phase 1A compliance mechanism — TYPE_TERMS and
     * TYPE_DPA existed on LegalDocumentVersion with zero callers anywhere in
     * application logic before this pass, exactly the same "already-built,
     * never wired in" shape TYPE_RESTAURANT_DECLARATION was in Slice 1.
     *
     * Gate 4 reuses WhatsappBusinessAccount's own established
     * `where('workspace_id', ...)->where('status', 'active')` query, the
     * exact pattern already used by resolveAccessTokenForWorkspace() and
     * defaultPhoneNumberIdForWorkspace() elsewhere in this codebase.
     *
     * Gate 5 has NO prior persisted representation anywhere in this
     * codebase — see the migration that added
     * RestaurantOutlet::pos_live_authorized_at and
     * RestaurantOutletService::authorizeForLivePos() for the new, auditable
     * admin action built to represent it, rather than inventing a fake
     * boolean or silently skipping it.
     *
     * Gate 6 was already fully enforced before this pass
     * (assertCommonActivationPreconditions()'s webhook_secret_hash null
     * check, backed by the DB's own UNIQUE constraint and
     * assignNewToken()'s collision-retry loop) — reused unchanged, just
     * now explicitly documented as gate 6 of the six rather than an
     * unlabelled precondition.
     *
     * None of this applies to activateSandbox(): sandbox connections are
     * AutomationXpert's own test/demo connections, not a live customer
     * integration, and remain intentionally exempt from every compliance
     * gate above — see activateSandbox()'s own docblock, unchanged.
     *
     * ⚠️ FAILS CLOSED: any gate not satisfied throws before
     * finalizeActivation() ever runs, and the failure is also recorded via
     * `restaurant.pos_connection.live_activation_blocked` — identifying
     * which gate category blocked the attempt, never the reason's
     * underlying secret/legal content — see logBlockedLiveActivation().
     *
     * ⚠️ WHAT IS STILL MISSING, DELIBERATELY NOT BUILT HERE: there is no
     * client-facing flow anywhere that lets a workspace actually create a
     * LegalAcceptance row for terms/dpa/restaurant_declaration —
     * routes/client.php is an empty placeholder. In this environment,
     * satisfying gates 1–3 today requires an operator/ops process to record
     * the acceptance directly (exactly as this slice's own tests do), not a
     * self-service screen — a real, reported gap, not a shortcut taken
     * here. Gate 5 DOES have a real admin-facing flow
     * (RestaurantOutletController::authorizeLivePos()) — it was built new,
     * specifically because a database-only workaround was not acceptable
     * for a required gate with no prior source.
     */
    public function activateLive(PosConnection $connection, ?AdminUser $actor = null): PosConnection
    {
        if ($connection->environment !== PosConnection::ENVIRONMENT_PRODUCTION) {
            throw new \RuntimeException('Only a live Petpooja connection can be activated through this action. Use activateSandbox() for an AutomationXpert test/sandbox connection.');
        }

        try {
            $this->assertCommonActivationPreconditions($connection);
            $this->assertTermsAccepted($connection);
            $this->assertDpaAccepted($connection);
            $this->assertRestaurantDeclarationAccepted($connection);
            $this->assertConnectedWabaExists($connection);
            $this->assertOutletAuthorizedForLivePos($connection);
        } catch (\RuntimeException $e) {
            $this->logBlockedLiveActivation($connection, $actor, $this->liveActivationGateCategoryFor($connection, $e));

            throw $e;
        }

        return $this->finalizeActivation($connection, $actor, 'restaurant.pos_connection.live_activated');
    }

    /**
     * Maps a thrown gate exception to a short, stable category name for the
     * audit trail — never the exception's own message, which for the
     * "archived"/"no token" precondition is safe but generic, and which for
     * every other gate is written to be a clear operator-facing sentence,
     * not a value that belongs duplicated into a machine-readable meta
     * field.
     */
    private function liveActivationGateCategoryFor(PosConnection $connection, \RuntimeException $e): string
    {
        return match (true) {
            $e instanceof TermsNotAcceptedException => 'terms',
            $e instanceof DpaNotAcceptedException => 'dpa',
            $e instanceof RestaurantDeclarationNotAcceptedException => 'restaurant_declaration',
            $e instanceof NoConnectedWabaException => 'connected_waba',
            $e instanceof OutletNotAuthorizedForLivePosException => 'outlet_authorization',
            $connection->status === PosConnection::STATUS_ARCHIVED => 'connection_archived',
            default => 'connection_token',
        };
    }

    /**
     * Records a BLOCKED live activation attempt — the category alone, e.g.
     * 'terms' or 'outlet_authorization', never a token, a legal document's
     * content_body/content_sha256, or an exception message that might one
     * day be edited to include either. This is deliberately a separate
     * audit action from the 'live_activated' success action, not a status
     * field on it, so a blocked attempt is never confused with the
     * connection actually starting to accept deliveries.
     */
    private function logBlockedLiveActivation(PosConnection $connection, ?AdminUser $actor, string $gateCategory): void
    {
        $this->auditLog->logAdmin(
            action: 'restaurant.pos_connection.live_activation_blocked',
            targetType: PosConnection::class,
            targetId: $connection->id,
            meta: [
                'workspace_id' => $connection->workspace_id,
                'outlet_id' => $connection->outlet_id,
                'blocked_gate' => $gateCategory,
            ],
            admin: $actor,
        );
    }

    /**
     * The archived/token preconditions shared identically by both
     * activateSandbox() and activateLive() — factored out so the two
     * methods cannot silently drift apart on what "eligible to activate"
     * means, independent of environment.
     */
    private function assertCommonActivationPreconditions(PosConnection $connection): void
    {
        if ($connection->status === PosConnection::STATUS_ARCHIVED) {
            throw new \RuntimeException('An archived connection cannot be resumed. Create a new connection instead.');
        }

        if ($connection->webhook_secret_hash === null) {
            throw new \RuntimeException('Cannot activate a connection with no webhook token configured.');
        }
    }

    /**
     * Gate 1. Same real, already-persisted mechanism as
     * assertRestaurantDeclarationAccepted() below, just a different
     * `document_type`.
     *
     * @throws TermsNotAcceptedException
     */
    private function assertTermsAccepted(PosConnection $connection): void
    {
        $accepted = LegalAcceptance::currentFor(
            $connection->workspace_id,
            LegalDocumentVersion::TYPE_TERMS,
        );

        if ($accepted === null) {
            throw new TermsNotAcceptedException(
                'This workspace has not accepted the current AutomationXpert Terms of Service. A live connection cannot be activated until it has.'
            );
        }
    }

    /**
     * Gate 2. Same real, already-persisted mechanism as
     * assertRestaurantDeclarationAccepted() below, just a different
     * `document_type`.
     *
     * @throws DpaNotAcceptedException
     */
    private function assertDpaAccepted(PosConnection $connection): void
    {
        $accepted = LegalAcceptance::currentFor(
            $connection->workspace_id,
            LegalDocumentVersion::TYPE_DPA,
        );

        if ($accepted === null) {
            throw new DpaNotAcceptedException(
                'This workspace has not accepted the current Data Processing Agreement. A live connection cannot be activated until it has.'
            );
        }
    }

    /**
     * Gate 3.
     *
     * @throws RestaurantDeclarationNotAcceptedException
     */
    private function assertRestaurantDeclarationAccepted(PosConnection $connection): void
    {
        $accepted = LegalAcceptance::currentFor(
            $connection->workspace_id,
            LegalDocumentVersion::TYPE_RESTAURANT_DECLARATION,
        );

        if ($accepted === null) {
            throw new RestaurantDeclarationNotAcceptedException(
                'This workspace has not accepted the current Petpooja restaurant declaration. A live connection cannot be activated until it has.'
            );
        }
    }

    /**
     * Gate 4 — "a connected sender/WABA for the workspace". Reuses
     * WhatsappBusinessAccount's own established query shape, the exact
     * pattern already used by resolveAccessTokenForWorkspace() and
     * defaultPhoneNumberIdForWorkspace() elsewhere in this codebase, rather
     * than inventing a second way to ask "does this workspace have an
     * active WABA".
     *
     * @throws NoConnectedWabaException
     */
    private function assertConnectedWabaExists(PosConnection $connection): void
    {
        $connected = WhatsappBusinessAccount::where('workspace_id', $connection->workspace_id)
            ->where('status', 'active')
            ->exists();

        if (! $connected) {
            throw new NoConnectedWabaException(
                'This workspace has no connected WhatsApp Business Account. A live Petpooja connection cannot be activated until one is connected.'
            );
        }
    }

    /**
     * Gate 5 — "outlet-specific authorization". Unlike gates 1-4, this
     * concept had no existing persisted representation anywhere in the
     * codebase; see the migration that added
     * RestaurantOutlet::pos_live_authorized_at and
     * RestaurantOutletService::authorizeForLivePos() for the new, auditable
     * admin action built to satisfy it.
     *
     * `withoutWorkspaceScope()`: PosConnection::outlet() is a plain,
     * unscoped relation, but the RELATED model (RestaurantOutlet) carries
     * BelongsToWorkspace's global scope, which fails closed to null with no
     * ambient admin/tenant context — the exact same shape already
     * documented on restoreConnection() above. Looked up explicitly here so
     * this gate behaves identically whether or not an admin HTTP request
     * happens to be the caller.
     *
     * @throws OutletNotAuthorizedForLivePosException
     */
    private function assertOutletAuthorizedForLivePos(PosConnection $connection): void
    {
        $outlet = $connection->outlet_id !== null
            ? RestaurantOutlet::withoutWorkspaceScope('reason: resolves the connection\'s outlet for an admin-only lifecycle gate; the relation would fail closed to null with no ambient admin/tenant context, same shape as restoreConnection().')
                ->find($connection->outlet_id)
            : null;

        if ($outlet === null || ! $outlet->isAuthorizedForLivePos()) {
            throw new OutletNotAuthorizedForLivePosException(
                'This outlet has not been authorized for a live Petpooja connection. An admin must authorize the outlet before it can go live.'
            );
        }
    }

    private function finalizeActivation(PosConnection $connection, ?AdminUser $actor, string $auditAction): PosConnection
    {
        return DB::transaction(function () use ($connection, $actor, $auditAction) {
            $connection->update(['status' => PosConnection::STATUS_CONNECTED]);

            $this->auditLog->logAdmin(
                action: $auditAction,
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
     * Sets or clears the connection's default phone country after creation —
     * mirrors updateAllowedIps() exactly (same "editable any time, always
     * audited, never silently applied" shape). $defaultPhoneCountry is
     * expected to already be a validated ISO 3166-1 alpha-2 code or null;
     * this method does not itself validate against PhoneNumber::COUNTRIES —
     * that happens once, at the HTTP boundary (StorePosConnectionRequest /
     * the controller's own validate() call), the same division of
     * responsibility updateAllowedIps() already has with the `ip` rule.
     */
    public function updateDefaultPhoneCountry(PosConnection $connection, ?string $defaultPhoneCountry, ?AdminUser $actor = null): PosConnection
    {
        return DB::transaction(function () use ($connection, $defaultPhoneCountry, $actor) {
            $connection->update(['default_phone_country' => $defaultPhoneCountry]);

            $this->auditLog->logAdmin(
                action: 'restaurant.pos_connection.default_phone_country_changed',
                targetType: PosConnection::class,
                targetId: $connection->id,
                meta: [
                    'workspace_id' => $connection->workspace_id,
                    // The country code itself is not sensitive (unlike an IP
                    // allowlist's actual addresses) — recorded directly,
                    // matching how other non-secret field changes elsewhere
                    // in this service are audited with their new value.
                    'default_phone_country' => $defaultPhoneCountry,
                ],
                admin: $actor,
            );

            return $connection->refresh();
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
