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
 * @property int $id
 * @property int $workspace_id
 * @property int $outlet_id
 * @property int|null $whatsapp_phone_number_id
 * @property int|null $whatsapp_template_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RestaurantDigitalBillDeliveryConfig extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'outlet_id',
        'whatsapp_phone_number_id',
        'whatsapp_template_id',
    ];

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
