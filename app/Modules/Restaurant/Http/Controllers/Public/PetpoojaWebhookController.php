<?php

namespace App\Modules\Restaurant\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Models\PosWebhookRejection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1B — Petpooja sandbox webhook ingress. Secure capture only: no bill
 * processing, no customer sync, no queue dispatch, no outbound messaging.
 * See PosWebhookEvent's class docblock for the two CRITICAL invariants this
 * controller exists to satisfy (workspace_id provenance, raw-byte hashing).
 *
 * Provider-specific by design — this class parses Petpooja's exact payload
 * shape (`properties.Restaurant.restID`, `token`/`Token`). The underlying
 * PosConnection/PosWebhookEvent/PosWebhookRejection models stay
 * provider-neutral for future POS integrations.
 *
 * ⚠️ EVENT VALIDATION IS STRICT — MISSING IS NOT THE SAME AS ORDERDETAILS.
 * Petpooja's own documentation shows `event: "orderdetails"` sent
 * explicitly. An earlier version of this controller defaulted a missing
 * `event` key to 'orderdetails', reasoning that today's real sandbox
 * payloads might omit it — that was rejected: defaulting an ABSENT field to
 * the value that means "this is a final bill" is exactly the silent
 * misclassification a payment/order system cannot afford, documentation or
 * not. So, once authenticated:
 *   - `event === 'orderdetails'` (exact match)      -> pending, processed;
 *   - `event` key absent/null                        -> quarantined,
 *     failure_reason = 'missing_event';
 *   - `event` present but any other value             -> quarantined,
 *     failure_reason = 'unsupported_event'.
 * All three authenticated outcomes still return 200 — only an
 * UNauthenticated request is ever rejected (see the generic 403 below).
 *
 * ⚠️ NO CIDR SUPPORT IN THIS SLICE: allowed_ips is matched by EXACT STRING
 * ONLY. This codebase has no reusable CIDR-range matcher (PublicHttpUrl has
 * private CIDR arithmetic for an unrelated SSRF denylist, not extracted into
 * shared infrastructure) and building one was out of scope for this ingress
 * slice. A connection configured with a CIDR range in allowed_ips will not
 * match anything under this implementation — configure exact IPs only.
 */
class PetpoojaWebhookController extends Controller
{
    private const PROVIDER = 'petpooja';

    private const EVENT_ORDERDETAILS = 'orderdetails';

    private const DUPLICATE_UNIQUE_KEY = 'pos_webhook_events_connection_id_payload_hash_unique';

    public function __invoke(Request $request): JsonResponse
    {
        // ── A. Capture exact bytes, before any JSON decoding ────────────
        $rawBody = $request->getContent();
        $payloadHash = hash('sha256', $rawBody);
        $sourceIp = $request->ip();

        // ── B. Decode and resolve connection ─────────────────────────────
        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            $this->audit(null, $payloadHash, $sourceIp, PosWebhookRejection::REASON_MALFORMED_PAYLOAD);

            return $this->rejected();
        }

        $restId = data_get($payload, 'properties.Restaurant.restID');

        if (! is_string($restId) || $restId === '') {
            $this->audit(null, $payloadHash, $sourceIp, PosWebhookRejection::REASON_MISSING_RESTID);

            return $this->rejected();
        }

        $connection = PosConnection::findByProviderAndRef(self::PROVIDER, $restId);

        if (! $connection) {
            $this->audit(null, $payloadHash, $sourceIp, PosWebhookRejection::REASON_UNKNOWN_RESTID);

            return $this->rejected();
        }

        if ($connection->status !== PosConnection::STATUS_CONNECTED) {
            $this->audit($connection->id, $payloadHash, $sourceIp, PosWebhookRejection::REASON_INACTIVE_CONNECTION);

            return $this->rejected();
        }

        // ── C. Validate the active connection: IP, then token ───────────
        if (! $this->ipAllowed($connection, $sourceIp)) {
            $this->audit($connection->id, $payloadHash, $sourceIp, PosWebhookRejection::REASON_IP_NOT_ALLOWED);

            return $this->rejected();
        }

        $token = $payload['token'] ?? $payload['Token'] ?? null;

        if (! is_string($token) || ! PosConnection::verifyToken($connection, $token)) {
            $this->audit($connection->id, $payloadHash, $sourceIp, PosWebhookRejection::REASON_INVALID_TOKEN);

            return $this->rejected();
        }

