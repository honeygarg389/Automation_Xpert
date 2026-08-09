<?php

namespace App\Modules\Entitlements\Models;

use App\Models\Plan;
use Database\Factories\AddOnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A sellable thing: a software package, an additive pack, or a feature flag.
 *
 * ─── NOT workspace-scoped ───────────────────────────────────────────────────
 *
 * Platform-owned catalog, in the same layer §A.5 of the Phase 0 plan classifies
 * as platform-global — `Plan`, `Coupon`, `PaymentGatewayConfig`, `BillingEvent`.
 * The workspace scope stops well below this. Asserted in AddOnCatalogSchemaTest,
 * because the Phase 0 coverage guard only inventories tables that HAVE a
 * `workspace_id` column and therefore cannot see this one.
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property int $rank
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AddOn extends Model
{
    /** @use HasFactory<AddOnFactory> */
    use HasFactory;

    /**
     * Module models are not found by Laravel's default factory resolver, which
     * would look for Database\Factories\Modules\Entitlements\Models\AddOnFactory.
     * Same reason Campaign declares this.
     */
    protected static function newFactory(): AddOnFactory
    {
        return AddOnFactory::new();
    }

    /**
     * DOMINANT. Full software packages are never summed — the highest eligible
     * `rank` wins outright. CLAUDE.md rule 5.
     */
    public const TYPE_PACKAGE = 'package';

    /** ADDITIVE. Explicit packs and credits: value * quantity, summed. */
    public const TYPE_PACK = 'pack';

    /** BOOLEAN. OR across everything held; `value` is ignored. */
    public const TYPE_FEATURE = 'feature';

    /** @var list<string> */
    public const TYPES = [self::TYPE_PACKAGE, self::TYPE_PACK, self::TYPE_FEATURE];

    protected $fillable = ['uuid', 'slug', 'name', 'description', 'type', 'rank', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['rank' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (AddOn $addOn) {
            $addOn->uuid ??= (string) Str::uuid();
            $addOn->slug ??= Str::slug($addOn->name);
        });
    }

    /** A sequential catalog id in a URL enumerates the product line. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<AddOnGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(AddOnGrant::class);
    }

    /** @return HasMany<AddOnPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(AddOnPrice::class);
    }

    /** @return HasMany<EntitlementGrant, $this> */
    public function entitlementGrants(): HasMany
    {
        return $this->hasMany(EntitlementGrant::class);
    }

    /** @return BelongsToMany<Plan, $this> */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_add_on')
            ->withTimestamps();
    }

    public function isDominant(): bool
    {
        return $this->type === self::TYPE_PACKAGE;
    }

    public function isAdditive(): bool
    {
        return $this->type === self::TYPE_PACK;
    }
}
