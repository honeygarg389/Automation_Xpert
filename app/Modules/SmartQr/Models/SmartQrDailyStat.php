<?php

namespace App\Modules\SmartQr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One assignment's figures for one day. §10's aggregates.
 *
 * ⚠️ NO workspace_id and NO scope — the grain is the assignment, whose tenant is
 * fixed for the life of the row (R-4). Reads go through the assignment ids a
 * workspace holds, which R-13 bounds at 50.
 *
 * ⚠️ The `attributed_*` naming is R-19 and is not cosmetic: these figures are a
 * FLOOR, because a customer can delete the reference before sending.
 *
 * @property int $smart_qr_assignment_id
 * @property Carbon $stat_date
 * @property int $scans
 * @property int $unique_scans
 * @property int $bot_scans
 * @property int $attributed_messages
 * @property int $attributed_unique_contacts
 * @property int $attributed_new_contacts
 * @property int $attributed_conversations_started
 */
class SmartQrDailyStat extends Model
{
    protected $fillable = [
        'smart_qr_assignment_id', 'stat_date',
        'scans', 'unique_scans', 'bot_scans',
        'attributed_messages', 'attributed_unique_contacts',
        'attributed_new_contacts', 'attributed_conversations_started',
    ];

    protected function casts(): array
    {
        return ['stat_date' => 'date'];
    }

    /** @return BelongsTo<SmartQrAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SmartQrAssignment::class, 'smart_qr_assignment_id');
    }
}
