<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\RestaurantOutletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $name
 * @property string|null $address
 * @property string|null $timezone
 * @property string $status
 */
class RestaurantOutlet extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<RestaurantOutletFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'workspace_id',
        'name',
        'address',
        'timezone',
        'status',
    ];

    protected static function newFactory(): RestaurantOutletFactory
    {
        return RestaurantOutletFactory::new();
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
     * Deliberately hasMany, not hasOne: an outlet can retain prior/inactive
     * POS connection history (a restID rotation, a reconnect after
     * disconnection) rather than being restricted to exactly one connection
     * at a time. No "which connection is active" resolver here by design —
     * that is later service-layer work once Phase 1B's status semantics are
     * exercised in practice.
     *
     * @return HasMany<PosConnection, $this>
     */
    public function posConnections(): HasMany
    {
        return $this->hasMany(PosConnection::class, 'outlet_id');
    }
}
