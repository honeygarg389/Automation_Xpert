<?php

namespace App\Modules\Shared\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Segment extends Model
{
    /**
     * Phase 0, slice 7 — completing the Shared module.
     *
     * id route key. The segment_contact pivot hangs off it and carries no
     * workspace_id of its own.
     */
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'name', 'type', 'rules_json', 'contact_count'];

    protected function casts(): array
    {
        return [
            'rules_json' => 'array',
            'contact_count' => 'integer',
        ];
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'segment_contact');
    }
}
