<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    /**
     * Phase 0, slice 9 — the two orphans.
     *
     * Customer-configured outbound webhooks, previously keyed only to a user.
     *
     * §A.6 recorded this as 'not workspace-owned', which was true of the SCHEMA
     * and not of the data: these are customer-owned rows at /app/webhooks. The
     * column was added in 2026_08_09_100000 so the rule could be enforced rather
     * than described.
     */
    use BelongsToWorkspace;

    use HasFactory;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'url',
        'secret',
        'events',
        'enabled',
        'description',
    ];

    protected $casts = [
        'events' => 'array',
        'enabled' => 'boolean',
    ];

    protected $hidden = ['secret'];

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    public function signature(string $payload): string
    {
        $timestamp = now()->timestamp;
        $body = "{$timestamp}.{$payload}";

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $body, $this->secret);
    }

    public function listensTo(string $event): bool
    {
        if (empty($this->events)) {
            return true; // subscribed to all events
        }

        return in_array($event, $this->events);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
