<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Durable idempotency ledger for one Digital Bill delivery per bill.
 * It intentionally has no customer address, URL/token, template content or
 * provider response payload fields.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $restaurant_bill_id
 * @property int|null $outlet_id
 * @property int|null $digital_bill_delivery_config_id
 * @property string $purpose
 * @property string $status
 * @property int $attempt_count
 * @property Carbon|null $provider_attempt_started_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $suppressed_at
 * @property Carbon|null $failed_at
 * @property string|null $provider_message_id
 * @property string|null $reason_code
 */
class RestaurantDigitalBillDelivery extends Model
{
    use BelongsToWorkspace;

    public const PURPOSE_DIGITAL_BILL = 'digital_bill';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_OUTCOME_UNKNOWN = 'outcome_unknown';

    protected $fillable = [
        'workspace_id', 'restaurant_bill_id', 'outlet_id', 'digital_bill_delivery_config_id',
        'purpose', 'status', 'attempt_count', 'provider_attempt_started_at', 'sent_at',
        'suppressed_at', 'failed_at', 'provider_message_id', 'reason_code',
    ];

    protected function casts(): array
    {
        return [
            'provider_attempt_started_at' => 'datetime', 'sent_at' => 'datetime',
            'suppressed_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<RestaurantBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(RestaurantBill::class, 'restaurant_bill_id');
    }

    /** @return BelongsTo<RestaurantOutlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(RestaurantOutlet::class);
    }

    /** @return BelongsTo<RestaurantDigitalBillDeliveryConfig, $this> */
    public function config(): BelongsTo
    {
        return $this->belongsTo(RestaurantDigitalBillDeliveryConfig::class, 'digital_bill_delivery_config_id');
    }
}
