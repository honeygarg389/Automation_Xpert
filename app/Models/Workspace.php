<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasFactory;

    protected $table = 'workspaces';

    protected $fillable = [
        'owner_id',
        'client_id',
        'name',
        'default_locale',
        'currency_code',
    ];

    protected $attributes = [
        'default_locale' => 'en',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Users who are members of this workspace (via pivot). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Users whose primary workspace is this one. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'workspace_id');
    }

    /** Whether the given user can access this workspace (owner or member). */
    /**
     * Whether the user may access this workspace.
     *
     * MUST agree with User::accessibleWorkspaces(). These are two definitions of
     * the same concept — this one is used by WorkspacePolicy::view (and so by
     * workspace switching), the other by the switcher list and WorkspaceContext.
     * Filtering only one of them leaves the other as a bypass.
     *
     * The client check comes first and is not optional: membership rows are
     * never revoked (ClientWorkspaceService uses syncWithoutDetaching and
     * nothing detaches), so a stale row from a former client must grant nothing.
     * See docs/phase-0-tenant-isolation-plan.md §G-1d.
     */
    public function isAccessibleBy(User $user): bool
    {
        $workspaceClientId = $this->client_id === null ? null : (int) $this->client_id;
        $userClientId = $user->client_id === null ? null : (int) $user->client_id;

        if ($workspaceClientId !== $userClientId) {
            return false;
        }

        if ($this->owner_id === $user->id) {
            return true;
        }

        return $this->members()->where('user_id', $user->id)->exists();
    }
}
