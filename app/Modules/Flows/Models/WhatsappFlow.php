<?php

namespace App\Modules\Flows\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A workspace-owned authoring definition for a static WhatsApp Flow.
 *
 * `screens` is the shared, platform-owned field contract. It is deliberately
 * independent of Meta JSON so both WhatsappFlowJsonCompiler and the later
 * standalone HTML form renderer consume this exact shape:
 *
 * [
 *   {
 *     "id": "contact", "title": "Your details",
 *     "fields": [
 *       {
 *         "id": "first_name", "type": "text", "label": "First name",
 *         "name": "first_name", "required": true, "helper_text": null,
 *         "options": [], "step": 1, "order": 1
 *       }
 *     ]
 *   }
 * ]
 *
 * Steps are ordered by their array position (and fields by `order`); `step` is
 * stored on every field as an explicit portable marker for the future HTML
 * renderer and import/export tools. `heading` is presentational and therefore
 * has no submitted `name`; every other type requires a unique form name.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property string $status
 * @property list<array{id:string,title:string,fields:list<array<string,mixed>>}> $screens
 * @property array{button_text?:string,success_message?:string} $submit_settings
 * @property string|null $meta_flow_id
 * @property string|null $meta_sync_status
 * @property list<array<string,mixed>>|null $meta_validation_errors
 * @property string|null $meta_sync_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $submissions_count
 */
class WhatsappFlow extends Model
{
    use BelongsToWorkspace, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const META_SYNC_STATUS_SYNCING = 'syncing';

    public const META_SYNC_STATUS_SYNCED_DRAFT = 'synced_draft';

    public const META_SYNC_STATUS_PUBLISHED = 'published';

    public const META_SYNC_STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    /** Meta's current Flow create/update category enum. */
    public const CATEGORIES = [
        'SIGN_UP',
        'SIGN_IN',
        'APPOINTMENT_BOOKING',
        'LEAD_GENERATION',
        'CONTACT_US',
        'CUSTOMER_SUPPORT',
        'SURVEY',
        'OTHER',
    ];

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'category',
        'status',
        'screens',
        'submit_settings',
        'meta_flow_id',
        'meta_sync_status',
        'meta_validation_errors',
        'meta_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'screens' => 'array',
            'submit_settings' => 'array',
            'meta_validation_errors' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $flow): void {
            $flow->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}
