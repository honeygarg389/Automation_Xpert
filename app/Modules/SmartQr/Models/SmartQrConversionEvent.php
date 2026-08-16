<?php

namespace App\Modules\SmartQr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One typed fact about an attributed arrival. §9, §10.
 *
 * ⚠️ Typed rows rather than one wide row of flags: a single inbound message can
 * be "customer messaged" AND "new contact" AND "conversation started" at once,
 * and §10's metric list will grow. Flags would need a migration each time and
 * cannot express three simultaneous truths about one event.
 *
 * ⚠️ NO workspace_id — same reasoning as the session (R-4).
 *
 * @property int $id
 * @property int $smart_qr_assignment_id
 * @property int $attribution_session_id
 * @property string $type
 * @property int|null $contact_id
 * @property int|null $conversation_id
 * @property int|null $message_id
 * @property Carbon $occurred_at
 */
class SmartQrConversionEvent extends Model
{
    /** A customer sent their first message carrying this session's token. */
    public const TYPE_CUSTOMER_MESSAGED = 'customer_messaged';

    /** The contact did not exist before the session was issued. */
    public const TYPE_NEW_CONTACT = 'new_contact';

    /** The conversation did not exist before the session was issued. */
    public const TYPE_CONVERSATION_STARTED = 'conversation_started';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_CUSTOMER_MESSAGED,
        self::TYPE_NEW_CONTACT,
        self::TYPE_CONVERSATION_STARTED,
    ];

    protected $fillable = [
        'smart_qr_assignment_id', 'attribution_session_id', 'type',
        'contact_id', 'conversation_id', 'message_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<SmartQrAttributionSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SmartQrAttributionSession::class, 'attribution_session_id');
    }
}
