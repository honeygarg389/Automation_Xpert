<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Workspace;
use Database\Factories\PosWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw capture of an inbound POS webhook event.
 *
 * ⚠️ NOT workspace-scoped, on purpose — see WorkspaceScopeCoverageGuardTest's
 * PENDING list. `workspace_id` is a populated data column used for querying
 * valid events, but the table must remain writable/visible for quarantined
 * rows that have no resolvable tenant at all (see findActiveByProviderAndRef()
 * failing to match anything).
 *
 * ⚠️⚠️⚠️ CRITICAL TRUST BOUNDARY — READ BEFORE WIRING UP PHASE 1B INGRESS ⚠️⚠️⚠️
 *
 * `workspace_id` on this table must ONLY EVER be populated by copying it from
 * the RESOLVED `pos_connections` row — i.e. `$connection->workspace_id` after
 * a successful `PosConnection::findActiveByProviderAndRef()` lookup. It must
 * NEVER be set from any value present in or derived from the inbound webhook
 * payload itself. The payload is attacker-controlled input; trusting any
 * tenant-identifying value from it would let a malicious sender claim events
 * for an arbitrary workspace — the exact shape of a cross-tenant data
 * injection. This is deliberately documented here, before Phase 1B implements
 * the ingress controller, so the implementer cannot miss it.
 *
 * ⚠️⚠️⚠️ CRITICAL, SEPARATE REQUIREMENT — payload_hash must be computed from
 * the exact raw HTTP request body BYTES (e.g. `$request->getContent()`)
 * BEFORE any JSON decoding/re-encoding. Never hash a re-serialized/
 * normalized version of the parsed payload — re-serialization can silently
 * change whitespace or key order, so a byte-for-byte-identical retry from
 * Petpooja would hash DIFFERENTLY than the original delivery, and the
 * UNIQUE(connection_id, payload_hash) constraint this table already has
 * would fail to recognize it as the same event. That defeats the exact-
 * retry idempotency this table exists to provide, silently — the second
 * insert would just succeed as if it were a new event. Documented now, with
 * no ingress controller yet built, so Phase 1B's implementer hashes the
 * right bytes on day one rather than discovering this from a duplicate
 * order after the fact.
 *
 * @property int $id
 * @property int|null $connection_id
 * @property int|null $workspace_id
 * @property string $provider
 * @property string $payload_hash
 * @property string|null $event_type
 * @property Carbon $received_at
 * @property string $processing_status
 * @property array<string, mixed> $raw_payload
 * @property string|null $raw_body
 * @property string|null $failure_reason
 * @property int $attempts
 */
class PosWebhookEvent extends Model
{
    /** @use HasFactory<PosWebhookEventFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /**
     * An authenticated event whose event_type Phase 1B does not process
     * (anything other than 'orderdetails'). Distinct from STATUS_FAILED:
     * nothing was attempted and failed — the event was deliberately set
     * aside, unprocessed, because this phase has no handler for it.
     */
    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSED,
        self::STATUS_FAILED,
        self::STATUS_QUARANTINED,
    ];

    protected $fillable = [
        'connection_id',
        'workspace_id',
        'provider',
        'payload_hash',
        'event_type',
        'received_at',
        'processing_status',
        'raw_payload',
        'raw_body',
        'failure_reason',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    protected static function newFactory(): PosWebhookEventFactory
    {
        return PosWebhookEventFactory::new();
    }

    /** @return BelongsTo<PosConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PosConnection::class, 'connection_id');
    }

    /**
     * A PLAIN Eloquent association, deliberately NOT the BelongsToWorkspace
     * trait — same convenience-relation-without-scope reasoning as
     * PosConnection::workspace(). It applies no automatic scope. Do not
     * confuse "has a workspace() relation" with "is scoped": this table must
     * remain writable for quarantined rows with no resolvable tenant.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
