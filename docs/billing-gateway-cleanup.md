# Billing gateway cleanup — findings

**Status: RECORDED ONLY. Not started, not scheduled. Its own track, after Smart QR.**

⚠️ **Provenance.** These findings come from the owner's inspection, not from a sweep run here.
Items marked **[verified]** were re-measured against `master` on 2026-08-13; items marked
**[from inspection]** are recorded as given and have not been independently checked. The
distinction matters because this document will outlive the conversation.

---

## Scope

**Eleven gateways to remove, not ten.** [from inspection]

Thirteen implement `BillingGatewayInterface` **[verified]**: Cashfree, MercadoPago, Mollie,
MyFatoorah, Paddle, PayPal, Paymob, Paystack, Razorpay, Square, Stripe, Tap, Xendit. Eleven go;
the keepers are Razorpay and Cashfree.

**Removal is code-only.** [verified] The working database holds:

```
payment_gateway_configs  1     (one inert paddle row, no credentials)
plans                    0
subscriptions            0
client_subscriptions     0
payment_transactions     0
```

Nothing to migrate, nothing to back-fill, no customer affected. This is the cheapest this
cleanup will ever be.

---

## The plan-form price-id fields

**Adding `razorpay_*_id` to the plan form is the wrong fix.** [from inspection]

Razorpay and Cashfree register plans **at checkout** and have no use for a stored price id. The
plan form's gateway fields exist for the Stripe/Paddle model, where a price object is created
ahead of time and referenced.

`plans` carries exactly four such columns **[verified]**:

```
stripe_monthly_id, stripe_yearly_id, paddle_monthly_id, paddle_yearly_id
```

No `razorpay_*` or `cashfree_*` columns exist — correctly. **The right fix is removing the dead
Stripe/Paddle fields once Paddle is gone**, not adding four more for gateways that do not want
them.

---

## ⚠️ `add_on_prices` inherited the anti-pattern, deliberately, and is dead code today

`add_on_prices` (Phase 1 slice 1) carries `stripe_price_id` and `paddle_price_id` because it was
built to mirror the columns `plans` already had — a deliberate choice at the time, to avoid
inventing a second pricing vocabulary.

That reasoning was sound then and is wrong now. **Nothing reads those columns; slice 6 (the
purchase path) is blocked and unbuilt, so the table has no consumers at all.**

**It is free to fix now and expensive after slice 6.** Once purchasing writes and reads those
columns, changing them is a migration with live data behind it. Today it is a column drop on an
empty table.

---

## The `Sdk.jsx` trap — the one thing to get wrong on that PR

`resources/js/Pages/client/Checkout/Sdk.jsx` **[verified: exists]**

**Cashfree needs it. Paddle's removal makes it look orphaned.** Anyone deleting eleven gateways
and then sweeping for now-unused front-end assets will find this file referenced by a gateway
that is going away and conclude it is dead. It is not — one of the two keepers depends on it.

Flagged here because it is the single most likely mistake on that PR, and its failure mode is a
checkout that silently stops working for the gateway that survived.

---

## What removal closes, and what it does not

**It CLOSES BUG-034.** [verified against the recorded finding] BUG-034 is Paddle and PayPal
failing to release the idempotency lock on handler failure — and they are the **only** two
offenders of thirteen. Both are on the removal list, so the finding closes **by deletion**
rather than by fix. BUG-034's entry has been updated to say so.

**It does NOTHING for BUG-032.** [verified] `refund()` touches no `Subscription` in **any** of the
thirteen, including both keepers. Removing eleven leaves the defect fully intact, and slice 6
stays blocked on it.

---

## ⚠️ Product decisions this forces — not side effects

**Removal eliminates every gateway capable of in-place plan changes.** [from inspection]

That is a capability loss, not a cleanup artifact. Upgrades and downgrades would become
cancel-and-resubscribe. **Awaiting the owner's decision** — recorded here so it is decided rather
than discovered after the PR merges.

**Razorpay creates a new gateway-side plan object on every checkout attempt.** [from inspection]
Not a bug, and not a blocker — but it will clutter the Razorpay dashboard with abandoned plan
objects proportional to abandoned checkouts. Worth knowing before volume arrives.

---

## PhonePe — and a warning about what is being deleted

⚠️ **PhonePe does not exist in this codebase.** [verified — no match in `app`, `config`, `routes`
or `database`] It is a **prospective** gateway, not one being removed.

Adding it requires a merchant-driven **notify → wait 24h → execute** loop: new schema
(`renewal_notified_at`, or a `redemptions` table) and a scheduled driver.

**The three `ChargeRecurring*` commands about to be deleted are the only working examples of that
shape in the codebase** [verified: `ChargeRecurringMyFatoorahCommand`,
`ChargeRecurringPaymobCommand`, `ChargeRecurringTapCommand`].

So the cleanup deletes the only reference implementation of the pattern a future PhonePe
integration will need. **Keep a copy** — in this document, in a branch, or in the PR description
— before the commands go.
