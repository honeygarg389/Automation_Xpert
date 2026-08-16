# Billing gateway cleanup — findings, rulings, and sequence

**Status: RECORDED, NOT STARTED.** This is its own track. Nothing here is scheduled, and none of
it is part of Smart QR or Phase 1. The purpose of this document is that the inspection behind it
survives the session that produced it.

**PROVENANCE.** Every claim marked ✅ was measured directly in this repository on 2026-08-13 —
files opened, greps run, the working database queried. Claims marked ⚖️ are owner rulings
recorded verbatim in intent. Nothing here is recalled from memory.

> ### ⚠️ A near-identical file exists on `feature/smart-qr`
>
> `docs/billing-gateway-cleanup.md` was already written on the `feature/smart-qr` branch
> (commits `150a058`, `4d194dc`) from a second-hand account of the same inspection, and marked
> its own claims as unverified. **This version is the superset and was measured directly.**
>
> Both branches therefore add the *same path* with *different content*. That is an add/add
> conflict, and it is deliberate: a loud conflict is the correct outcome here. The alternative —
> two differently-named documents on one topic — is the silent-divergence failure recorded in
> `CLAUDE.md` under the BUG-007 doc-merge incident, where `git merge-tree` exited 0 and produced
> a file with two contradictory sections.
>
> **When the branches meet: keep this version, discard the other.** Do not attempt a
> line-level merge of the two.
>
> ### ✅ RESOLVED 2026-08-16 — that is what happened, and this is the record of it
>
> The other version reached `master` on branch `docs/billing-findings` (not `feature/smart-qr`
> as predicted above — the file moved branches, the prediction was otherwise exact). It was
> **97 lines against this file's 329**, and it was discarded whole at the add/add conflict, by
> owner ruling.
>
> **What it claimed that this file does not:** that removing the eleven gateways forces a
> product decision on in-place plan changes which was *"a product decision **awaiting the
> owner**"*. That was the reason to discard it rather than merge it — the decision was not
> awaited, it was **made**, and it is recorded here as Owner ruling 2. A union of the two files
> would have said both things at once, which is the BUG-007 failure this note was written to
> avoid.
>
> **Checked before discarding, at sentence level rather than by heading:** all 38 of its
> sentences were compared against this file's 127. Every substantive claim it made appears here,
> measured, usually with a file:line reference it lacked. The only content unique to it was its
> own statement that its claims were unverified — which is precisely why it is the superseded
> one. **Nothing was lost.**

---

## Scope

**Eleven gateways to remove, not ten.** ✅ Thirteen classes implement `BillingGatewayInterface`
in `app/Services/Billing/`. The keepers are **Razorpay** and **Cashfree**, so eleven go:

```
stripe   paypal   paddle   tap        paystack   xendit   paymob
myfatoorah   mollie   square   mercadopago
```

**Removal is code-only.** ✅ Measured against the working database (`whatsmine`):

| Table | Rows |
|---|---|
| `plans` | 0 |
| `subscriptions` | 0 |
| `payment_transactions` | 0 |
| `payment_gateway_configs` | **1** — `paddle` |

The single `payment_gateway_configs` row is inert: `enabled = false`, `test_mode = true`, and
all three credential keys (`publishable_key`, `secret_key`, `webhook_secret`) are **empty
strings**. The 424-byte column is the encrypted envelope around empty values, not a secret.

No data migration. No orphaned billing data. No customer impact. Delete that one row and the
data side is done.

**There is no shared base class, trait, or cross-gateway reference inside the eleven.** ✅
Grepped for `extends`, cross-class `Gateway::` references, and inter-file
`use App\Services\Billing\…` imports across all thirteen files: **zero hits**. Each gateway
independently duplicates its own HTTP client, status mapper and invoice hook — **6,797 lines for
conceptually one job**.

The three things the keepers share all survive the removal:

- `App\Contracts\BillingGatewayInterface` — outside the removal set
- `App\Services\Billing\InvoiceService` — 47 lines, same directory, not a gateway
- `App\Services\WebhookIdempotencyService` — outside the directory entirely

---

## ⚠️ The one thing most likely to go wrong on that PR

**`resources/js/Pages/client/Checkout/Sdk.jsx`.** ✅

Two gateways return a `['checkout' => …]` envelope instead of a `['url' => …]`, and that
envelope is what routes to this page: **Cashfree** (`CashfreeGateway.php:135`) and **Paddle**
(`PaddleGateway.php:80`).