        // ── D. Validate event, now that the sender is authenticated ─────
        // No defaulting: a key that is absent/null is MISSING, not
        // 'orderdetails' — see the class docblock for why that distinction
        // is load-bearing.
        $rawEvent = $payload['event'] ?? null;

        if ($rawEvent === self::EVENT_ORDERDETAILS) {
            // ── E. Authenticated, exact orderdetails: persist + duplicate handling ──
            return $this->persist(
                connection: $connection,
                payloadHash: $payloadHash,
                rawBody: $rawBody,
                payload: $payload,
                eventType: self::EVENT_ORDERDETAILS,
                processingStatus: PosWebhookEvent::STATUS_PENDING,
                failureReason: null,
            );
        }

        if ($rawEvent === null) {
            return $this->persist(
                connection: $connection,
                payloadHash: $payloadHash,
                rawBody: $rawBody,
                payload: $payload,
                eventType: null,
                processingStatus: PosWebhookEvent::STATUS_QUARANTINED,
                failureReason: 'missing_event',
            );
        }

        return $this->persist(
            connection: $connection,
            payloadHash: $payloadHash,
            rawBody: $rawBody,
            payload: $payload,
            eventType: is_string($rawEvent) ? $rawEvent : null,
            processingStatus: PosWebhookEvent::STATUS_QUARANTINED,
            failureReason: 'unsupported_event',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persist(
        PosConnection $connection,
        string $payloadHash,
        string $rawBody,
        array $payload,
        ?string $eventType,
        string $processingStatus,
        ?string $failureReason,
    ): JsonResponse {
        try {
            DB::transaction(function () use ($connection, $payloadHash, $rawBody, $payload, $eventType, $processingStatus, $failureReason) {
                PosWebhookEvent::create([
                    'connection_id' => $connection->id,
                    // ⚠️ workspace_id comes ONLY from the resolved connection —
                    // never from anything in $payload. See PosWebhookEvent's
                    // CRITICAL TRUST BOUNDARY docblock.
                    'workspace_id' => $connection->workspace_id,
                    'provider' => self::PROVIDER,
                    'payload_hash' => $payloadHash,
                    'event_type' => $eventType,
                    'received_at' => now(),
                    'processing_status' => $processingStatus,
                    'raw_payload' => $payload,
                    'raw_body' => $rawBody,
                    'failure_reason' => $failureReason,
                    'attempts' => 0,
                ]);

                $connection->update(['last_event_at' => now()]);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateDelivery($e)) {
                // Exact-retry: the row already exists from the original
                // delivery. Same success response, no second row, and
                // last_event_at from the original delivery stands — this
                // is a retry, not a new event.
                return response()->json(['status' => 'ok']);
            }

            // Anything else is a genuine failure: durable acceptance did NOT
            // happen, so this must never claim 200. Logged without the raw
            // payload — the log is not the place to duplicate a webhook body.
            Log::error('Petpooja webhook: unexpected failure persisting event', [
                'connection_id' => $connection->id,
                'payload_hash' => $payloadHash,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Catches ONLY the expected UNIQUE(connection_id, payload_hash)
     * duplicate-key violation — not integrity violations in general, so an
     * unrelated constraint failure still surfaces as a genuine 500 rather
     * than being silently swallowed as "duplicate".
     */
    private function isDuplicateDelivery(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), self::DUPLICATE_UNIQUE_KEY);
    }

    /**
     * IP allowlist, exact match only — see class docblock for the CIDR
     * limitation. An empty/unconfigured allowlist means no IP restriction.
     */
    private function ipAllowed(PosConnection $connection, ?string $sourceIp): bool
    {
        $allowed = $connection->allowed_ips;

        if (empty($allowed)) {
            return true;
        }

        return $sourceIp !== null && in_array($sourceIp, $allowed, true);
    }

    private function audit(?int $connectionId, string $payloadHash, ?string $sourceIp, string $reason): void
    {
        PosWebhookRejection::create([
            'provider' => self::PROVIDER,
            'connection_id' => $connectionId,
            'payload_hash' => $payloadHash,
            'source_ip' => $sourceIp,
            'failure_reason' => $reason,
            'received_at' => now(),
        ]);
    }

    /**
     * The ONE generic rejection response — identical status and body for
     * every rejection reason (malformed JSON, missing/unknown restID,
     * inactive connection, IP mismatch, invalid token). Never reveals which
     * reason applied externally; that detail lives only in
     * pos_webhook_rejections.
     */
    private function rejected(): JsonResponse
    {
        return response()->json(['status' => 'rejected'], 403);
    }
}
