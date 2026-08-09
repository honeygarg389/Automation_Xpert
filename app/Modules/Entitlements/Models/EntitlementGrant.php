<?php

namespace App\Modules\Entitlements\Models;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Partner;
use Database\Factories\EntitlementGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A held entitlement: this client, or this partner, holds this add-on.
 *
 * ─── ⚠️ EXACTLY ONE OWNER ───────────────────────────────────────────────────
 *
 * `client_id` and `partner_id` are both nullable, and precisely one must be set.
 * Two explicit foreign keys rather than a polymorphic `owner_type`/`owner_id`:
 * a morph cannot carry a foreign key, and BUG-021 records that 121 of 154
 * FK-shaped columns in this schema already lack one. The right resolution there
 * is to raise the other 121, not to lower these.
 *
 * The cost of that choice is that "exactly one" is not expressible in the schema
 * builder, so it is enforced here on save and asserted in BOTH directions by
 * test — neither set, and both set. A rule enforced only on the way in is a rule
 * that a seeder, a factory or a `DB::table()` insert walks straight past, so the
 * test also checks what the model does when it READS such a row.
 *
 * ─── Why the owner is not a workspace ───────────────────────────────────────
 *
 * Entitlements are bought by an organisation and consumed by its workspaces. A
 * grant pinned to one workspace could not express "this client's 50,000 extra
 * messages", which is the normal case. NOT workspace-scoped — §A.3 classifies
 * client-owned billing records (`ClientSubscription`) the same way.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $client_id
 * @property int|null $partner_id
 * @property int $add_on_id
 * @property int $quantity
 * @property string $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string $source
 * @property int|null $assigned_by_admin_id
 * @property string|null $reason
 */
class EntitlementGrant extends Model
{
    /** @use HasFactory<EntitlementGrantFactory> */
    use HasFactory;

    /**
     * Module models are not found by Laravel's default factory resolver, which
     * would look for Database\Factories\Modules\Entitlements\Models\EntitlementGrantFactory.
     * Same reason Campaign declares this.
     */
    protected static function newFactory(): EntitlementGrantFactory
    {
        return EntitlementGrantFactory::new();
    }

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** Conferred by the customer's plan. */
    public const SOURCE_PLAN = 'plan';

    /** Bought. */
    public const SOURCE_PURCHASE = 'purchase';

    /** Granted by hand — needs permission + reason + dates + audit. Rule 9. */
    public const SOURCE_MANUAL = 'manual';

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_PLAN, self::SOURCE_PURCHASE, self::SOURCE_MANUAL];

    protected $fillable = [
        'uuid', 'client_id', 'partner_id', 'add_on_id', 'quantity',
        'status', 'starts_at', 'ends_at', 'source', 'assigned_by_admin_id', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EntitlementGrant $grant) {
            $grant->uuid ??= (string) Str::uuid();
        });

        static::saving(function (EntitlementGrant $grant) {
            $grant->assertExactlyOneOwner();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function assertExactlyOneOwner(): void
    {
        $hasClient = $this->client_id !== null;
        $hasPartner = $this->partner_id !== null;

        if ($hasClient === $hasPartner) {
            throw new \InvalidArgumentException(
                $hasClient
                    ? 'An entitlement grant cannot belong to both a client and a partner. '
                      .'A partner ceiling and a customer holding are different things: one '
                      .'limits, the other grants.'
                    : 'An entitlement grant must belong to either a client or a partner. '
                      .'An ownerless grant would be counted by neither resolver and would '
                      .'silently confer nothing.'
            );
        }
    }

    /** 'client' | 'partner'. Null only for a row written around the model. */
    public function ownerType(): ?string
    {
        return match (true) {
            $this->client_id !== null && $this->partner_id === null => 'client',
            $this->partner_id !== null && $this->client_id === null => 'partner',
            default => null,
        };
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<AddOn, $this> */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function assignedByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_by_admin_id');
    }

    /**
     * Active AND within its dates.
     *
     * `status` alone is not enough: a missed expiry job leaves a stale `active`
     * row granting entitlement forever, which is the same shape as the guard
     * already present on `User::activeSubscription()`.
     */
    public function isInForce(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_ACTIVE
            && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo($at))
            && ($this->ends_at === null || $this->ends_at->greaterThan($at));
    }
}
