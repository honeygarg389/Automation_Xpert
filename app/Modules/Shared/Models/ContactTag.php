<?php

namespace App\Modules\Shared\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContactTag extends Model
{
    /**
     * Phase 0, slice 7 — completing the Shared module.
     *
     * id route key, and UNIQUE (workspace_id, name) — the tenant is already part of
     * its identity, so every firstOrCreate in the codebase already keys on both.
     */
    use BelongsToWorkspace;

    protected $table = 'contact_tags';

    protected $fillable = ['workspace_id', 'name', 'color'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tag_pivot', 'tag_id', 'contact_id');
    }
}
