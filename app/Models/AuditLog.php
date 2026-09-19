<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int|null $actor_admin_id
 * @property int|null $user_id
 * @property int|null $client_id
 * @property int|null $workspace_id
 * @property string $action
 * @property string|null $auditable_type
 * @property int|string|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $meta
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $url
 */
class AuditLog extends Model
{
    protected $fillable = [
        'actor_admin_id',
        'user_id',
        'client_id',
        'workspace_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'meta',
        'ip',
        'user_agent',
        'url',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function actorAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'actor_admin_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
