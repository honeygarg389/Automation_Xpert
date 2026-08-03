# CLAUDE.md — AutomationXpert

Project context for Claude Code. Read this at the start of every session.

## What this is

AutomationXpert is a multi-tenant WhatsApp/omnichannel marketing-automation SaaS built on the
WhatsMine codebase. It is being extended into a white-label platform with a partner reseller
tier, a generic add-on/entitlement layer, and a Smart QR module.

## Stack (do not change without asking)

- Laravel 12, PHP 8.2+ (dev machine runs 8.5)
- MySQL 8+ (dev machine runs 9.3)
- Inertia.js v2 + React 19 + Vite + Tailwind CSS 3
- Lucide icons, Recharts, Sonner — **do not install alternative chart/icon/toast libraries**
- Queues: database driver (job batches + failed jobs already configured)
- Realtime: Laravel Reverb / Pusher
- Auth: Sanctum (web + API tokens), separate admin guard with DB-backed RBAC
- Exports: Dompdf, ZipArchive, CSV, storage abstraction — already present, reuse them

## Architecture rules — non-negotiable

1. **Modular monolith.** New domains go in `app/Modules/{Domain}` with their own
   `Http/Controllers`, `Models`, `Services`, `Actions`, `Jobs`, `Listeners`, `Policies`,
   `routes/`, `database/migrations/`. Modules self-register routes and migrations.
   Never add a new domain as a loose pile of files in `app/Http/Controllers`.

2. **Tenant hierarchy.**
   `Platform Owner → Partner → Client (organisation) → Workspace → Users`
   - `workspace_id` is the operational tenant boundary for **all** customer-owned data.
   - `client_id` is the parent organisation only.
   - `partner_id` lives on `clients` and is **derived** through that relationship.
     Do not add `partner_id` to every table. Denormalize it only onto aggregate/billing
     tables where a partner-level query would otherwise require an expensive join,
     and document each instance.
   - Platform-owned (direct) customers have `partner_id = null`. This must keep working.

3. **Isolation is enforced, not conventional.** Customer-owned models use the
   `BelongsToWorkspace` trait + global scope.

   **Bypassing requires `withoutWorkspaceScope('reason: …')` with a REQUIRED reason
   argument** — not a bare `withoutGlobalScope()`. A rule that depends on remembering to
   write a comment is not a rule; the reason must be enforced by the signature. A CI guard
   greps for **both** spellings (`withoutWorkspaceScope` and the native
   `withoutGlobalScope`) so no bypass can be added without appearing in the inventory.

   **Isolation tests:** the central `PartnerIsolationTest` + `MultiTenantScopingTest` cover
   the boundary, backed by a shared reusable assertion trait. A per-module isolation test is
   written **when that module is next touched** — not all ten up front. New modules ship one
   from birth.

   A CI guard test fails the build if any model whose table has a `workspace_id` column
   lacks the `BelongsToWorkspace` trait, so the classification cannot go stale.

4. **Generic billing.** No feature-specific product/price tables. Everything sellable goes
   through the add-on catalog + entitlement resolver. Feature code asks an entitlement
   facade, never `plans.limits` directly.

5. **Dominant vs additive entitlements.** Full software packages are **not** summed — the
   highest eligible package wins. Only explicit packs/credits are additive.

6. **Partner entitlement is a ceiling.** A partner's resold plan can never grant a customer
   more than the partner's own entitlement. The resolver intersects
   partner entitlement ∩ customer plan. Always.

7. **Event-driven first.** Plan, subscription, workspace and partner changes dispatch queued
   reconciliation immediately. Scheduled commands are a safety net, never the primary path.

8. **Backward compatibility.** Existing plans, checkout, subscriptions, gateways, the WhatsApp
   webhook flow, and existing reports must keep working. Migrations are additive.
   No destructive changes to existing tables without an explicit plan and rollback path.

9. **Secure and auditable.** Manual overrides require permission + reason + dates + audit log
   via the existing `AuditLogService`. Jobs are idempotent, retryable, and tenant-safe.
   Never log credentials, provider tokens, or message contents.

## Existing patterns to reuse (do not reinvent)

- Driver/registry pattern: `SmsDriverInterface`, `SocialNetworkInterface`,
  `LlmProviderInterface`, `BillingGatewayRegistry`. Anything pluggable follows this shape.
- Third-party credentials use Eloquent `encrypted:array` casts. Always.
- Webhook signature verification: `hash_hmac` + `hash_equals` (timing-safe).
- Plan limits: `plans.limits` JSON + workspace usage meters.
- WhatsApp channel target: `ChannelAccount` scoped by `workspace_id`,
  `channel = whatsapp`, `status = active`.
