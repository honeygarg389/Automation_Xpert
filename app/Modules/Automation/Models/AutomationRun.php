<?php

namespace App\Modules\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $automation_id
 * @property int|null $contact_id
 * @property string $status
 * @property string|null $error
 * @property array<string, mixed>|null $context
 * @property Automation|null $automation
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class AutomationRun extends Model
{
    protected $table = 'automation_runs';

    protected $fillable = ['automation_id', 'contact_id', 'status', 'context', 'current_node_id', 'resume_node_id', 'error', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Automation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return HasMany<AutomationRunLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(AutomationRunLog::class, 'run_id');
    }
}
