<?php

namespace App\Modules\Flows\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Shared\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A workspace-owned response ledger shared by WhatsApp Flows and future web forms.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $whatsapp_flow_id
 * @property int|null $contact_id
 * @property int|null $automation_run_id
 * @property array<string, mixed> $answers
 * @property Carbon|null $created_at
 */
class FormSubmission extends Model
{
    use BelongsToWorkspace;

    public const SOURCE_WHATSAPP_FLOW = 'whatsapp_flow';

    public const SOURCE_WEB_FORM = 'web_form';

    public const SOURCES = [self::SOURCE_WHATSAPP_FLOW, self::SOURCE_WEB_FORM];

    protected $fillable = [
        'workspace_id', 'whatsapp_flow_id', 'contact_id', 'source', 'answers',
        'flow_token', 'automation_run_id', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['answers' => 'array'];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $submission): void {
            $submission->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<WhatsappFlow, $this> */
    public function whatsappFlow(): BelongsTo
    {
        return $this->belongsTo(WhatsappFlow::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<AutomationRun, $this> */
    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }
}
