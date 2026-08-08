<?php

namespace App\Modules\Entitlements\Models;

use Database\Factories\AddOnGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an add-on confers: a key, its kind, and its value.
 *
 * NOT workspace-scoped — see AddOn.
 *
 * @property int $id
 * @property int $add_on_id
 * @property string $key
 * @property string $kind
 * @property int|null $value
 */
class AddOnGrant extends Model
{
    /** @use HasFactory<AddOnGrantFactory> */
    use HasFactory;

    /**
     * Module models are not found by Laravel's default factory resolver, which
     * would look for Database\Factories\Modules\Entitlements\Models\AddOnGrantFactory.
     * Same reason Campaign declares this.
     */
    protected static function newFactory(): AddOnGrantFactory
    {
        return AddOnGrantFactory::new();
    }

    /** Per-period, measured by usage_meters. Only increases within a period. */
    public const KIND_COUNTER = 'counter';

    /**
     * Cardinality, measured by COUNT(*) at request time. Goes DOWN when a row is
     * deleted — which is why it can never be a meter. See BUG-024.
     */
    public const KIND_GAUGE = 'gauge';

    /** A feature flag. `value` is ignored. */
    public const KIND_BOOLEAN = 'boolean';

    /** @var list<string> */
    public const KINDS = [self::KIND_COUNTER, self::KIND_GAUGE, self::KIND_BOOLEAN];

    protected $fillable = ['add_on_id', 'key', 'kind', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    /** NULL means unlimited, matching plans.limits' existing convention. */
    public function isUnlimited(): bool
    {
        return $this->value === null;
    }

    /** @return BelongsTo<AddOn, $this> */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }
}
