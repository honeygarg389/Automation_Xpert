<?php

namespace App\Modules\SmartQr\Models;

use Database\Factories\SmartQrBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A print run. PLATFORM-OWNED — a batch never belongs to a tenant, at any point
 * in its life, so it carries no workspace and needs no scope.
 *
 * @property int $id
 * @property string $batch_number
 * @property string|null $batch_name
 * @property string $prefix
 * @property string|null $uuid
 * @property int $quantity
 * @property int $serial_start
 * @property string $status
 * @property int $generated_count
 * @property int $printed_count
 * @property Carbon|null $generated_at
 * @property Carbon|null $printed_at
 * @property Carbon|null $failed_at
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
     * The largest number of codes one batch may ever hold. §4.
     *
     * ⚠️ ONE SOURCE, because two places now enforce it. It began as an inline
     * `max:10000` in StoreQrBatchRequest, which was fine while creation was the
     * only way to add codes. Extending a batch has to check the SAME ceiling
     * against existing + additional, and a second literal is how the two drift
     * until one path admits a batch the other would refuse.
     */
    public const MAX_QUANTITY = 10_000;

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

        // ═══ ⚠️ THE BATCH LOGO IS NOT PART OF THE DATABASE — DELETING THE ROW
        //     LEAVES IT ON DISK UNLESS SOMETHING ELSE REMOVES IT ═════════════
        //
        // Registered here, not in QrBatchController::destroy(), on purpose: a
        // controller-level fix only protects the one call site someone
        // remembered to edit — and that is exactly how this file ended up
        // orphaned in the first place, since storeBatchLogo() was added to the
        // create path without anyone touching destroy(). A model hook runs on
        // every deletion path — this controller, Model::destroy(), tinker, a
        // future bulk-admin tool — because the guarantee belongs to "a batch
        // with a logo was deleted", not to any one place that can delete one.
        //
        // ⚠️ DELIBERATELY NOT App\Models\Media::delete()'s SHAPE. That override
        // calls Storage::delete() with no try/catch, so a genuine I/O failure
        // there would stop parent::delete() from ever running — the file
        // problem would block the row deletion too. That is acceptable for a
        // record that IS the file. It is not acceptable here: a batch row and
        // its serial range are the far more consequential object, and losing
        // track of one branding image is a recoverable annoyance, not a reason
        // to refuse an admin's delete.
        //
        // ⚠️ Measured, not assumed: Storage::disk('local')->delete() on a file
        // that is ALREADY GONE returns true and throws nothing — Flysystem's
        // local adapter treats a missing target as success. So the try/catch
        // below exists for the rarer case (a bad logo_disk value, a permissions
        // fault, a network-disk outage), not for the everyday "already cleaned
        // up" case, which needs no special handling at all.
        static::deleting(function (self $batch) {
            if (! is_string($batch->logo_path) || $batch->logo_path === '') {
                return;
            }

            try {
                Storage::disk($batch->logo_disk ?: 'local')->delete($batch->logo_path);
            } catch (\Throwable $e) {
                // Logged, never rethrown — see the class-level note above.
                Log::error('smart_qr.batch_logo_cleanup_failed', [
                    'batch_id' => $batch->id,
                    'logo_path' => $batch->logo_path,
                    'logo_disk' => $batch->logo_disk,
                    'error' => $e->getMessage(),
                ]);
            }
        });
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
