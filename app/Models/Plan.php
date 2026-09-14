<?php

namespace App\Models;

use App\Modules\Entitlements\Models\AddOn;
use App\Support\BillingCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $features
 *                                               ⚠️ `limits` is `mixed`-valued, not `int|null`, and deliberately so. It is a
 *                                               JSON column: it round-trips whatever was written to it, and nothing in the
 *                                               schema constrains the values. Declaring it narrower would be a claim the
 *                                               database does not make — and it made PlanPackageSynthesizer's defensive
 *                                               branch look like dead code to PHPStan, which is the annotation lying, not the
 *                                               defence being unnecessary.
 * @property array<string, mixed>|null $limits
 *                                             ⚠️ @property bool $white_label_enabled — DERIVED, NOT AUTHORITATIVE.
 *
 * This column no longer decides anything. It is the legacy SEED for the
 * `white_label` feature grant: PlanPackageSynthesizer bridges it into the
 * synthesized package, and the entitlement resolver is the authority. Ask
 * `Entitlements`/`Entitlement::allows('white_label')`, never this column.
 *
 * It is kept rather than dropped because it is still the source the bridge reads
 * — the same position plans.limits is in — and it will be removed only once a
 * test proves nothing reads it. Its only former reader, Plan::hasFeature(), was
 * deleted in slice 3 (zero callers, returned false for everything else).
 *
 * A live column that no longer decides anything is the trap this codebase keeps
 * finding, so it is labelled here rather than left to look authoritative.
 * @property bool $white_label_enabled
 * @property bool $whatsapp_flows_enabled Legacy seed for the resolver-owned Flow entitlement.
 * @property bool $enabled
 */
class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_cents',
        'currency_code',
        'interval',
        'sort_order',
        'enabled',
        'monthly_price_cents',
        'quarterly_price_cents',
        'half_yearly_price_cents',
        'yearly_price_cents',
        'trial_days',
        'stripe_monthly_id',
        'stripe_quarterly_id',
        'stripe_half_yearly_id',
        'stripe_yearly_id',
        'features',
        'limits',
        'featured',
        'popular',
        'white_label_enabled',
        'whatsapp_flows_enabled',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'sort_order' => 'integer',
            'enabled' => 'boolean',
            'monthly_price_cents' => 'integer',
            'quarterly_price_cents' => 'integer',
            'half_yearly_price_cents' => 'integer',
            'yearly_price_cents' => 'integer',
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'featured' => 'boolean',
            'popular' => 'boolean',
            'white_label_enabled' => 'boolean',
            'whatsapp_flows_enabled' => 'boolean',
        ];
    }

    /**
     * Price in cents for the given billing cycle.
     */
    /**
     * Price in cents for the given billing cycle, or null when this plan is not
     * sold on that cycle.
     *
     * ⚠️ ONLY `month` FALLS BACK TO `price_cents`, and the asymmetry is
     * deliberate rather than an oversight. `price_cents` is the legacy single-
     * price column from before per-cycle pricing existed, and its meaning is
     * "the monthly price" — there is no cycle recorded alongside it. Extending
     * that fallback to quarter, half_year or year would silently sell a yearly
     * subscription at a monthly price, which is the failure this method exists
     * to prevent. A cycle with no price returns null and every gateway refuses.
     */
    public function priceCentsForCycle(string $cycle): ?int
    {
        return match ($cycle) {
            BillingCycle::MONTH => $this->monthly_price_cents ?? $this->price_cents,
            BillingCycle::QUARTER => $this->quarterly_price_cents,
            BillingCycle::HALF_YEAR => $this->half_yearly_price_cents,
            BillingCycle::YEAR => $this->yearly_price_cents,
            default => null,
        };
    }

    /**
     * The cycles this plan is actually sold on, in canonical order.
     *
     * Drives the pricing page's cycle selector — a cycle with no price must not
     * be offered, or checkout refuses after the customer has chosen it.
     *
     * @return list<string>
     */
    public function availableCycles(): array
    {
        return array_values(array_filter(
            BillingCycle::ALL,
            fn (string $c) => ($this->priceCentsForCycle($c) ?? 0) > 0
        ));
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * Value from the plan's JSON limits column. Not named `limit` — that is the query builder.
     */
    public function limitValue(string $key): mixed
    {
        $limits = $this->limits;

        return is_array($limits) ? ($limits[$key] ?? null) : null;
    }

    /**
     * The add-ons this plan includes.
     *
     * The backward-compatibility bridge for Phase 1, not a new concept: each
     * existing plan gains one synthesized `package` add-on carrying its current
     * `limits`, and links to it here. Existing subscriptions need no migration —
     * they still point at plan_id, and the resolver walks
     * plan -> plan_add_on -> add_on_grants.
     *
     * `plans.limits` stays authoritative until a test proves nothing reads it.
     *
     * ⚠️ The pivot carries NO quantity. `entitlement_grants.quantity` is the one
     * place a holding's quantity lives; a second one would make the resolver's
     * answer depend on which path the grant arrived by for the same customer
     * holding the same thing.
     *
     * @return BelongsToMany<AddOn, $this>
     */
    public function addOns(): BelongsToMany
    {
        return $this->belongsToMany(AddOn::class, 'plan_add_on')
            ->withTimestamps();
    }

    public function clientSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
