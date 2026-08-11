<?php

namespace App\Models;

use App\Modules\Entitlements\Models\EntitlementGrant;
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
 * @property string $entitlement_mode
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

    /**
     * ─── R-1: the partner ceiling's tri-state ───────────────────────────────
     *
     * CLAUDE.md rule 6: a partner's entitlement is a CEILING — the resolver
     * intersects partner entitlement with the customer's plan, always. But a
     * partner with NO grants makes that intersection ambiguous, and both
     * readings are wrong:
     *
     *   "empty grants nothing"  -> every one of that partner's customers gets
     *                              zero of everything, the moment the ceiling
     *                              ships;
     *   "empty means no ceiling"-> rule 6 is silently not in force, and the
     *                              first partner onboarded without grants
     *                              resells unlimited.
     *
     * The second is the failure class BUG-023 belongs to: a limit resolving to
     * null, read as unlimited, with nothing anywhere saying so. So the answer is
     * neither — it is a RECORDED DECISION.
     */
    public const MODE_UNRESTRICTED = 'unrestricted';

    public const MODE_CEILING = 'ceiling';

    /** @var list<string> */
    public const MODES = [self::MODE_UNRESTRICTED, self::MODE_CEILING];

    protected $fillable = ['uuid', 'name', 'slug', 'status', 'owner_admin_user_id', 'entitlement_mode'];

    /**
     * Mirrors the column default so an unsaved Partner already reads as
     * unrestricted. Without it hasCeiling() and the saving hook would both be
     * reasoning about null on a fresh instance, and "no mode set" would look
     * different from "explicitly unrestricted" — which is the exact ambiguity
     * R-1 exists to remove.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['entitlement_mode' => self::MODE_UNRESTRICTED];

    protected static function booted(): void
    {
        static::creating(function (Partner $partner) {
            $partner->uuid ??= (string) Str::uuid();
            $partner->slug ??= Str::slug($partner->name);
        });

        static::saving(function (Partner $partner) {
            $partner->assertCeilingIsConfigured();
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

    /** @return HasMany<EntitlementGrant, $this> */
    public function entitlementGrants(): HasMany
    {
        return $this->hasMany(EntitlementGrant::class);
    }

    /**
     * A partner in `ceiling` mode must actually have a ceiling.
     *
     * ⚠️ The write-time half of R-1, and the half that carries the weight. The
     * column alone would let someone set `ceiling` on a partner with no grants,
     * which resolves to "this partner may resell nothing" — a total outage for
     * every one of their customers, produced by a dropdown.
     *
     * Refusing at write turns that into an error at the moment of the decision,
     * beside the person who can fix it, instead of a support ticket from someone
     * who cannot.
     *
     * @throws \InvalidArgumentException
     */
    public function assertCeilingIsConfigured(): void
    {
        if ($this->entitlement_mode !== self::MODE_CEILING) {
            return;
        }

        // An unsaved partner has no id, so it can hold no grants yet. Creating
        // straight into `ceiling` is therefore always wrong, and saying so
        // plainly beats a confusing empty-relation query.
        if (! $this->exists) {
            throw new \InvalidArgumentException(
                'A partner cannot be created directly in ceiling mode: it has no entitlement '
                .'grants yet, so the ceiling would be empty and every customer of this partner '
                .'would resolve to zero. Create it unrestricted, grant its entitlements, then '
                .'switch.'
            );
        }

        if (! $this->entitlementGrants()->where('status', EntitlementGrant::STATUS_ACTIVE)->exists()) {
            throw new \InvalidArgumentException(
                'A partner cannot be switched to ceiling mode with no active entitlement '
                .'grants. An empty ceiling grants nothing, so every one of this partner\'s '
                .'customers would lose access — which is not what "restrict this partner" means '
                .'to whoever chose it.'
            );
        }
    }

    public function isActive(): bool
    {
        return ($this->status ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE;
    }

    /**
     * Whether this partner's entitlements act as a ceiling on its customers.
     *
     * `false` is not "no ceiling was configured" — it is "somebody decided this
     * partner is unrestricted". That distinction is the whole point of R-1.
     */
    /**
     * Whether any grant is in force right now.
     *
     * ⚠️ Status AND dates. A grant with `ends_at` in the past is not a ceiling,
     * and nothing wrote a row when it lapsed — which is why the resolver checks
     * this at read time rather than trusting the save-time rule.
     */
    public function hasGrantsInForce(): bool
    {
        return $this->entitlementGrants()
            ->where('status', EntitlementGrant::STATUS_ACTIVE)
            ->get()
            ->contains(fn (EntitlementGrant $g) => $g->isInForce());
    }

    public function hasCeiling(): bool
    {
        return $this->entitlement_mode === self::MODE_CEILING;
    }
}
