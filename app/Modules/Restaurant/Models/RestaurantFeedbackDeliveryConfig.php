<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Configuration only. This record never schedules or sends feedback.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $outlet_id
 * @property int|null $whatsapp_phone_number_id
 * @property int|null $whatsapp_template_id
 * @property string $timing_preference
 * @property Carbon|null $next_day_at
 * @property string|null $google_review_url
 */
class RestaurantFeedbackDeliveryConfig extends Model
{
    use BelongsToWorkspace;

    public const TIMING_IMMEDIATELY = 'immediately';

    public const TIMING_ONE_HOUR = 'one_hour';

    public const TIMING_FIVE_HOURS = 'five_hours';

    public const TIMING_NEXT_DAY = 'next_day';

    public const TIMING_SEVEN_DAYS = 'seven_days';

    public const TIMING_PREFERENCES = [self::TIMING_IMMEDIATELY, self::TIMING_ONE_HOUR, self::TIMING_FIVE_HOURS, self::TIMING_NEXT_DAY, self::TIMING_SEVEN_DAYS];

    protected $fillable = ['workspace_id', 'outlet_id', 'whatsapp_phone_number_id', 'whatsapp_template_id', 'timing_preference', 'next_day_at', 'google_review_url'];

    protected function casts(): array
    {
        return ['next_day_at' => 'datetime:H:i'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<RestaurantOutlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(RestaurantOutlet::class, 'outlet_id');
    }

    /** @return BelongsTo<WhatsappPhoneNumber, $this> */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(WhatsappPhoneNumber::class, 'whatsapp_phone_number_id');
    }

    /** @return BelongsTo<WhatsappTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'whatsapp_template_id');
    }
}
