<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Durable, one-per-bill feedback ledger. Public tokens are never mass assignable.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $restaurant_bill_id
 * @property int|null $outlet_id
 * @property int|null $restaurant_feedback_delivery_config_id
 * @property string $purpose
 * @property string $public_token
 * @property Carbon $scheduled_for
 * @property string $status
 * @property int $attempt_count
 * @property Carbon|null $provider_attempt_started_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $suppressed_at
 * @property Carbon|null $failed_at
 * @property string|null $provider_message_id
 * @property string|null $reason_code
 * @property int|null $rating
 * @property string|null $customer_comment
 * @property Carbon|null $submitted_at
 * @property Carbon|null $manager_alerted_at
 * @property Carbon|null $revoked_at
 */
final class RestaurantFeedbackRequest extends Model
{
    use BelongsToWorkspace;

    public const PURPOSE_FEEDBACK_REQUEST = 'feedback_request';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_OUTCOME_UNKNOWN = 'outcome_unknown';

    protected $fillable = [
        'workspace_id', 'restaurant_bill_id', 'outlet_id', 'restaurant_feedback_delivery_config_id',
        'purpose', 'scheduled_for', 'status', 'attempt_count', 'provider_attempt_started_at',
        'sent_at', 'suppressed_at', 'failed_at', 'provider_message_id', 'reason_code',
        'rating', 'customer_comment', 'submitted_at', 'manager_alerted_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime', 'provider_attempt_started_at' => 'datetime', 'sent_at' => 'datetime',
            'suppressed_at' => 'datetime', 'failed_at' => 'datetime', 'submitted_at' => 'datetime',
            'manager_alerted_at' => 'datetime', 'revoked_at' => 'datetime',
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
        return $this->belongsTo(RestaurantOutlet::class, 'outlet_id');
    }

    /** @return BelongsTo<RestaurantFeedbackDeliveryConfig, $this> */
    public function config(): BelongsTo
    {
        return $this->belongsTo(RestaurantFeedbackDeliveryConfig::class, 'restaurant_feedback_delivery_config_id');
    }
}
