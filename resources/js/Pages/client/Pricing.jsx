import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Card } from '@/Components/ui';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Check, Zap } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Public-facing pricing page. Works for anonymous visitors and authenticated users.
 * Anonymous: "Get Started" → /register?plan_id=X&cycle=Y
 * Authenticated: checkout via gateway
 */
export default function Pricing({
    plans = [],
    gateways = [],
    flash = {},
    is_authenticated = false,
    register_url = '/register',
    checkout_url = null,
}) {
    const { t } = useTranslation();

    /**
     * Mirrors App\Support\BillingCycle::ALL. `months` drives the savings
     * calculation below; `perLabel` is the "/ month" suffix.
     */
    const CYCLES = [
        { key: 'month', months: 1, label: 'pricing.monthly', perLabel: 'pricing.per_month' },
        { key: 'quarter', months: 3, label: 'pricing.quarterly', perLabel: 'pricing.per_quarter' },
        { key: 'half_year', months: 6, label: 'pricing.half_yearly', perLabel: 'pricing.per_half_year' },
        { key: 'year', months: 12, label: 'pricing.yearly', perLabel: 'pricing.per_year' },
    ];

    // Only offer cycles at least one plan is actually sold on — a cycle with no
    // price sends the customer to a checkout that refuses.
    const offeredCycles = CYCLES.filter(
        (c) => c.key === 'month' || plans.some((p) => (p.available_cycles ?? []).includes(c.key))
    );

    /**
     * ⚠️ THE SAVINGS BADGE IS COMPUTED, NOT ASSERTED.
     *
     * It used to read a hardcoded "Save 15%" on the yearly button — a marketing
     * claim that was true only if whoever configured the prices happened to make
     * it true, and silently false otherwise. With four cycles that would be three
     * more unverifiable claims.
     *
     * This derives the real best saving against the monthly-equivalent price and
     * shows a badge only when there IS one. A cycle priced at or above monthly
     * simply gets no badge rather than a false one.
     */
    const savingPercent = (cycleKey, months) => {
        if (cycleKey === 'month') return null;
        const best = plans.reduce((acc, p) => {
            const monthly = p.prices_cents?.month;
            const cycleCents = p.prices_cents?.[cycleKey];
            if (!monthly || !cycleCents) return acc;
            const pct = Math.round((1 - cycleCents / (monthly * months)) * 100);
            return pct > acc ? pct : acc;
        }, 0);
        return best > 0 ? best : null;
    };

    const [billingCycle, setBillingCycle]     = useState('month');
    const [loadingGateway, setLoadingGateway] = useState(null);
    const { url } = usePage();

    const hasSuccess   = url.includes('checkout=success');
    const hasCanceled  = url.includes('checkout=canceled');
    const flashError   = flash?.error;
    const flashSuccess = flash?.success;

    const configuredGateways = gateways.filter((g) => g.configured);

    const handleCheckout = (planId, gatewayKey) => {
        if (! planId || ! gatewayKey) return;
        const loadingKey = `${planId}-${gatewayKey}`;
        setLoadingGateway(loadingKey);
        router.post(checkout_url ?? route('client.checkout.store'), {
            plan_id:       planId,
            billing_cycle: billingCycle,
            gateway:       gatewayKey,
        }, {
            preserveScroll: true,
            onFinish: () => setLoadingGateway(null),
        });
    };

    const getStartedHref = (plan) => {
        if (plan.is_free) {
            return is_authenticated ? route('client.dashboard') : register_url;
        }
        return `${register_url}?plan_id=${plan.id}&cycle=${billingCycle}`;
    };

    return (
        <ClientLayout title={t('pricing.title')}>
            <Head title={t('pricing.title')} />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('pricing.choose_plan')}</h2>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                            {t('pricing.transparent')}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 rounded-soft-lg border border-soft border-neutral-200 bg-neutral-50 p-1 dark:bg-neutral-800 dark:border-neutral-700">
                        {offeredCycles.map(({ key, months, label }) => {
                            const saving = savingPercent(key, months);
                            return (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setBillingCycle(key)}
                                    className={`rounded-soft px-3 py-1.5 text-sm font-medium transition ${billingCycle === key ? 'bg-white dark:bg-neutral-700 text-neutral-900 dark:text-neutral-100 shadow-soft' : 'text-neutral-600 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100'}`}
                                >
                                    {t(label)}
                                    {saving !== null && (
                                        <span className="text-xs text-green-600 dark:text-green-400 ml-0.5">
                                            {t('pricing.save_percent', { percent: saving })}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {(flashError || hasCanceled) && (
                    <div className="rounded-soft-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        {flashError || t('pricing.checkout_canceled')}
                    </div>
                )}
                {(flashSuccess || hasSuccess) && (
                    <div className="rounded-soft-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                        {flashSuccess || t('pricing.payment_success')}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {plans.map((plan) => {
                        const priceDisplay = plan.prices_display?.[billingCycle] ?? null;
                        const isFree       = plan.is_free;
                        const isPopular    = plan.popular;

                        return (
                            <div
                                key={plan.id}
                                className={`relative flex flex-col rounded-xl border bg-white dark:bg-neutral-900 p-6 ${isPopular ? 'border-brand-500 ring-2 ring-brand-400/30' : 'border-neutral-200 dark:border-neutral-700'}`}
                            >
                                {isPopular && (
                                    <div className="absolute -top-3 left-1/2 -translate-x-1/2">
                                        <span className="inline-flex items-center gap-1 rounded-full bg-brand-600 px-3 py-0.5 text-xs font-semibold text-white">
                                            <Zap className="h-3 w-3" /> {t('pricing.most_popular')}
                                        </span>
                                    </div>
                                )}

                                <div className="flex-1">
                                    <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{plan.name}</h3>
                                    {plan.description && (
                                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{plan.description}</p>
                                    )}
                                    <div className="mt-4">
                                        <span className="text-3xl font-bold text-neutral-900 dark:text-white">
                                            {isFree ? t('pricing.free') : (priceDisplay ?? '—')}
                                        </span>
                                        {! isFree && (
                                            <span className="ml-1 text-sm text-neutral-500">/ {t(CYCLES.find((c) => c.key === billingCycle)?.perLabel ?? 'pricing.per_month')}</span>
                                        )}
                                    </div>
                                    {plan.trial_days > 0 && (
                                        <p className="mt-1 text-xs text-brand-600 dark:text-brand-400">{t('pricing.trial_days', { days: plan.trial_days })}</p>
                                    )}

                                    {/* Features list */}
                                    <ul className="mt-5 space-y-2 text-sm text-neutral-600 dark:text-neutral-300">
                                        {plan.features?.map((f, i) => (
                                            <li key={i} className="flex items-center gap-2">
                                                <Check className="h-4 w-4 text-brand-500 shrink-0" />
                                                {f}
                                            </li>
                                        ))}
                                        {plan.white_label_enabled && (
                                            <li className="flex items-center gap-2">
                                                <Check className="h-4 w-4 text-brand-500 shrink-0" /> {t('pricing.white_label')}
                                            </li>
                                        )}
                                    </ul>
                                </div>

                                {/* CTA */}
                                <div className="mt-6">
                                    {! is_authenticated ? (
                                        // Anonymous: Get Started → register (with plan hint)
                                        <Link
                                            href={getStartedHref(plan)}
                                            className={`block w-full rounded-lg py-2.5 text-center text-sm font-semibold transition ${isPopular ? 'bg-brand-600 text-white hover:bg-brand-700' : 'border border-neutral-300 dark:border-neutral-600 text-neutral-900 dark:text-neutral-100 hover:bg-neutral-50 dark:hover:bg-neutral-800'}`}
                                        >
                                            {isFree ? t('pricing.get_started_free') : t('pricing.get_started')}
                                        </Link>
                                    ) : isFree ? (
                                        <p className="text-sm text-neutral-500 dark:text-neutral-400 text-center">{t('pricing.free_no_payment')}</p>
                                    ) : configuredGateways.length === 0 ? (
                                        <p className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-soft px-3 py-2 text-center">
                                            {t('pricing.no_gateway')}
                                        </p>
                                    ) : (
                                        <div className="space-y-2">
                                            {configuredGateways.map((gw) => {
                                                const buttonKey   = `${plan.id}-${gw.key}`;
                                                const isThisLoading = loadingGateway === buttonKey;
                                                return (
                                                    <Button
                                                        key={buttonKey}
                                                        type="button"
                                                        variant={isPopular ? 'primary' : 'outline'}
                                                        size="sm"
                                                        className="w-full"
                                                        disabled={loadingGateway !== null}
                                                        onClick={() => handleCheckout(plan.id, gw.key)}
                                                    >
                                                        {isThisLoading ? t('pricing.redirecting') : t('pricing.pay_with', { name: gw.name })}
                                                    </Button>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>

                {gateways.length > 0 && is_authenticated && (
                    <div className="rounded-soft-lg border border-soft border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800 px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                        <span className="font-medium">{t('pricing.gateways_label')} </span>
                        {gateways.map((g) => (
                            <span key={g.key} className="mr-2">
                                {g.name} {g.configured ? '✓' : t('pricing.not_configured')}
                            </span>
                        ))}
                    </div>
                )}

                {! plans?.length && (
                    <Card>
                        <Card.Body>
                            <p className="text-neutral-500 dark:text-neutral-400">{t('pricing.no_plans')}</p>
                        </Card.Body>
                    </Card>
                )}
            </div>
        </ClientLayout>
    );
}
