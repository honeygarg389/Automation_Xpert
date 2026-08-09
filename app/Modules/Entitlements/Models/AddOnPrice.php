<?php

namespace App\Modules\Entitlements\Models;

use Database\Factories\AddOnPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an add-on costs, per currency and interval.
 *
 * Mirrors the columns `plans` already carries rather than inventing a second
 * pricing vocabulary. NOT workspace-scoped — see AddOn.
 *
 * @property int $id
 * @property int $add_on_id
 * @property string $currency_code
 * @property string $interval
 * @property int $price_cents
 * @property string|null $stripe_price_id
 * @property string|null $paddle_price_id
 * @property bool $is_active
 */
class AddOnPrice extends Model
{
    /** @use HasFactory<AddOnPriceFactory> */
    use HasFactory;

    /**
     * Module models are not found by Laravel's default factory resolver, which
     * would look for Database\Factories\Modules\Entitlements\Models\AddOnPriceFactory.
     * Same reason Campaign declares this.
     */
    protected static function newFactory(): AddOnPriceFactory
    {
        return AddOnPriceFactory::new();
    }

    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    /** For packs and credits, which are bought once rather than subscribed to. */
    public const INTERVAL_ONE_TIME = 'one_time';

    /** @var list<string> */
    public const INTERVALS = [self::INTERVAL_MONTH, self::INTERVAL_YEAR, self::INTERVAL_ONE_TIME];

    protected $fillable = [
        'add_on_id', 'currency_code', 'interval', 'price_cents',
        'stripe_price_id', 'paddle_price_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<AddOn, $this> */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }
}
