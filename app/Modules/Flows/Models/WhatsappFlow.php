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
 * @property array{button_text?:string,success_message?:string}|null $submit_settings
 * @property string|null $meta_flow_id
 * @property string|null $meta_sync_status
 * @property list<array<string,mixed>>|null $meta_validation_errors
 * @property string|null $meta_sync_error
 * @property array<string,mixed>|null $meta_passthrough
 * @property bool $web_form_enabled
 * @property string|null $public_slug
 * @property bool $recaptcha_enabled
 * @property int|null $max_submissions
 * @property string|null $limit_error_message
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

    /**
     * Terminal on Meta's side (error 139004: "Can't delete published Flow...
     * deprecate instead") — see WhatsappFlowMetaSyncService::removeFromMeta().
     * Not a soft-delete: a deprecated Flow's row and submission history stay,
     * only its Meta-side usability ends.
     */
    public const META_SYNC_STATUS_DEPRECATED = 'deprecated';

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
        'meta_passthrough',
        'web_form_enabled',
        'public_slug',
        'recaptcha_enabled',
        'max_submissions',
        'limit_error_message',
    ];

    protected function casts(): array
    {
        return [
            'screens' => 'array',
            'submit_settings' => 'array',
            'meta_validation_errors' => 'array',
            'meta_passthrough' => 'array',
            'web_form_enabled' => 'boolean',
            'recaptcha_enabled' => 'boolean',
            'max_submissions' => 'integer',
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

    /** Smart QR's opaque public-token format: 128 bits of CSPRNG entropy. */
    public static function generatePublicSlug(): string
    {
        return bin2hex(random_bytes(16));
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

    /**
     * The ledger is shared across both submission sources (WhatsApp Flow and
     * public web form) — see FormSubmission::SOURCES — so this counts across
     * both, deliberately with no `source` filter. Callers must run inside the
     * correct WorkspaceContext: FormSubmission is workspace-scoped and the
     * scope fails closed, so calling this with no context resolved would
     * silently read zero and never trip the limit.
     */
    public function hasReachedSubmissionLimit(): bool
    {
        if ($this->max_submissions === null) {
            return false;
        }

        return $this->submissions()->count() >= $this->max_submissions;
    }
}
