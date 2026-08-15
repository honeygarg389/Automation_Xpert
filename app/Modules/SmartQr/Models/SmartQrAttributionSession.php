<?php

namespace App\Modules\SmartQr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reference token issued at scan time. §9.
 *
 * ⚠️ NO workspace_id AND NO SCOPE — R-4, and for a sharper reason than the
 * other tables. A session lives 30 minutes; the assignment underneath it can be
 * ended and the code reassigned inside that window. A denormalised tenant would
 * keep asserting the OLD workspace for exactly the period where it matters.
 *
 * The tenant is reached through `assignment`. Customer-facing reads go through
 * that relation, and the inbound listener compares it against the CONVERSATION's
 * workspace before associating anything.
 *
 * @property int $id
 * @property string $token
 * @property int $smart_qr_assignment_id
 * @property int|null $smart_qr_scan_event_id
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property int|null $contact_id
 * @property int|null $conversation_id
 * @property int|null $message_id
 */
class SmartQrAttributionSession extends Model
{
    protected $fillable = [
        'token', 'smart_qr_assignment_id', 'smart_qr_scan_event_id',
        'issued_at', 'expires_at', 'consumed_at',
        'contact_id', 'conversation_id', 'message_id',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SmartQrAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SmartQrAssignment::class, 'smart_qr_assignment_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
