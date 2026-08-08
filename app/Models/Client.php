<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Client extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'partner_id',
        'email',
        'phone',
        'address',
        'status',
        'base_currency',
        'currency_symbol',
        'currency_position',
        'logo_path',
        'logo_disk',
        'primary_color',
        'tagline',
        'custom_domain',
        'support_email',
    ];

    public function logoUrl(): ?string
    {
        if (empty($this->logo_path)) {
            return null;
        }

        $disk = $this->logo_disk ?? 'public';

        return Storage::disk($disk)->url($this->logo_path);
    }

    /**
     * The reseller this client belongs to, or NULL for a platform-owned
     * (direct) customer.
     *
     * ⚠️ NULL IS A PERMANENT, SUPPORTED STATE — not a migration artefact to be
     * tidied away. Every partner-aware query must decide explicitly whether it
     * includes direct customers; `Client::directOnly()` and
     * `Client::forPartner()` exist so that decision is spelled rather than
     * implied by a bare `where('partner_id', …)`, which silently excludes them.
     */
    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** Clients belonging to one reseller. Excludes direct customers by design. */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForPartner(Builder $query, int $partnerId): Builder
    {
        return $query->where('partner_id', $partnerId);
    }

    /**
     * Platform-owned customers only.
     *
     * The named counterpart to forPartner(). The failure this prevents is a
     * platform-wide total written as a partner query, which would silently omit
     * every direct customer — and the platform owner's own dashboard is exactly
     * where that would be wrong and unnoticed.
     */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDirectOnly(Builder $query): Builder
    {
        return $query->whereNull('partner_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function clientSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(ClientSubscription::class)->where('status', 'active')->latestOfMany();
    }

    public function activePlan(): ?Plan
    {
        return $this->activeSubscription?->plan;
    }

    /**
     * The plan actually in effect for this client, mirroring
     * User::effectiveSubscription(): an admin-assigned ClientSubscription takes
     * precedence, otherwise fall back to the plan from any of the client's users'
     * active/trialing Subscriptions (e.g. a self-serve Stripe plan). The admin client
     * list must use this — not just activeSubscription — otherwise clients on a normal
     * user Subscription show as "No Plan" even though their dashboard shows the plan.
     */
    public function effectivePlan(): ?Plan
    {
        if ($this->activeSubscription?->plan) {
            return $this->activeSubscription->plan;
        }

        $sub = Subscription::whereIn('user_id', $this->users()->select('id'))
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->orderByDesc('id')
            ->first();

        return $sub?->plan;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