Delete Paddle, and the page reads as Paddle-specific leftovers. It is not — **Cashfree is the
only surviving gateway that needs it**, and `SDK_SRC` at `Sdk.jsx:15` already carries a
`cashfree` entry and nothing else, which makes the page look half-dead and invites removal.

**Deleting it breaks Cashfree checkout with no compile error and no failing test.** Put this in
the PR description, not in a code comment nobody reads during a five-thousand-line deletion.

---

## The plan-form field question — the proposed fix is the wrong one

The request was to add `razorpay_*_id` fields to the plan create/edit form, replacing the Stripe
ones. **That is the wrong fix**, because neither keeper has any use for a stored gateway-side
price id. ✅

| Gateway | How it prices a subscription |
|---|---|
| **Razorpay** | `POST /v1/plans` with `item.amount = $priceCents`, then `POST /v1/subscriptions` against the plan id it just got back. The id is never persisted. (`RazorpayGateway.php:76-119`) |
| **Cashfree** | Sends `plan_details` **inline** in the subscription-create call. No separate plan object exists at all. (`CashfreeGateway.php:89-122`) |

Both derive the amount from `Plan::priceCentsForCycle()`. A `razorpay_monthly_id` column would
be collected, saved, and never read by anything.

**Only two of the thirteen read the existing columns, and they differ:** ✅

| Gateway | Reads them | Behaviour when null |
|---|---|---|
| **Paddle** | `PaddleGateway.php:59`, `:378` | **Hard requirement** — returns an error, checkout dies. Paddle's `/transactions` endpoint refuses inline prices. |
| **Stripe** | `StripeGateway.php:75`, `:540` | **Optional** — falls back to `prices->create()` ad-hoc. The columns are a convenience. |
| other eleven | — | never read them |

So all four columns on `plans` exist for **one gateway that genuinely needs them (Paddle) and
one that does not (Stripe)** — and both are on the removal list.

**The right fix is the opposite direction: drop the dead Stripe/Paddle fields once Paddle is
gone.** `stripe_monthly_id`, `stripe_yearly_id`, `paddle_monthly_id`, `paddle_yearly_id` become
unreferenced the moment those two classes are deleted, which turns the migration into a pure
drop with no reader to update.

Call sites that disappear with them: `Plan::$fillable` (`Plan.php:57-60`),
`PlanController.php:80-81, 182-183, 214-215`, `PlanModal.jsx:14-15`, `PlanForm.jsx:151-158`.

### Recorded for later: Razorpay creates a new plan object on every checkout attempt

✅ Because `createCheckout()` calls `POST /v1/plans` unconditionally, ten customers subscribing
to the same local plan produce **ten Razorpay-side plan objects**, and every abandoned checkout
leaves one behind.

**This is not a bug** — it is how the API is being used, and it is correct. But it will clutter
the Razorpay dashboard as volume grows, and it is **the only reason a price-id column could ever
be justified later: as a cache, never as a requirement.** If that day comes, the column stores
"the Razorpay plan we already made for this (plan, cycle)", not "the price the admin typed in".

### ⚠️ `add_on_prices` inherited the same anti-pattern, deliberately

✅ `app/Modules/Entitlements/database/migrations/2026_08_10_100000_create_add_on_catalog_tables.php:141-142`
gives `add_on_prices` a `stripe_price_id` and a `paddle_price_id`, under a comment stating the
columns *"deliberately mirror the columns `plans` already carries rather than inventing a second
pricing vocabulary."*

Stated plainly: **Phase 1 copied the anti-pattern from the table it was built to replace.**
`CLAUDE.md` rule 4 says everything sellable goes through the add-on catalog and forbids
feature-specific product/price tables — and the add-on catalog's own price table hard-codes two
gateway names, one of which is being deleted and neither of which this business uses.

**It is free to fix today and expensive after slice 6.** ✅ The columns have **no reader, no
writer, and no factory support** (`AddOnPriceFactory` sets neither). They are dead weight right
now because the purchase path that would populate them is itself blocked on BUG-032. Fix the
shape while nothing depends on it.

### The generic replacement shape — recorded, not to be built

One row per (priceable thing, gateway, cycle), polymorphic over `Plan` and `AddOnPrice`:

```
gateway_price_refs
  id
  priceable_type / priceable_id   -- Plan or AddOnPrice
  gateway                         -- 'razorpay' | 'cashfree' | …
  billing_cycle                   -- 'month' | 'year' | 'one_time'
  external_id
  unique(priceable_type, priceable_id, gateway, billing_cycle)
```

