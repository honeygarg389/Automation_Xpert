<?php

namespace App\Modules\SmartQr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One PART of a batch-scoped export — a single ZIP among N.
 *
 * PLATFORM-OWNED, like the batch it belongs to: an export describes platform
 * inventory, never a tenant's data, so it carries no workspace and needs no
 * scope (the same reasoning as SmartQrBatch).
 *
 * ⚠️ THE INVENTORY BULK EXPORT DOES NOT USE THIS TABLE. That path writes a file
 * and is discovered by directory listing, and is deliberately unchanged — see
 * the migration's docblock for why the two identification strategies coexist
 * rather than one replacing the other.
 *
 * @property int $id
 * @property int $batch_id
 * @property int $part_number
 * @property int $total_parts
 * @property string $format
 * @property string $status
 * @property string|null $path
 * @property string|null $error
 */
class SmartQrExport extends Model
{
    /**
     * ⚠️ queued is the state a row is BORN in, before its job is dispatched.
     *
     * The distinction these four exist to preserve: an absent ZIP means
     * "queued" (not started), "processing" (started, may yet arrive) or
     * "failed" (will never arrive) — three different things an operator acts on
     * differently, which a missing file alone cannot express.
     */
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'batch_id', 'part_number', 'total_parts', 'format', 'status', 'path', 'error',
    ];

    protected function casts(): array
    {
        return [
            'part_number' => 'integer',
            'total_parts' => 'integer',
        ];
    }

    /** @return BelongsTo<SmartQrBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SmartQrBatch::class, 'batch_id');
    }

    /**
     * The parts of one batch's export, in the order an operator reads them.
     *
     * ⚠️ NO `take(20)`, deliberately — unlike QrInventoryController's
     * readyExports(), which caps at 20 because it lists a shared directory with
     * no way to tell whose archive is whose. Scoped to one batch, the full set
     * IS the answer: a 10,000-code batch has exactly 20 parts and all of them
     * must be visible, or the operator cannot tell a complete export from a
     * truncated view of one.
     *
     * @param  Builder<SmartQrExport>  $query
     * @return Builder<SmartQrExport>
     */
    public function scopeForBatch(Builder $query, int $batchId): Builder
    {
        return $query->where('batch_id', $batchId)->orderBy('part_number');
    }
}
