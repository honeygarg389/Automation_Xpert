<?php

namespace App\Modules\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property string $node_id
 * @property string $node_type
 */
class AutomationRunLog extends Model
{
    protected $table = 'automation_run_logs';

    protected $fillable = ['run_id', 'node_id', 'node_type', 'result', 'message', 'output'];

    protected function casts(): array
    {
        return ['output' => 'array'];
    }

    /** @return BelongsTo<AutomationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'run_id');
    }
}
