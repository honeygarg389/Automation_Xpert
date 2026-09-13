<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Workspace;
use Database\Factories\PosConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property int|null $active_slot
 * @property string $environment
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

    /**
     * The ONE canonical persisted "ingress accepted" value. Do NOT introduce
     * a `STATUS_ACTIVE` constant — Phase 1B's PetpoojaWebhookController
     * checks this exact constant (`$connection->status !== self::STATUS_CONNECTED`)
     * and always has; "Activate"/"Resume" in the admin UI are labels over
     * this same value, not a second status meaning the same thing.
     */
    public const STATUS_CONNECTED = 'connected';

    /**
     * Superseded by STATUS_PAUSED/STATUS_ARCHIVED for the Phase 1C lifecycle
     * model, but kept — not removed — because it is still a legitimate
     * schema value and at least one existing test constructs a row with it.
     * No new code should write it going forward.
     */
    public const STATUS_DISCONNECTED = 'disconnected';

    /**
     * Deliberately rejected by Phase 1B's status check (which only accepts
     * STATUS_CONNECTED) — a paused connection stops accepting webhooks
     * immediately, with no change needed to the ingress controller itself.
     */
    public const STATUS_PAUSED = 'paused';

    /**
     * Terminal for ingress purposes (also rejected by the same check) but
     * NOT a deletion: rows, webhook history and audit trail are retained.
     * `active_slot` is NULL for an archived connection — see the class
     * docblock and the migration that added it — which is what actually
     * frees the outlet up for a new connection; archiving is the only status
     * transition that does.
     */
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONNECTED,
        self::STATUS_DISCONNECTED,
        self::STATUS_PAUSED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * "Non-archived" is the business rule's actual boundary — see the class
     * docblock: an outlet may have only one connection whose status is in
     * this set at a time, enforced at the DB layer via `active_slot`. Does
     * NOT include STATUS_DISCONNECTED, which predates this lifecycle model
     * and no current code path produces.
     */
    public const NON_ARCHIVED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONNECTED,
        self::STATUS_PAUSED,
    ];

    public const ENVIRONMENT_SANDBOX = 'sandbox';

    public const ENVIRONMENT_PRODUCTION = 'production';

    /**
     * Phase 1C only ever writes ENVIRONMENT_SANDBOX. ENVIRONMENT_PRODUCTION
     * exists as a named constant so the column's contract is documented and
     * so nothing has to invent a magic string when the later compliance/
     * activation-gate phase adds the production path — but no code in this
     * phase creates, activates, or offers a production connection.
     */
    public const ENVIRONMENTS = [
        self::ENVIRONMENT_SANDBOX,
        self::ENVIRONMENT_PRODUCTION,
    ];

    protected $fillable = [
        'workspace_id',
        'outlet_id',
        'provider',
        'external_ref',
        'credentials',
        'allowed_ips',
        'status',
        'environment',
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

        // `active_slot` is FULLY DERIVED from `status` — recomputed
        // unconditionally on every save, the same "never independently
        // settable" pattern as legal_document_versions.content_sha256
        // (Phase 1B). A caller cannot set it correctly or incorrectly by
        // hand; it simply is not a fillable attribute at all.
        static::saving(function (self $connection) {
            $connection->active_slot = $connection->status === self::STATUS_ARCHIVED ? null : 1;
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

    /** @return HasMany<PosWebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PosWebhookEvent::class, 'connection_id');
    }

    /** @return HasMany<PosWebhookRejection, $this> */
    public function webhookRejections(): HasMany
    {
        return $this->hasMany(PosWebhookRejection::class, 'connection_id');
    }

    /**
     * Whether ANY webhook activity — accepted or rejected — has ever been
     * recorded against this connection. This is the gate for both "Delete
     * test connection" (only ever offered with zero history) and the
     * guarded workspace-move flow (blocked once history exists) — a
     * connection that has actually talked to Petpooja is no longer
     * "test data" that can be silently discarded or relocated.
     */
    public function hasWebhookHistory(): bool
    {
        return $this->webhookEvents()->exists() || $this->webhookRejections()->exists();
    }
}
