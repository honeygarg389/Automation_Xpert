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
 * @property string|null $unit
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

    /**
     * Kinds that MUST carry a unit. A counter or a gauge is a number, and a
     * number without a declared unit is only interpretable by guessing at its
     * key name — which is exactly how BUG-025 happened.
     *
     * @var list<string>
     */
    public const KINDS_REQUIRING_UNIT = [self::KIND_COUNTER, self::KIND_GAUGE];

    protected $fillable = ['add_on_id', 'key', 'kind', 'value', 'unit'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (AddOnGrant $grant) {
            $grant->assertUnitMatchesKind();
        });
    }

    /**
     * ⚠️ A counter or gauge must declare its unit; a boolean must not have one.
     *
     * The failure this prevents is already in this codebase: `plans.limits`
     * stores `storage => 5120` meaning MEGABYTES, while `MediaService` reads
     * `storage_gb` meaning gigabytes. Nothing recorded which, the key name was
     * the only clue, and the consumer guessed wrong — so every customer on the
     * system has a 1 GB quota regardless of plan (BUG-025).
     *
     * Enforcing it at write time rather than trusting a convention is the whole
     * point: a convention is what `storage` vs `storage_gb` already was.
     *
     * The boolean half matters too. A `unit` on a feature flag would be
     * meaningless, and a meaningless value in a column the resolver reads is how
     * a later reader talks themselves into a wrong interpretation.
     *
     * @throws \InvalidArgumentException
     */
    public function assertUnitMatchesKind(): void
    {
        $unit = is_string($this->unit) ? trim($this->unit) : null;
        $requiresUnit = in_array($this->kind, self::KINDS_REQUIRING_UNIT, true);

        if ($requiresUnit && ($unit === null || $unit === '')) {
            throw new \InvalidArgumentException(
                "A {$this->kind} grant for '{$this->key}' must declare a unit. A number whose "
                .'unit is recoverable only from its key name is how the storage/storage_gb '
                .'defect happened — every customer got 1 GB regardless of plan.'
            );
        }

        if (! $requiresUnit && $unit !== null && $unit !== '') {
            throw new \InvalidArgumentException(
                "A {$this->kind} grant for '{$this->key}' must not declare a unit: it grants a "
                .'flag, not a quantity, and a unit here would only invite a wrong reading later.'
            );
        }
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
