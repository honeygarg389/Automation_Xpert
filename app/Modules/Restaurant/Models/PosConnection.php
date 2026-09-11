<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Workspace;
use Database\Factories\PosConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A tenant's connection to a POS provider.
 *
 * ⚠️ NOT workspace-scoped, on purpose — see WorkspaceScopeCoverageGuardTest's
 * PENDING list. findActiveByProviderAndRef() must resolve a connection for an
 * unauthenticated inbound webhook, before any tenant context exists; a global
 * workspace scope would make that lookup fail closed for every request.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int|null $outlet_id
 * @property string $provider
 * @property string $external_ref
 * @property array<string, mixed>|null $credentials
 * @property string|null $webhook_secret_hash
 * @property Carbon|null $webhook_secret_rotated_at
 * @property array<int, string>|null $allowed_ips
 * @property string $status
 * @property array<string, mixed>|null $meta_json
 * @property Carbon|null $last_tested_at
 * @property string $last_test_status
 * @property string|null $last_test_message
 * @property Carbon|null $last_event_at
 */
class PosConnection extends Model
{
    /** @use HasFactory<PosConnectionFactory> */
    use HasFactory;

    public const PROVIDER_PETPOOJA = 'petpooja';

    public const PROVIDERS = [
        self::PROVIDER_PETPOOJA,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONNECTED,
        self::STATUS_DISCONNECTED,
    ];

    protected $fillable = [
        'workspace_id',
        'outlet_id',
        'provider',
        'external_ref',
        'credentials',
        'allowed_ips',
        'status',
        'meta_json',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'last_event_at',
    ];

    protected $hidden = [
        'credentials',
        'webhook_secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'allowed_ips' => 'array',
            'meta_json' => 'array',
            'webhook_secret_rotated_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PosConnectionFactory
    {
        return PosConnectionFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * A PLAIN Eloquent association, deliberately NOT the BelongsToWorkspace
     * trait. This is convenience only — eager-loading, an admin screen
     * showing "which workspace owns this connection" once a row is already
     * resolved — and it applies NO automatic global scope. Do not confuse
     * "has a workspace() relation" with "is scoped": this model must remain
     * resolvable by an unauthenticated webhook via
     * findActiveByProviderAndRef() before any tenant context exists, which a
     * scoped relation/model would prevent.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<RestaurantOutlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(RestaurantOutlet::class, 'outlet_id');
    }

    /**
     * Resolves a connection for an inbound webhook, before any tenant
     * context exists. Only ever call this from an unauthenticated ingress
     * path with the provider's own identifiers — never with anything else,
     * since there is no workspace scope standing behind it.
     */
    public static function findActiveByProviderAndRef(string $provider, string $externalRef): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->where('external_ref', $externalRef)
            ->where('status', self::STATUS_CONNECTED)
            ->first();
    }

    /**
     * Resolves a connection by (provider, external_ref) REGARDLESS of
     * status — unlike findActiveByProviderAndRef(), which folds "doesn't
     * exist" and "exists but not active" into the same null result.
     *
     * A central inbound webhook needs those two outcomes to stay
     * distinguishable (unknown_restid vs inactive_connection are different
     * rejection-audit reasons, even though both external responses are
     * identical) — so this is the helper the ingress controller calls, one
     * query, still safe with no ambient workspace context.
     */
    public static function findByProviderAndRef(string $provider, string $externalRef): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->where('external_ref', $externalRef)
            ->first();
    }

    /**
     * Constant-time verification of a plaintext webhook token against the
     * connection's stored SHA-256 digest. A null/absent hash never matches,
     * including against an empty-string token.
     */
    public static function verifyToken(self $connection, string $plaintextToken): bool
    {
        if ($connection->webhook_secret_hash === null) {
            return false;
        }

        return hash_equals($connection->webhook_secret_hash, hash('sha256', $plaintextToken));
    }
}
