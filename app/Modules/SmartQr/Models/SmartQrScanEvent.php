<?php

namespace App\Modules\SmartQr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scan, keyed by ASSIGNMENT rather than workspace.
 *
 * ⚠️ No `workspace_id`, and no scope. Denormalising a tenant onto a row whose
 * tenant CHANGES produces two sources of truth that disagree the moment a QR is
 * reassigned: the old scans would keep asserting the old workspace while the
 * code belongs to a new one, and someone would eventually "fix" one of them.
 *
 * Keying by assignment makes the period intrinsic. Old scans point at the old
 * assignment, which carries the old workspace_id and is invisible to the new
 * tenant's scoped queries — so "a reassignment hides the previous tenant's
 * analytics" needs no date comparison and no data migration.
 *
 * Slice 1 carries only what the canary needs; slice 4 adds ip_hash, ua_hash,
 * bot detection, device and referer.
 *
 * @property int $smart_qr_assignment_id
 */
class SmartQrScanEvent extends Model
{
    /**
     * ⚠️ EVERY scan column must be listed here.
     *
     * Slice 4 added ip_hash, ua_hash, is_bot, referer_host and is_unique, and
     * omitting them from this array made `create()` DISCARD them silently — no
     * error, no exception, just rows with the database defaults. Bot flags read
     * false, referers read null and every repeat scan counted as unique.
     *
     * Caught only because the tests assert the STORED ROW rather than the job
     * payload. A test that checked what was dispatched would have passed.
     */
    protected $fillable = [
        'smart_qr_assignment_id', 'scanned_at',
        'ip_hash', 'ua_hash', 'is_bot', 'referer_host', 'is_unique',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'is_bot' => 'boolean',
            'is_unique' => 'boolean',
        ];
    }

    /** @return BelongsTo<SmartQrAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SmartQrAssignment::class, 'smart_qr_assignment_id');
    }
}