- Inbound messages are already queued and idempotent; the `MessageReceived` event fires
  after contact/conversation processing. **Never build a parallel webhook flow.**
- UI: reuse existing layouts, cards, tables, tabs, modals, filters, badges, buttons, form
  fields, typography, spacing, radii, shadows, colour tokens, loading/empty/validation states.

## Commands

```bash
php artisan test                      # full suite
php artisan test --filter=<Name>      # single test
./vendor/bin/phpstan analyse          # must pass at level 6
./vendor/bin/pint --dirty             # CHANGED FILES ONLY — see below
npm run lint                          # JS/JSX lint
npx vitest run                        # React tests
php artisan migrate --pretend         # inspect SQL before running
```

Run tests + PHPStan + Pint before declaring any task complete.

**Pint is scoped to changed files only (`--dirty`). Never run it repo-wide** — the codebase
has never been Pint-formatted, so a full run produces a ~694-file reformat diff that buries
every real review.

**Tests run against `whatsmine_test`, never the working database.** `phpunit.xml` pins
`DB_CONNECTION=mysql` and `DB_DATABASE=whatsmine_test`; `tests/bootstrap.php` aborts the run
if the resolved schema name does not end in `_test`, and `Tests\TestCase::setUp()` re-checks
the booted config. The suite uses `RefreshDatabase` (`migrate:fresh`) in 79 of 81 files, so
an unguarded run drops every table. MySQL is required — SQLite cannot reproduce the JSON
columns and MySQL-specific migrations. Do not edit `.env` to change test targeting.

On this machine `vendor/bin/*` shims are not executable (symlinks were flattened by a file
copy); invoke the underlying script directly, e.g.
`php -d memory_limit=2G vendor/phpunit/phpunit/phpunit`. The suite needs ~1G; the 128M
default exhausts.

## Branching

`master` is the integration branch. **Fixes the live application needs must never sit behind
a long-lived feature branch.** Phase 0 is mostly fixes, not white-label work — the test-DB
isolation, the SSRF fix, the WorkspaceContext bug and the isolation scope are all needed
regardless of whether white-label ever ships.

| Branch | Contains | Merge |
|---|---|---|
| `fix/test-database-isolation` | phpunit.xml pinning, `tests/bootstrap.php` guardrail, TestCase check | immediately |
| `fix/webhook-ssrf-tests` | repairs to the SSRF test harness (the fix itself is already in `master`) | immediately |
| `fix/workspace-context` | `WorkspaceContext` + the 5 infrastructure call sites (ships the G-2 rate-limit fix) | soon — revenue-affecting |
| `feature/workspace-isolation-scope` | controller migration, `BelongsToWorkspace`, child-table denormalization | when green |
| `feature/partner-tier` | `partners` table, `clients.partner_id` | when green |
| `feature/white-label` | actual white-label surface — partner dashboard, branding, domains | much later |

Rules:

- One concern per branch. `fix/*` merges as soon as it is green; `feature/*` may live longer.
- Never park a bug fix on a feature branch because it was discovered there.
- Branch names use correct spelling — `label`, not `lable`.
- Every branch must leave the suite at or below the recorded baseline
  (see `docs/test-suite-baseline.md`). A new failing test name that is not in that document
  is a regression and blocks the merge.

## Working agreement

- **Plan before code.** For any non-trivial task, inspect the actual repository first and
  return: findings, exact files to create/modify, migration design, package proposals, and
  risks. Wait for approval before writing code.
- **One vertical slice per session.** Schema + models + factories + tests is one task.
  The service layer is another. UI is another.
- **Tests are part of the deliverable**, not a follow-up. Mirror the existing test patterns,
  including `MultiTenantScopingTest`.
- **Never invent codebase facts.** If you have not opened the file, say so and go read it.
- **Flag ambiguity instead of guessing**, especially on money, entitlements, and isolation.
- Prefer editing existing files over creating new ones. No new top-level directories without
  asking.

## Roadmap order (current)

1. Phase 0 — `BelongsToWorkspace` global scope + partner hierarchy + isolation test suite
2. Phase 1 — Add-on catalog + partner-aware entitlement resolver + materialized read model
3. Smart QR module (partner-aware, entitlement-gated from birth)
4. White-label surface — partner dashboard, branding, custom domains, hostname middleware
5. Partner billing — platform→partner subscriptions and usage slabs
6. E-commerce pack, Google Business Profile, n8n/Make connectors, Calendly