Gateways that do not pre-register prices simply have no rows — which is both keepers, today.
**Do not build this now.** It is recorded so that the next person who reaches for a
`<gateway>_price_id` column has somewhere better to go.

---

## What removal closes, and what it does not

**It CLOSES BUG-034 by deletion.** ✅ Paddle and PayPal are the only two gateways that never
release the idempotency lock on handler failure. Both are on the removal list. Both keepers
release correctly — `RazorpayGateway.php:172-175`, `CashfreeGateway.php:176-179`. Once the
eleven are gone there is no code left to fix.

> ⚠️ **BUG-034 and BUG-033 are not recorded on `master`.** They exist only on
> `feature/smart-qr` (commit `4cccb6d`); `master`'s `found-bugs.md` ends at BUG-032. ✅
>
> The "closes by deletion" correction therefore **could not be applied to BUG-034's entry from
> this branch** — writing a rival BUG-034 section on a master-derived branch is precisely how
> two contradictory sections end up in one file with no conflict marker.
>
> **Owed action:** when `feature/smart-qr` merges, edit BUG-034 in place to say it closes by
> deletion rather than reading as outstanding work. This paragraph is the reminder.

**It does NOTHING for BUG-032.** ✅ `refund()` calls the gateway API and updates
`payment_transactions` (`refunded_at`, `refunded_cents`, `status`) but touches `Subscription` in
**neither keeper** — `RazorpayGateway.php:351-382`, `CashfreeGateway.php:389-424`. Removing
eleven gateways removes eleven copies of the defect and leaves the two that matter. It remains a
hard prerequisite for Phase 1 slice 6. BUG-032's entry has been updated on this branch to say so.

---

## What the keepers actually are

Assessed because these two matter more than the eleven. ✅

| | Razorpay | Cashfree |
|---|---|---|
| Refund implemented | yes | yes |
| BUG-032 (refund revokes entitlement) | ❌ affected | ❌ affected |
| Releases idempotency lock on failure | ✅ yes | ✅ yes |
| Renewal webhooks (not just first payment) | ✅ yes | ✅ yes |
| In-place plan change | ❌ no | ❌ no |

Renewals are the strongest part of what is being kept. Both distinguish a first charge from a
renewal by checking for a prior `paid` transaction on the same subscription, dispatch
`SubscriptionRenewed` only on genuine renewals, and re-derive `renews_at`. Both verify webhook
signatures timing-safely and both refuse unsigned webhooks in production. Cashfree additionally
falls back to an API `fetchTags()` call when the webhook payload omits `subscription_tags`.

### ⚠️ Both keepers cap the subscription at a finite number of cycles

✅ Neither gateway supports a genuinely open-ended subscription, so both fake one:

| Cycle | Cap | Runs out after |
|---|---|---|
| monthly | 120 | 10 years |
| yearly | **10** | 10 years |

`RazorpayGateway.php:73`, `CashfreeGateway.php:86`. The subscription simply stops at the cap and
**neither gateway warns**. Far away, but it is a silent expiry rather than a failure, which is
the kind that is discovered by a customer rather than by monitoring.

---

## ⚖️ Owner ruling 1 — PhonePe is SKIPPED, not deferred

**Not "later". Skipped, with the reasoning recorded so it is not re-litigated.**

PhonePe Autopay is real and documented (`/subscriptions/v2/*`: Subscription Setup, Notify
Redemption, Execute Redemption, Subscription Status, refunds, webhooks). The problem is not
capability, it is fit:

- **Cashfree already provides UPI recurring** for the same audience in the same currency —
  `CashfreeGateway.php:109` lists `upi` among its mandate payment methods.
- PhonePe does **not** bill on a schedule for you. The merchant drives every cycle:
  check status is ACTIVE → **Notify** → **wait ≥24h** (mandatory) → **Execute**.
- Retries are capped at one attempt plus three, inside 48 hours, and permitted **only** during
  two non-peak IST windows (9:31 PM–9:59 AM, 1:01 PM–4:59 PM).
- That needs new schema (`renewal_notified_at`, or a `subscription_redemptions` table — the
  `Subscription` model has nowhere to record "notified at") and **the platform's first scheduled
  billing driver**.

Disproportionate for a capability the platform already holds twice.

**Revisit only for commercial reasons** — brand recognition at checkout — **never technical
ones.** The technical answer is settled.

