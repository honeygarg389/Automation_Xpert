# Billing gateway cleanup — findings

**⚠️ PROVENANCE.** These findings come from the owner's inspection, not from a measurement made
in the session that wrote this file. The checkable claims were re-verified here and are marked
✅; the rest are recorded as reported so nothing is lost. **Do not treat the unverified items as
measured** — re-check before acting on them.

**This is its own track. It is NOT started, and it is not part of Smart QR.**

---

## Scope

**Eleven gateways to remove, not ten.** ✅ Verified: 13 classes implement
`BillingGatewayInterface`, and the two keepers are **Razorpay** and **Cashfree** — so eleven go.

**Removal is code-only.** ✅ Verified against the working database:

```
plans = 0    subscriptions = 0    payment_transactions = 0    payment_gateway_configs = 1
```

The single `payment_gateway_configs` row is the inert paddle row. No credentials, no customer
data, nothing to migrate.

---

## The plan-form field question — the proposed fix is the wrong one

Adding `razorpay_*_id` columns to the plan form is **wrong**. Razorpay and Cashfree register
plans **at checkout** and have no use for a stored gateway-side price id — the field would be
collected, saved and never read.

**The right fix is the opposite direction: remove the dead Stripe/Paddle fields once Paddle is
gone.** `plans` currently carries `stripe_monthly_id`, `stripe_yearly_id`, `paddle_monthly_id`
and `paddle_yearly_id`; with both gateways removed, all four are dead columns on a table the
admin form writes.

### ⚠️ `add_on_prices` inherited the same anti-pattern, deliberately, and is dead code today

Phase 1 slice 1 gave `add_on_prices` a `stripe_price_id` and a `paddle_price_id`, *"deliberately
mirroring the columns `plans` already carries rather than inventing a second pricing
vocabulary."* That was the right call at the time and it is the wrong shape now.

**It is free to fix today and expensive after slice 6**, because nothing reads those columns yet
— the purchase path that would populate them is blocked on BUG-032. Fix it while it is still
dead code.

---

## ⚠️ The one thing to get wrong on that PR

**`resources/js/Pages/client/Checkout/Sdk.jsx`** ✅ (exists). **Cashfree needs it.** Paddle's
removal will make it *look* orphaned, because Paddle is the other obvious SDK consumer.

Deleting it breaks checkout for one of the two gateways being kept. Flag it on the PR
description, not in a comment nobody reads during a large deletion.

---

## What removal closes, and what it does not

**It CLOSES BUG-034 by deletion.** Paddle and PayPal are the only two gateways that never
release the idempotency lock on handler failure — both are on the removal list, so the defect
leaves with them. No code fix needed if the removal lands first.

**It does NOTHING for BUG-032.** Refunds revoke no subscription in **any** of the thirteen,
including both keepers. That remains slice 6's blocker regardless.

---

## ⚠️ A product decision hiding inside a cleanup

**Removing the eleven removes every gateway capable of in-place plan changes.** Neither Razorpay
nor Cashfree supports changing a subscription's plan on the gateway side — an upgrade or
downgrade becomes cancel-and-resubscribe.

That is a **product decision awaiting the owner**, not a side effect to absorb quietly. It
changes what "upgrade" means for every future customer.

---

## Recorded for later, not actionable now

**Razorpay creates a new gateway-side plan object on every checkout attempt.** Not a bug — it is
how their API is being used — but it will clutter the Razorpay dashboard with duplicate plan
objects, one per abandoned checkout. Worth a dedupe or a naming convention before volume.

**PhonePe, if it is ever added, does not fit the current driver shape.** It requires a
merchant-driven loop: *notify → wait 24h → execute*. That needs new schema (a
`renewal_notified_at` column, or a `redemptions` table) and a **scheduled** driver rather than a
webhook-driven one.

⚠️ **The three `ChargeRecurring*` commands about to be deleted are the only working examples of
that shape in this codebase** ✅ — `ChargeRecurringMyFatoorahCommand`,
`ChargeRecurringPaymobCommand`, `ChargeRecurringTapCommand`. If PhonePe is on the roadmap, read
them before they are removed, or the pattern is reconstructed from scratch later.
