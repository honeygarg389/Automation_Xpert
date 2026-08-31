<?php

namespace App\Modules\SmartQr\Models;

use App\Models\AdminUser;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use Database\Factories\SmartQrAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Where tenancy lives — and where it ends.
 *
 * ⚠️ THIS model IS workspace-scoped, and that is not a contradiction of
 * SmartQrCode's exemption. The code is lifecycle-owned; the ASSIGNMENT is
 * unambiguously tenant data for its whole life. A row here belongs to the
 * workspace named in its column from the moment it is written, which is exactly
 * the question the coverage guard's standard asks.
 *
 * The trait also gives the reassignment rule for free: a previous tenant's
 * assignment carries THEIR workspace_id, so the new tenant's scoped queries
 * cannot see it, and neither can any child row keyed by it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $smart_qr_code_id
 * @property string|null $uuid
 * @property Carbon|null $assigned_at
 * @property Carbon|null $unassigned_at
 * @property string $status
 * @property int|null $channel_account_id
 * @property bool $admin_locked
 * @property string|null $lock_reason
 * @property int|null $locked_by_admin_id
 * @property Carbon|null $locked_at
 * @property string|null $default_message
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property string|null $name
 * @property string|null $qr_type
 * @property int|null $assigned_user_id
 */
class SmartQrAssignment extends Model
{
    /** @use HasFactory<SmartQrAssignmentFactory> */
    use HasFactory;

    /**
     * ⚠️ Module models live outside app/Models, so Laravel's convention
     * resolves Database\\Factories\\Modules\\SmartQr\\Models\\…Factory and finds
     * nothing. Named explicitly, as the AI and Social module models do.
     */
    protected static function newFactory(): SmartQrAssignmentFactory
    {
        return SmartQrAssignmentFactory::new();
    }

    use BelongsToWorkspace;

    protected $fillable = [
        'uuid', 'smart_qr_code_id', 'workspace_id', 'channel_account_id', 'assigned_user_id',
        'name', 'qr_type', 'default_message', 'status', 'assigned_at', 'unassigned_at',
        'starts_at', 'expires_at', 'assigned_by_admin_id', 'config_snapshot',

        // ⚠️ admin_locked, lock_reason, locked_by_admin_id and locked_at are
        // DELIBERATELY ABSENT, and their absence is load-bearing.
        //
        // SmartQrCodeController::update() — the CUSTOMER path — calls
        // $assignment->update($validated) with tenant input. Any lock column
        // listed here would let a locked tenant post admin_locked=false and
        // unlock themselves, defeating the whole feature with no error.
        //
        // The lock is written with forceFill() from QrAssignmentController and
        // nowhere else. SmartQrAssignmentLockTest proves the exclusion by
        // POSTing the field through the customer route rather than trusting
        // that it is missing from this list.
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime', 'unassigned_at' => 'datetime',
            'starts_at' => 'datetime', 'expires_at' => 'datetime',
            'config_snapshot' => 'array',
            'admin_locked' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->uuid ??= (string) Str::uuid();
            $a->assigned_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isCurrent(): bool
    {
        return $this->unassigned_at === null;
    }

    /**
     * ⚠️ Declared HERE, not on the trait.
     *
     * `BelongsToWorkspace` deliberately carries no `workspace` relation — it had
     * two conflicting definitions once and they were removed, leaving the trait
     * doing exactly one thing: applying the scope. Its docblock says a scoped
     * model that needs the relation declares its own, in the place that uses it.
     * The admin inventory screen is that place.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The team member this QR is assigned to (§6 step 6), or null.
     *
     * ⚠️ OPTIONAL, and usually null. Nothing in the product requires it, which
     * is why §12's user-wise performance report is empty on most installations —
     * the UI names that cause rather than showing a blank table.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * The WhatsApp number a scan of this QR lands on (§6 step 4).
     *
     * ⚠️ UNSCOPED, AND THAT IS THE WHOLE REASON THIS RELATION NEEDS A COMMENT.
     *
     * ChannelAccount uses BelongsToWorkspace, so the global scope filters it to
     * the CURRENT workspace. The admin inventory detail screen reads this for an
     * arbitrary code belonging to an arbitrary tenant, and the failure mode is
     * not an error — the scope simply matches nothing and the relation resolves
     * to NULL. "Destination Phone: —" on a code that has one reads as missing
     * data, not as a bug, so nothing would ever report it.
     *
     * Mirrors SmartQrCode::currentAssignment(), which removes the same scope for
     * the same reason and on the same screens.
     *
     * ⚠️ Displayable fields are `display_name` and `phone_number_id` — there is
     * no plain phone column on channel_accounts.
     *
     * @return BelongsTo<ChannelAccount, $this>
     */
    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id')
            ->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * Is the tenant barred from changing this assignment's active status?
     *
     * ⚠️ ASKS ONE QUESTION AND NOT THE OTHER. This does not say whether the QR is
     * serving — `status` says that, and the two are independent: a locked
     * assignment may be active or inactive, and the public redirect reads only
     * `status`. Callers wanting "is it live" must not reach for this.
     */
    public function isLocked(): bool
    {
        return (bool) $this->admin_locked;
    }

    /**
     * The admin who applied the current lock, if the account still exists.
     *
     * @return BelongsTo<AdminUser, $this>
     */
    public function lockedByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'locked_by_admin_id');
    }

    /** @return BelongsTo<SmartQrCode, $this> */
    public function code(): BelongsTo
    {
        return $this->belongsTo(SmartQrCode::class, 'smart_qr_code_id');
    }

    /** @return HasMany<SmartQrScanEvent, $this> */
    public function scanEvents(): HasMany
    {
        return $this->hasMany(SmartQrScanEvent::class);
    }
}