**Final gateway set: Razorpay + Cashfree.**

### The shape PhonePe would have needed already exists, and is about to be deleted

✅ `ChargeRecurringTapCommand`, `ChargeRecurringPaymobCommand` and
`ChargeRecurringMyFatoorahCommand` — with their three registrations in `routes/console.php:78-96`
— are **the only working examples of a merchant-driven billing loop in this codebase**. They
exist for exactly the reason PhonePe would: gateways that do not self-bill.

They go with the removal. **Do not resurrect them from git later** — they would come back as
three copies of a pattern rather than one. But the record should say they existed, so that
whoever eventually needs the shape knows it was solved here once before.

---

## ⚖️ Owner ruling 2 — in-place plan change is out of scope, deliberately

✅ Removing the eleven removes **every gateway capable of an in-place plan change**. Stripe,
Mollie, Square and MercadoPago implement a real `changePlan()`. Both keepers return a hard error
— `RazorpayGateway.php:345-349`, `CashfreeGateway.php:383-387` — because both require a fresh
mandate authorization.

This is **a product decision, not a side effect of a cleanup**, and it is accepted. The
supported upgrade paths are:

1. Let the current term run out and change at renewal.
2. Cancel and re-subscribe.
3. For special cases, **the admin assigns the plan directly**.

> ### ⚠️ Path 3 is BUG-031, and this ruling changes its severity
>
> `Admin\ClientController::assignPlan()` writes a `client_subscriptions` row and does **not**
> cancel the gateway subscription: two active subscriptions, two plans, the gateway still
> charging for the old one.
>
> It was recorded as an administrative edge case. **It is now the owner's supported route for
> special-request upgrades** — the ruling above promotes it from something that happens rarely to
> something the business intends to do. Its priority has been raised on this branch and the
> reason recorded in the finding itself.
>
> **Do not fix it now.** The three candidate behaviours in BUG-031 differ in who loses money and
> the choice is still commercial.
>
> **Numbering note:** this defect was referred to as "BUG-035" when the ruling was given. There
> is no BUG-035 — `master` ends at BUG-032, and this is **BUG-031**, recorded 2026-08-12. ✅

---

## The recorded sequence — a plan, not work

Order matters, because each step makes the next one smaller:

1. **Fix refunds on the two keepers (BUG-032).** Do this *first*, on the two gateways that
   survive, rather than on thirteen. It is Phase 1 slice 6's blocker, so it has to happen
   regardless of whether the cleanup ever runs.
2. **Remove the eleven.** Code-only. Closes BUG-034 by deletion. Watch `Sdk.jsx`.
3. **Drop the dead price columns.** By this point `stripe_*_id` and `paddle_*_id` on `plans`,
   and `stripe_price_id` / `paddle_price_id` on `add_on_prices`, have no reader and no writer —
   a **pure drop** with no code to update alongside it.

Doing 3 before 2 means editing readers that are about to be deleted. Doing 1 after 2 is fine but
delays slice 6 for no reason.

### Full surface of step 2, for whoever does it

✅ Measured, not estimated:

| Surface | Detail |
|---|---|
| Gateway classes | 11 files, ~5,530 LOC (Stripe alone is 712) |
| `BillingGatewayRegistry` | DB branch L37-170, config branch L178-302, `$labels` L334-348 — ~300 of 360 lines |
| Routes | `routes/web.php:83-95` — 11 of 13 webhook routes |
| `WebhookController` | 11 handler methods |
| `CheckoutController` | the `Rule::in([…])` gateway list, L27 |
| `PaymentGatewayConfigController` | `GATEWAYS` const L17, label map L21-33 |
| `Index.jsx` | `GATEWAY_HINTS` entries from L147 |
| `config/billing.php` | 11 of 13 blocks (L5-29, L45-119) |
| `PaymentGatewayConfigSeeder` | seeds `['stripe','paypal','paddle']` — **all three are being removed**, so this needs rewriting, not trimming |
| Console commands | 3 `ChargeRecurring*` files |
| Scheduler | `routes/console.php:78-96` — 3 registrations |
| Tests | `WebhookSignatureTest.php:66-72` (Stripe-only) and `PrivilegedActionPermissionTest.php:143` (`'gateway' => 'stripe'` fixture) — **retarget to razorpay, do not delete** |
| Docs | 5 files carry gateway tables that go stale |
| Data | delete the one inert `paddle` row |
