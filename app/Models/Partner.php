<?php

namespace App\Models;

use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A reseller. Sits above `Client` in
 * `Platform Owner → Partner → Client → Workspace → Users`.
 *
 * ─── ⚠️ NOT workspace-scoped, and it must never be ──────────────────────────
 *
 * `Partner` is two levels ABOVE the tenant boundary. `Client`, `Workspace`,
 * `ClientSubscription` and `Plan` all likewise carry no `workspace_id` and no
 * `BelongsToWorkspace` — the workspace scope stops below this whole layer.
 *
 * Scoping it would be incoherent rather than merely wrong: a partner owns many
 * clients, each of which owns many workspaces, so "which workspace does a
 * partner belong to" has no answer. `PartnerTierTest` asserts this rather than
 * leaving it to a comment, in the shape of the existing `User` and `Workspace`
 * guards — the coverage guard cannot catch it, because a table with no
 * `workspace_id` column never enters that guard's inventory.
 *
 * ─── partner_id is DERIVED, not denormalised ────────────────────────────────
 *
 * It lives on `clients` and nowhere else. A workspace's partner is
 * `$workspace->client->partner`. CLAUDE.md permits denormalising it onto
 * aggregate/billing tables where a partner-level query would otherwise need an
 * expensive join — each such instance must be documented, and there are none
 * today.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property int|null $owner_admin_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = ['uuid', 'name', 'slug', 'status', 'owner_admin_user_id'];

    protected static function booted(): void
    {
        static::creating(function (Partner $partner) {
            $partner->uuid ??= (string) Str::uuid();
            $partner->slug ??= Str::slug($partner->name);
        });
    }

    /** Route key is the uuid: a sequential id in a URL enumerates resellers. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'owner_admin_user_id');
    }

    public function isActive(): bool
    {
        return ($this->status ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE;
    }
}
