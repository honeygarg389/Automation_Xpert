<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Entitlements\Jobs\ReconcileWorkspaceEntitlements;
use App\Modules\Entitlements\Services\EntitlementCache;
use App\Modules\Entitlements\Support\PlanLimitKinds;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\Billing\StripeGateway;
use App\Support\BillingCycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function __construct(private readonly EntitlementCache $entitlementCache) {}

    /**
     * ⚠️ THE KEY SET IS DERIVED, NOT RETYPED. BUG-027.
     *
     * This used to return two keys, `users` and `storage`, hand-written. The
     * admin form (`resources/js/Pages/Admin/Plans/PlanLimits.jsx`) has its own
     * `LIMIT_KEYS` array with SIXTEEN, renders an input for each, and submits
     * all sixteen.
     *
     * `validatePlan()` builds its rules from `array_keys(self::defaultLimits())`,
     * and Laravel's `validate()` returns only attributes that HAVE rules. So
     * fourteen keys arrived, had no rule, were dropped from `$validated`, and
     * `mapValidatedToAttributes()` then replaced the plan's entire limits JSON
     * with the two survivors.
     *
     * An administrator filled in every limit, saved, and fourteen vanished — and
     * a missing key reads as `null`, which every consumer treats as UNLIMITED.
     * Editing a plan GRANTED everything on it.
     *
     * The fix is not "add fourteen more literals here", which would leave two
     * lists to drift apart again. `PlanLimitKinds::MAP` is the single
     * declaration of which limit keys exist — it already backs the entitlement
     * resolver, and slice 4 needs it too. Deriving from it means a new key is
     * added in exactly one place and every consumer follows.
     *
     * The front end still declares its own list for labelling and ordering; the
     * guard in PlanLimitKeyDivergenceTest asserts the two agree, so a future
     * divergence fails the build instead of silently discarding a customer's
     * limits.
     *
     * @return array<string, null>
     */
    public static function defaultLimits(): array
    {
        return array_fill_keys(array_keys(PlanLimitKinds::MAP), null);
    }

    /** Enabled currencies for the plan form dropdown. */
    private function currencyOptions(): array
    {
        return Currency::where('enabled', true)
            ->orderBy('code')
            ->get(['code', 'symbol'])
            ->map(fn (Currency $c) => ['code' => $c->code, 'symbol' => $c->symbol])
            ->all();
    }

    /**
     * ⚠️ CATALOG, NOT ENTITLEMENT — do not route this through the facade.
     *
     * This is an administrator editing the PLAN DEFINITION. The facade answers
     * "what may this customer do", which is a different question with a
     * different answer: it folds grants and applies a partner ceiling, so it
     * would show the admin some customer's resolved entitlement in a form whose
     * Save button writes the plan row. Editing a resolved value back into its
     * own source is how a ceiling would silently become the plan.
     *
     * `plans.limits` is the correct source here, and this form is the thing that
     * writes it.
     */
    private function planToArray(Plan $p): array
    {
        $limits = $p->limits;
        if (! is_array($limits)) {
            $limits = self::defaultLimits();
        }

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'description' => $p->description,
            'currency_code' => $p->currency_code,
            'monthly_price_cents' => $p->monthly_price_cents,
            'quarterly_price_cents' => $p->quarterly_price_cents,
            'half_yearly_price_cents' => $p->half_yearly_price_cents,
            'yearly_price_cents' => $p->yearly_price_cents,
            'trial_days' => (int) ($p->trial_days ?? 0),
            'stripe_monthly_id' => $p->stripe_monthly_id,
            'stripe_quarterly_id' => $p->stripe_quarterly_id,
            'stripe_half_yearly_id' => $p->stripe_half_yearly_id,
            'stripe_yearly_id' => $p->stripe_yearly_id,
            'features' => is_array($p->features) ? $p->features : [],
            'limits' => $limits,
            'enabled' => (bool) $p->enabled,
            'featured' => (bool) ($p->featured ?? false),
            'popular' => (bool) ($p->popular ?? false),
            'sort_order' => (int) $p->sort_order,
            'white_label_enabled' => (bool) ($p->white_label_enabled ?? false),
            'whatsapp_flows_enabled' => (bool) ($p->whatsapp_flows_enabled ?? true),
        ];
    }

    public function index(Request $request): Response
    {
        $plans = Plan::orderBy('sort_order')->orderBy('id')->get()->map(fn (Plan $p) => $this->planToArray($p));

        return Inertia::render('Admin/Plans/Index', [
            'plans' => $plans,
            'currencies' => $this->currencyOptions(),
            'defaultCurrency' => Currency::defaultCode() ?? 'USD',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request, null);
        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['name']);
        $validated['sort_order'] = (int) (Plan::max('sort_order') ?? 0) + 1;

        $plan = Plan::create($this->mapValidatedToAttributes($validated));

        return redirect()->route('admin.plans.index')
            ->with('success', __('Plan created successfully.'))
            ->with('warning', $this->stripePriceWarning($plan));
    }

    public function edit(Plan $plan): Response
    {
        return Inertia::render('Admin/Plans/Edit', [
            'plan' => $this->planToArray($plan),
            'currencies' => $this->currencyOptions(),
            'defaultCurrency' => Currency::defaultCode() ?? 'USD',
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $this->validatePlan($request, $plan);
        $plan->update($this->mapValidatedToAttributes($validated));
        $this->refreshEntitlementsForPlan($plan);

        return redirect()->route('admin.plans.index')
            ->with('success', __('Plan updated successfully.'))
            ->with('warning', $this->stripePriceWarning($plan->fresh()));
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        $plan->delete();

        return redirect()->route('admin.plans.index')->with('success', __('Plan deleted successfully.'));
    }

    public function duplicate(Plan $plan): RedirectResponse
    {
        $copy = $plan->replicate();
        $copy->name = $plan->name.' (Copy)';
        $copy->slug = $plan->slug.'-copy-'.Str::random(4);
        $copy->sort_order = (int) (Plan::max('sort_order') ?? 0) + 1;
        $copy->save();

        return redirect()->route('admin.plans.index')
            ->with('success', __('Plan duplicated successfully.'))
            ->with('openEditPlanId', $copy->id);
    }

    public function reorder(Request $request): RedirectResponse
    {
        $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer', 'exists:plans,id']]);

        foreach ($request->input('order') as $position => $id) {
            Plan::where('id', $id)->update(['sort_order' => $position]);
        }

        return redirect()->route('admin.plans.index')->with('success', __('Plans reordered.'));
    }

    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        $request->merge(['currency_code' => strtoupper(trim((string) $request->input('currency_code')))]);

        $limitsKeys = array_keys(self::defaultLimits());
        $slugRule = ['nullable', 'string', 'max:64'];
        if ($plan) {
            $slugRule[] = 'unique:plans,slug,'.$plan->id;
        } else {
            $slugRule[] = 'unique:plans,slug';
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'slug' => $slugRule,
            'description' => ['nullable', 'string', 'max:1000'],
            'currency_code' => ['required', 'string', 'max:10', Rule::exists('currencies', 'code')],
            'monthly_price_cents' => ['required', 'integer', 'min:0'],
            'quarterly_price_cents' => ['nullable', 'integer', 'min:0'],
            'half_yearly_price_cents' => ['nullable', 'integer', 'min:0'],
            'yearly_price_cents' => ['nullable', 'integer', 'min:0'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'stripe_monthly_id' => ['nullable', 'string', 'max:255'],
            'stripe_quarterly_id' => ['nullable', 'string', 'max:255'],
            'stripe_half_yearly_id' => ['nullable', 'string', 'max:255'],
            'stripe_yearly_id' => ['nullable', 'string', 'max:255'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:500'],
            'enabled' => ['boolean'],
            'featured' => ['boolean'],
            'popular' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'white_label_enabled' => ['boolean'],
            'whatsapp_flows_enabled' => ['boolean'],
        ];

        foreach ($limitsKeys as $key) {
            $rules['limits.'.$key] = ['nullable', 'integer', 'min:0'];
        }

        return $request->validate($rules);
    }

    private function mapValidatedToAttributes(array $validated): array
    {
        $monthly = (int) ($validated['monthly_price_cents'] ?? 0);

        return [
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'currency_code' => $validated['currency_code'],
            'price_cents' => $monthly,
            'interval' => 'month',
            'monthly_price_cents' => $monthly,
            'quarterly_price_cents' => isset($validated['quarterly_price_cents']) ? (int) $validated['quarterly_price_cents'] : null,
            'half_yearly_price_cents' => isset($validated['half_yearly_price_cents']) ? (int) $validated['half_yearly_price_cents'] : null,
            'yearly_price_cents' => isset($validated['yearly_price_cents']) ? (int) $validated['yearly_price_cents'] : null,
            'trial_days' => (int) ($validated['trial_days'] ?? 0),
            'stripe_monthly_id' => $validated['stripe_monthly_id'] ?? null,
            'stripe_quarterly_id' => $validated['stripe_quarterly_id'] ?? null,
            'stripe_half_yearly_id' => $validated['stripe_half_yearly_id'] ?? null,
            'stripe_yearly_id' => $validated['stripe_yearly_id'] ?? null,
            'features' => $validated['features'] ?? [],
            'limits' => $validated['limits'] ?? null,
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'featured' => (bool) ($validated['featured'] ?? false),
            'popular' => (bool) ($validated['popular'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'white_label_enabled' => (bool) ($validated['white_label_enabled'] ?? false),
            'whatsapp_flows_enabled' => (bool) ($validated['whatsapp_flows_enabled'] ?? true),
        ];
    }

    /**
     * A plan edit changes the source for every client currently resolving this
     * plan. Delete their materialized rows in this request, so the next read
     * recomputes even when no queue worker is running; then enqueue the existing
     * reconciliation job to warm every workspace for each affected client.
     */
    private function refreshEntitlementsForPlan(Plan $plan): void
    {
        $assignedClientIds = ClientSubscription::query()
            ->where('plan_id', $plan->id)
            ->where('status', ClientSubscription::STATUS_ACTIVE)
            ->pluck('client_id');

        $candidateClientIds = $assignedClientIds;

        if (config('entitlements.enforce_effective_plan_source', false)) {
            $selfServeClientIds = User::query()
                ->whereIn('id', Subscription::query()
                    ->where('plan_id', $plan->id)
                    ->whereIn('status', ['active', 'trialing'])
                    ->select('user_id'))
                ->pluck('client_id');

            $candidateClientIds = $candidateClientIds->merge($selfServeClientIds);
        }

        Client::query()->whereKey($candidateClientIds->filter()->unique())->get()
            ->filter(fn (Client $client) => $this->planForEntitlementSource($client)?->is($plan))
            ->each(function (Client $client): void {
                $this->entitlementCache->forgetClient((int) $client->id);
                ReconcileWorkspaceEntitlements::dispatch((int) $client->id);
            });
    }

    /** Match EntitlementResolver's plan selection exactly. */
    private function planForEntitlementSource(Client $client): ?Plan
    {
        return config('entitlements.enforce_effective_plan_source', false)
            ? $client->effectivePlan()
            : $client->activePlan();
    }

    /**
     * Compare each configured Stripe Price ID against this plan's own price.
     *
     * ⚠️ ADVISORY ONLY — NEVER BLOCKING. The plan is already saved when this runs,
     * and that is deliberate: verification talks to a third party, so it can fail
     * for reasons that have nothing to do with what the admin typed (revoked key,
     * network, test-vs-live mode). Letting any of those refuse a plan save would
     * make Stripe's availability a prerequisite for editing local pricing.
     *
     * ⚠️ WHY IT MATTERS: createCheckout() PREFERS the Stripe catalog price over
     * `*_price_cents` when one is set. So a mismatch does not mean "two numbers
     * disagree cosmetically" — it means customers are charged the Stripe number
     * while every screen here shows the local one.
     *
     * A verification failure produces a DISTINCT message rather than silence:
     * "could not verify" and "verified, and it differs" are different facts, and
     * staying quiet on the first would let a typo'd price id look approved.
     */
    private function stripePriceWarning(Plan $plan): ?string
    {
        // Derived from BillingCycle so a fifth cycle needs no edit here.
        $pairs = array_map(
            fn (string $cycle) => [
                $cycle,
                $plan->{BillingCycle::stripePriceIdColumn($cycle)},
                $plan->priceCentsForCycle($cycle),
            ],
            BillingCycle::ALL
        );

        $hasAnyPriceId = array_filter($pairs, fn (array $pair) => ! empty($pair[1])) !== [];

        if (! $hasAnyPriceId) {
            return null;
        }

        $gateway = app(BillingGatewayRegistry::class)->get('stripe');
        if (! $gateway instanceof StripeGateway) {
            return null; // Stripe not enabled — nothing to verify against.
        }

        $messages = [];

        foreach ($pairs as [$cycle, $priceId, $localCents]) {
            if (empty($priceId)) {
                continue;
            }

            $result = $gateway->priceAmountMinorUnits((string) $priceId);

            if (isset($result['error'])) {
                $messages[] = __('Could not verify the Stripe Price ID for :cycle billing (:id). Check the ID and that your Stripe key is valid.', [
                    'cycle' => $cycle,
                    'id' => $priceId,
                ]);

                continue;
            }

            $stripeCents = $result['amount'];
            if ($stripeCents === null || $localCents === null) {
                continue;
            }

            if ((int) $stripeCents !== (int) $localCents) {
                $messages[] = __("Your Stripe Price ID for :cycle billing charges :stripe, but this plan's price here is :local. Customers will be charged the Stripe amount at checkout.", [
                    'cycle' => $cycle,
                    'stripe' => number_format($stripeCents / 100, 2).' '.strtoupper((string) $plan->currency_code),
                    'local' => number_format(((int) $localCents) / 100, 2).' '.strtoupper((string) $plan->currency_code),
                ]);
            }
        }

        return $messages === [] ? null : implode(' ', $messages);
    }
}
