<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingStep extends Model
{
    /**
     * Phase 0, slice 9 — the two orphans.
     *
     * BUG-009. Onboarding progress was recorded per USER while it is DETECTED per
     * workspace — so a user who finished onboarding in one workspace saw the next
     * one as already complete.
     *
     * The workspace_id column and the widened UNIQUE (user_id, workspace_id, step)
     * arrived together in 2026_08_09_100000: without the index change, completing
     * the same step in a second workspace collides on the old key.
     */
    use BelongsToWorkspace;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'step',
        'completed',
        'completed_at',
    ];

    protected $casts = [
        'completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
