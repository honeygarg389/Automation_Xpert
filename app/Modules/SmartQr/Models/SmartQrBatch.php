<?php

namespace App\Modules\SmartQr\Models;

use Database\Factories\SmartQrBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * @property string|null $uuid
 * @property int $quantity
 * @property int $serial_start
 * @property string $status
 * @property string|null $failure_reason
 * @property string|null $default_message
 * @property string|null $logo_path
 * @property string|null $logo_disk
 */
class SmartQrBatch extends Model
{
    /** @use HasFactory<SmartQrBatchFactory> */
    use HasFactory;

    /**
     * ⚠️ Module models live outside app/Models, so Laravel's convention
     * resolves Database\\Factories\\Modules\\SmartQr\\Models\\…Factory and finds
     * nothing. Named explicitly, as the AI and Social module models do.
     */
    protected static function newFactory(): SmartQrBatchFactory
    {
        return SmartQrBatchFactory::new();
    }

    protected $fillable = [
        'uuid', 'batch_number', 'batch_name', 'prefix', 'quantity', 'serial_start',
        'qr_type', 'status', 'generated_count', 'printed_count', 'default_message',
        'notes', 'created_by_admin_id', 'generated_at', 'printed_at',
        'failure_reason', 'failed_at',

        // ⚠️ Set ONLY by QrBatchController from a stored upload, never from
        // request input: StoreQrBatchRequest validates a FILE under the key
        // `logo` and defines no rule for either of these, so validated() can
        // never carry them into create(). Fillable so the controller can pass
        // the resolved pair in one array — not because they are user input.
        'logo_path', 'logo_disk',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'serial_start' => 'integer',
            'generated_count' => 'integer', 'printed_count' => 'integer',
            'generated_at' => 'datetime', 'printed_at' => 'datetime', 'failed_at' => 'datetime',
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
