<?php

namespace App\Modules\Shared\Models;

use App\Support\Concerns\MasksDemoData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ⚠️ @property annotations added in Smart QR slice 5, following the decision
 * recorded on ChannelAccount for BUG-002: annotate properly rather than let
 * `property.notFound` accumulate. `checkModelProperties` is on and larastan
 * cannot infer columns for this model.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $direction
 * @property string $channel
 * @property string $type
 * @property string|null $body
 * @property array<string, mixed>|null $payload
 * @property string|null $provider_message_id
 * @property Carbon|null $sent_at
 */
class Message extends Model
{
    use MasksDemoData;

    protected $fillable = [
        'conversation_id', 'direction', 'channel', 'type', 'payload', 'body',
        'media_id', 'status', 'provider_message_id', 'error_json',
        'sent_by', 'user_id', 'sent_at',
    ];

    /**
     * Scrub emails / phone numbers embedded in message text in demo mode. The
     * structured payload is left intact so interactive messages still render.
     *
     * @return array<string, string>
     */
    protected function demoMask(): array
    {
        return ['body' => 'text'];
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'error_json' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
