<?php

namespace App\Modules\Restaurant\Models;

use Database\Factories\PosWebhookRejectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A minimal security/audit record for a REJECTED inbound POS webhook
 * request — never the rejected content itself. See the migration's docblock
 * for the hard rule this model must never be extended to violate: no
 * raw_body, no raw_payload, no customer/bill fields, no workspace_id.
 *
 * Deliberately NOT workspace-scoped, and deliberately has NO workspace_id
 * column at all (unlike PosConnection/PosWebhookEvent, which carry the
 * column but skip the trait): a rejected request has no authenticated
 * tenant to attribute it to, and this table is platform-level security
 * telemetry, not customer-owned data. Because it has no workspace_id
 * column, WorkspaceScopeCoverageGuardTest's discovery (which keys off a
 * `workspace_id` column existing) never finds this model at all — no
 * PENDING/NEVER_SCOPED entry is needed or applicable.
 *
 * @property int $id
 * @property string $provider
 * @property int|null $connection_id
 * @property string $payload_hash
 * @property string|null $source_ip
 * @property string $failure_reason
 * @property Carbon $received_at
 */
class PosWebhookRejection extends Model
{
    /** @use HasFactory<PosWebhookRejectionFactory> */
    use HasFactory;

    public const REASON_MALFORMED_PAYLOAD = 'malformed_payload';

    public const REASON_MISSING_RESTID = 'missing_restid';

    public const REASON_UNKNOWN_RESTID = 'unknown_restid';

    public const REASON_INACTIVE_CONNECTION = 'inactive_connection';

    public const REASON_IP_NOT_ALLOWED = 'ip_not_allowed';

    public const REASON_INVALID_TOKEN = 'invalid_token';

    public const REASONS = [
        self::REASON_MALFORMED_PAYLOAD,
        self::REASON_MISSING_RESTID,
        self::REASON_UNKNOWN_RESTID,
        self::REASON_INACTIVE_CONNECTION,
        self::REASON_IP_NOT_ALLOWED,
        self::REASON_INVALID_TOKEN,
    ];

    protected $fillable = [
        'provider',
        'connection_id',
        'payload_hash',
        'source_ip',
        'failure_reason',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PosWebhookRejectionFactory
    {
        return PosWebhookRejectionFactory::new();
    }

    /** @return BelongsTo<PosConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PosConnection::class, 'connection_id');
    }
}
