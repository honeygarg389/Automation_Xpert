# Review collection — scope, and why Smart QR does not do it

**Recorded 2026-08-13, before either module existed.** Filed here rather than in Smart QR's
documentation deliberately: this is a constraint on **whoever builds review collection**, and
they will not be reading Smart QR's docs when they start.

---

## The ruling

**Review collection belongs to the existing Automation engine, driven from a WhatsApp
conversation. Smart QR's job ends at delivering the customer into WhatsApp.**

Smart QR **routes**. The automation **acts**. A scan puts a person into a WhatsApp
conversation; everything after that — asking for a review, timing the ask, following up,
recording the outcome — is automation work with an automation's tools.

## Why Smart QR has no review destination type

It was considered and refused. A `TYPE_REVIEW` destination in Smart QR would be a **second
place answering a question the Automation engine already answers**, and that is the
one-concept-two-places trap this codebase has produced repeatedly:

| Concept | Definition A | Definition B |
|---|---|---|
| workspace membership | `User::accessibleWorkspaces()` | `Workspace::isAccessibleBy()` |
| inbound dedup | `whatsapp_global` (controller) | `whatsapp_msg` (driver) |
| the set of plan limits | `PlanLimits.jsx` (16 keys) | `defaultLimits()` (2 keys) — BUG-027 |
| which subscription is in effect | `Client::activePlan()` | `User::effectiveSubscription()` — BUG-023 |
| the WhatsApp message meter | `whatsapp_messages` | `messages_whatsapp` — BUG-028 |

Every one of those looked reasonable when it was written. Each was found only after it had
produced a defect, and three of them were producing defects in production. A review flow living
partly in Smart QR and partly in Automation would join that list, and the failure would be the
familiar one: fixing the review logic in one place while the other keeps running.

So Smart QR ships **exactly two destination types — `whatsapp` and `url`** — and the handler is
shaped so a future type is a row and a handler rather than a schema change. That shape is for
types Smart QR should genuinely own, not a door left open for this one.

## Google Business Profile is out of scope here

Smart QR does not paste, store, or integrate a GBP review URL, and does not touch
`workspace_integration_connections`. **If review collection needs GBP, the review module
decides that** — including whether it needs the per-workspace integration table at all.

⚠️ There is an existing design constraint that applies the moment it does. From CLAUDE.md:

> **Per-workspace integrations need a SEPARATE `workspace_integration_connections` table** with
> encrypted per-workspace credentials. Do **not** extend `IntegrationConfig`, which is
> platform-global and admin-managed. Applies to Google Business Profile, Calendly, n8n.

That constraint is binding on the review module. It was not binding on Smart QR, because Smart
QR was never going to hold a GBP credential.

## What this means for whoever builds review collection

1. **Start from the Automation engine**, not from Smart QR. The conversation is the trigger.
2. **Do not add a destination type to Smart QR** to shortcut it. If a QR needs to start a review
   flow, it uses the `whatsapp` type with a prefilled message, and the automation recognises the
   conversation. That keeps one implementation.
3. **If GBP is needed**, build `workspace_integration_connections` as CLAUDE.md specifies —
   encrypted per-workspace credentials, not an extension of the platform-global
   `IntegrationConfig`.
4. **Entitlement-gate it through the facade**, as every module now must. Feature code never reads
   `plans.limits`.
