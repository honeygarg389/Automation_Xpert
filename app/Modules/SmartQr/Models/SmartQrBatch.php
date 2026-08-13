<?php

namespace App\Modules\SmartQr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A print run. PLATFORM-OWNED — a batch never belongs to a tenant, at any point
 * in its life, so it carries no workspace and needs no scope.
 *
 * @property int $id
 * @property string $batch_number
 * @property string $prefix
 */
class SmartQrBatch extends Model
{
    protected $fillable = [
        'uuid', 'batch_number', 'batch_name', 'prefix', 'quantity', 'serial_start',
        'qr_type', 'status', 'generated_count', 'printed_count', 'default_message',
        'notes', 'created_by_admin_id', 'generated_at', 'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'serial_start' => 'integer',
            'generated_count' => 'integer', 'printed_count' => 'integer',
            'generated_at' => 'datetime', 'printed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $b) => $b->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<SmartQrCode, $this> */
    public function codes(): HasMany
    {
        return $this->hasMany(SmartQrCode::class, 'batch_id');
    }
}
