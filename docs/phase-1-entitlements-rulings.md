# Phase 1 — rulings given before construction

Decisions made in conversation on **2026-08-09**, before any Phase 1 code existed.
Recorded here because a ruling that lives only in a chat log is indistinguishable, six weeks
later, from a decision nobody made.

The full Phase 1 design (catalog tables, resolver, slicing) is not yet written down — only
these three rulings and the slice-0 work already merged (`d485799`).

---

## R-1 — `partners.entitlement_mode` tri-state: APPROVED

**The problem.** CLAUDE.md rule 6 says a partner's entitlement is a ceiling, always: the
resolver intersects partner entitlement ∩ customer plan. But `partners` ships with no
entitlements of its own, so on day one every partner's ceiling is an empty set, and the
intersection is ambiguous:

- read "empty = grants nothing" → every partner's customers get zero of everything;
- read "empty = no ceiling configured" → rule 6 is silently not in force, and the first
  partner onboarded without grants resells unlimited.

**The ruling.** Neither. `partners.entitlement_mode` ∈ `unrestricted` | `ceiling`. Existing
rows default to `unrestricted`; switching to `ceiling` requires at least one grant, enforced at
write time.

> "Silent-unlimited is the exact failure class we've spent the day eliminating; an unconfigured
> ceiling must be a recorded decision, not an empty table."

That failure class, for the record, is the one BUG-023 belongs to: a limit that resolves to
`null` and is therefore read as unlimited, with nothing anywhere saying so.

## R-2 — `storage` vs `storage_gb`: migrate the key FAITHFULLY

The catalog migration copies the plan limit key across as **`storage`, in MB**, unchanged. It
does **not** rename it to `storage_gb` to match what `MediaService` and
`SubscriptionApiController` ask for.

A rename inside a migration silently changes every customer's quota **in both directions** —
enterprise customers from the hard-coded 1 GB fallback to their plan's real 500 GB, and any
customer over their true allowance into breach the moment it runs. The units differ too, so the
rename alone would be wrong: `storage: 5120` read as `storage_gb` grants 5120 GB.

Correcting the consumers is a separate change with its own data decision and its own
announcement. Recorded as **BUG-025** in `docs/found-bugs.md` with the full table.

## R-3 — `Plan::hasFeature()`: delete it in slice 3

Zero callers, and its `match` returns `false` for every input except `white_label` — the
`features` JSON column is never consulted. Anything written against it would silently deny.

> "Zero callers and returns false for everything is a trap that looks like a working API."

Delete rather than leave. Feature checks go through the entitlement facade.

---

## Still open — NOT ruled on

- The slice grouping for Phase 1 proper (proposed: 1 schema, 2 resolver canary, 3 facade,
  4 gauges, 5 partner ceiling, 6 purchase path, 7 read model, 8 presentation).
- **BUG-023's flip.** Report-only is shipped and logging to `storage/logs/entitlements.log`.
  Reading it and deciding whether to grandfather the existing self-serve cohort is a business
  decision with no technical default.
- `plans.white_label_enabled` versus the partner tier — two mechanisms claiming to answer
  "may this customer white-label". One has to stop being authoritative.
- `BillingGatewayInterface::createCheckout()` is typed to `Plan` across 15 implementations, so
  add-ons cannot be sold through the existing checkout without a signature change.
