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
./vendor/bin/phpstan analyse          # NOT clean — see below
./vendor/bin/pint --dirty             # CHANGED FILES ONLY — see below
npm run lint                          # JS/JSX lint
npx vitest run                        # React tests
php artisan migrate --pretend         # inspect SQL before running
```

Run tests + PHPStan + Pint before declaring any task complete.

**PHPStan does NOT pass at level 6 and never has.** `master` reports **733 errors**, on top of
a **1,213-entry `phpstan-baseline.neon`**. Both were inherited from the initial WhatsMine
import (`4ec7e3e`); `phpstan.neon` has never been modified since.

So a repo-wide run tells you nothing, and treating it as a gate trains you to ignore its
output — which is exactly how the one error that *is* yours gets lost among 733 that are not.

**Run it on the files you changed and diff the count against `master`:**

```bash
php -d memory_limit=2G vendor/phpstan/phpstan/phpstan.phar analyse <your files> --level=6
```

That is how the SEC-006 `TransientToken` fatal was caught — a real bug in new code, visible
only because the run was scoped to five files. See BUG-017 in `docs/found-bugs.md`.

**Pint is scoped to changed files only (`--dirty`). Never run it repo-wide** — the codebase
has never been Pint-formatted, so a full run produces a ~694-file reformat diff that buries
every real review.

⚠️ **A running `queue:listen` is not evidence that jobs are being processed.** Measured
2026-08-19: `queue:listen --tries=1 --timeout=0` was up and visible in `ps`, and had consumed
**nothing since 2026-08-15**. Three days of `GenerateQrExportJob` rows sat at `attempts=0`,
`reserved_at=NULL` — never picked up, never failed, so `failed_jobs` was empty and every
dashboard looked clean.

This is not a Smart QR problem. **It silently swallows every queued job in the application** —
scan recording, automation runs, reconciliation, anything dispatched. The owner's symptom was
"the QR export does nothing"; the actual blast radius was the whole queue.

Check the queue itself, not the process list:

```bash
php artisan tinker --execute='foreach (DB::table("jobs")->get() as $j) {
  printf("%s queue=%s attempts=%s reserved=%s\n", $j->id, $j->queue, $j->attempts,
    $j->reserved_at ? "yes" : "NEVER"); }'
```

**`reserved=NEVER` on a row older than a few seconds means no worker is really consuming.**
`php artisan queue:work --once` in the foreground processes one job and tells you whether the
queue is healthy — it drained four exports at ~130 ms each with zero failures, which is how the
listener was proved to be the broken part rather than the jobs.

⚠️ **A worker can be running, consuming jobs, and STILL be executing code you deleted.** A
long-running `php artisan queue:work` boots the framework ONCE and holds every job class
definition in memory for the life of the process. Editing a job class — or anything it calls —
on disk has no effect on it. It keeps running the old code, silently, with no error and no
warning, until the process is restarted.

**Twice in one session, with the same symptom both times: "the code change didn't take
effect."**

| # | What was changed | What the stale worker did |
|---|---|---|
| 1 | `track()` added to `GenerateQrExportJob` | built correct archives, never called `track()` — the method did not exist in its memory, so rows stayed `queued` while the files appeared |
| 2 | export filename scheme | kept emitting the old `Ymd-His-<hex>` names for **51 minutes** after the change; the worker had started **two days earlier** |

A third worker incident in the same session was a DIFFERENT failure — a worker reported as
running that had already exited, consuming nothing. That one is the `reserved=NEVER` case above.
The two hazards are easy to confuse because both present as "I started a worker and my thing
didn't happen", and they have opposite fixes: one needs a restart, the other needs a worker at
all. Check both.

`queue:work --once` and `queue:listen` do NOT have this problem: both re-boot per job, so they
always pick up current code. A persistent `queue:work` does. **The fix is always the same —
restart the worker after any change to a job class.** Add it to the deploy step; a rule that
depends on remembering is not a rule.

⚠️ **Diagnosing it means comparing the process START TIME against the code-change timestamp**, not
confirming that a worker exists:

```bash
ps -eo pid,lstart,command | grep '[q]ueue:work'      # when did it start?
stat -f '%Sm' -t '%Y-%m-%d %H:%M:%S' path/to/Job.php  # when did the code change?
```

Any worker started before the file changed is running the previous version. **"A worker is
running" and "a worker is running your code" are different claims**, and only the second one
matters. Note this is the mirror of the `reserved=NEVER` check above: that one catches a worker
that is up but consuming nothing, this one catches a worker that is up, consuming happily, and
wrong. Both look healthy in `ps`.

⚠️ **Rule out the cheap explanations first, and record that you did.** In incident 2 the causes
that get blamed reflexively were all measured and excluded before the worker was suspected: the
code on disk was already correct, `bootstrap/cache/config.php` and `routes-v7.php` were absent,
CLI opcache was off, and the `jobs` table was empty (so no pre-change job was waiting to run).
That left exactly one explanation, and re-running the export after a restart confirmed it. Do not
re-apply a change that is already on disk — diagnose why the disk is being ignored.

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

## Verification must halt, not report

⚠️ **A branch switch must be followed by an assertion that EXITS NON-ZERO on mismatch, in the
same command** — not a check whose output is read and acted on.

```bash
git checkout -q -b <branch> master
[ "$(git rev-parse --abbrev-ref HEAD)" = "<branch>" ] || { echo "WRONG BRANCH"; exit 1; }
```

**This rule is owed a mechanism because the prose version demonstrably failed.** Recorded
2026-08-12 as "verify the result of a checkout, not that the command returned". On 2026-08-13 the
same failure recurred **with the check run** — the output was printed, not acted on, and an entire
session's commits landed on the wrong branch. On 2026-08-14 the hard-stop version met the same
class of failure on first contact (`fatal: a branch named '…' already exists`) and prevented every
subsequent operation: no cherry-pick, no reset, no push.

The general form, which is the same principle already behind the `withoutWorkspaceScope('reason:
…')` signature and the CI guards: **a step that must be remembered is not a control.** If a
verification's failure mode is "the human reads the output and chooses to stop", it will
eventually be read and not acted on. Make the failure abort the process.

⚠️ **This belongs beside the checkout rule at `253936f` (on `feature/smart-qr`, unmerged).** When
both land on master they should be reconciled into one place rather than left as two entries
saying adjacent things — which is the duplicate-BUG-007 shape in prose.

## Working agreement

- **Plan before code.** For any non-trivial task, inspect the actual repository first and
  return: findings, exact files to create/modify, migration design, package proposals, and
  risks. Wait for approval before writing code.
- **One vertical slice per session.** Schema + models + factories + tests is one task.
  The service layer is another. UI is another.
- **Tests are part of the deliverable**, not a follow-up. Mirror the existing test patterns,
  including `MultiTenantScopingTest`.

- **Every "is blocked" test needs a positive control.** A test asserting that access is denied
  proves nothing on its own — a 403 is equally consistent with the endpoint rejecting
  *everyone*, and a test that 404s at route binding never reaches the check at all. Pair each
  negative assertion with one proving the **same route, same verb, same user type** succeeds
  for the legitimate case.

  This convention exists because it caught two dead assertions in a single test
  (`workspace_a_cannot_delete_workspace_b_contact`): the request 404'd on a wrong route key so
  authorization was never exercised, and the follow-up `assertDatabaseHas` could not fail
  because the model soft-deletes. Both had been passing as "proof" of protection for as long
  as the test existed.

  Related traps to check for:
  - **Route keys** — `getRouteKeyName()` is `uuid` on several models. Passing `->id` 404s
    before any authorization runs.
  - **Soft deletes** — `assertDatabaseHas` cannot prove a delete was prevented on a
    soft-deleting model. Use `assertNotSoftDeleted` / `assertSoftDeleted`.
  - **Auth style** — `actingAs($user, 'sanctum')` does not populate `currentAccessToken()`, so
    ability-gated API routes 401 before validation. Issue a real token.
- **Never invent codebase facts.** If you have not opened the file, say so and go read it.

- **When a fix reveals a second bug the first was masking, fix both in the same commit** and
  pin the second with its own regression test. Shipping the first alone converts a dormant
  fault into a live one.

  The example: the `ai-runs` rate limiter resolved the workspace from a non-existent
  attribute, so it always fell back to the client IP. `Workspace::find(<ip>)` returned null —
  which meant Laravel skipped the eager load on the next line, and that eager load
  (`with('client.activePlan')`) was itself invalid, because `activePlan()` is a method, not a
  relation. Fixing only the resolution would have produced a `RelationNotFoundException` on
  the first authenticated request. Each bug hid the other.

  Ask, whenever a fix makes a previously-dead code path live: **what has never actually
  executed before, and is it correct?**

- **Before hardening a check, grep for OTHER definitions of the same concept.** This codebase
  repeatedly implements one idea in two places, and fixing one of them closes nothing while
  looking correct.

  Confirmed instances:
  - `User::accessibleWorkspaces()` and `Workspace::isAccessibleBy()` both define workspace
    membership. Filtering only the first left the second — used by `WorkspacePolicy::view`,
    and therefore by workspace switching — as a complete bypass.
  - Inbound WhatsApp webhooks deduplicate in two layers, `whatsapp_global` in the controller
    and `whatsapp_msg` in the driver. A test asserting a single row count conflated them.

  Both were caught only because a test failed for an unexpected reason. Search for the
  concept, not the symbol you already have: sibling methods on the related model, the policy,
  the middleware, and any service that answers the same question.
- **A signature change is not done when the public callers compile.** Grepping the public API
  finds the callers you were thinking about. It does not find the ones inside the class you
  just edited, and it does not find tests.

  Concretely, changing `OnboardingService::markStep()`'s parameters broke callers in **three
  places, each found by a different mechanism**:

  | Broken caller | Found by |
  |---|---|
  | 4 controllers / middleware | grep — the ones I was looking for |
  | 2 **internal** callers inside `OnboardingService` itself (`complete()` → `markStep()`, and `getProgress()` → `complete()`) | runtime `TypeError` in the new tests |
  | 2 existing test files (`OnboardingMilestonesTest`, `BillingFixesTest`) | **the full-suite run, and nothing else** |

  The internal pair mattered most: they were `TypeError`s on the dashboard and on *every*
  client page via `HandleInertiaRequests`, and one of them was a `@deprecated` shim nobody
  would think to check.

  So: after any signature change, grep for the method name **without** a `$this->`/`self::`
  qualifier too, and **run the whole suite** — not the module's tests. A red suite here is the
  change working as intended; a green one after only grepping means you have not looked yet.
- **A clean merge is not evidence that a document is coherent.** Git conflicts on overlapping
  *lines*, not contradictory *meaning*. Two branches that append to different regions of the
  same file merge silently — and append-structured files (`docs/found-bugs.md`, roadmaps,
  changelogs) are exactly the shape that produces contradictions no conflict marker will
  flag.

  This happened: `docs/bug-007-scope` carried an authoritative four-defect BUG-007 and
  `fix/places-key-in-url` carried a narrower superseded one. `git merge-tree` exited **0** and
  produced a file with **two contradictory BUG-007 sections** and no warning. It was caught
  only because the merge was dry-run first and then run with `--no-commit`.

  So: **dry-run every doc merge** (`git merge-tree --write-tree`), and when two branches both
  touch a tracked document, **merge with `--no-commit` and inspect** — a clean exit means
  "no overlapping lines", never "the result makes sense". Verify the structure afterwards
  (`grep -c '^## BUG-'` and friends) rather than trusting the absence of conflict markers.

  Related trap: `git commit` during a merge commits the **index**. Editing a file to resolve
  something *after* the merge staged it, without re-running `git add`, commits the unresolved
  version while the working tree looks correct. Check the committed tree
  (`git show <ref>:<path>`), not the working file.
- **A stash-check you did not confirm reverted is not a stash-check.** Three incidents, and
  the third was the worst kind: it made a verification step *pass* when it should have failed.

  | # | What happened | Cost |
  |---|---|---|
  | 1 | `git checkout -- app/` wiped an uncommitted fix (gemini branch) | fix lost, rewritten |
  | 2 | same, on the places branch | fix lost, rewritten |
  | 3 | `git checkout --` **errored** on an untracked test file, the error scrolled past, and the previous check's mutation was still in the file | a stash-check **passed that should have failed**, and was nearly reported as green |

  Incidents 1 and 2 cost time. Incident 3 cost *truth* — the entire value of a stash-check is
  that its failure is informative, so one that silently cannot fail is worse than not running
  it.

  The procedure, in order:

  1. **Commit first.** Not just the production fix — the **tests too**. `git checkout --` on a
     path git has never seen exits non-zero and changes nothing, and a new test file is
     untracked by definition.
  2. **Confirm the restore actually happened** before the next check: `git status --porcelain`
     must be empty, and for a targeted revert, grep back the line you removed.
  3. **Never chain stash-checks without a clean tree between them.** Check N's mutation
     surviving into check N+1 is how a green result becomes meaningless.
  4. `git checkout` cannot restore an untracked file at all. There is no undo.

  **The same rule applies to `git checkout <branch>`, and it cost a whole session's work
  being committed to the wrong branch.** Opening a Smart QR session with:

  ```
  git checkout -q feature/smart-qr 2>/dev/null || git checkout -q -b feature/smart-qr master
  → fatal: a branch named 'feature/smart-qr' already exists
  ```

  The branch existed at master's tip, the `||` fallback swallowed the failure, and **every
  commit for the rest of the session went to the branch that happened to be checked out
  already**. It surfaced only when a push of `feature/smart-qr` uploaded a branch with 0
  commits and 0 files, hours later.

  So: **verify the RESULT of a checkout, not that the command returned.** `git rev-parse
  --abbrev-ref HEAD` after switching, before the first commit. And never `2>/dev/null` a
  git command whose failure changes which branch you are on — suppressing the error is
  what made a loud failure silent.

- **A stash-check whose MUTATION cannot be shown to have landed proves nothing.** This is the
  unverified-revert trap in the opposite direction, and it is worse, because it produces a
  *green* result that reads as "the code is correct" when it means "nothing was tested".

  Measured: three refusal-state mutations in Smart QR slice 4 all reported **OK (24 tests)**.
  The conclusion on offer was "these tests do not discriminate" — and it was wrong. The Python
  edits had been piped through a bash helper function, the escaping mangled the search strings,
  and `str.replace()` silently did nothing. Re-run with the pattern asserted, every one of the
  six mutations failed exactly the tests it should.

  So: **every mutation asserts its own pattern before writing**, e.g.

      assert old in s, 'PATTERN NOT FOUND'

  and prints confirmation. `str.replace()` and `sed` both fail silently on a missed pattern;
  neither tells you the file is unchanged. Confirm the mutation applied, and confirm the
  restore afterwards — both halves, every time.

- **Assert the STORED ROW, not the dispatched payload.** A test that checks what a job was
  *called with* passes while the job writes nothing.

  Smart QR slice 4: `SmartQrScanEvent::$fillable` still listed slice 1's two columns, so
  `create()` **silently discarded** `ip_hash`, `ua_hash`, `is_bot`, `referer_host` and
  `is_unique` — no error, no exception, rows written with database defaults. Bot flags read
  false, referers null, and every repeat scan counted as unique.

  `Queue::assertPushed(fn ($job) => $job->isBot === true)` **would have passed**: the argument
  was correct all the way to `create()`. Only reading the row back caught it. Mass assignment
  fails quietly by design, so the payload and the persisted row are two different claims — test
  the second.

- **Bytes present is not pixels drawn.** The third instance of the same trap, and the one that
  reached a printed artefact. Assert what the user perceives, not what the file contains.

  Smart QR slice 8 found that endroid's `SvgWriter` silently discards a label, and appended the
  serial band by hand. `appendSerialToSvg()` matched the opening tag with a pattern that stopped
  at `height="…"` — and endroid emits `viewBox` **after** `height`, so the viewBox sat outside
  the matched span and its replacement was a **silent no-op**:

      <svg ... width="1056px" height="1094px" viewBox="0 0 1056 1056">
                              ↑ canvas grew          ↑ viewport did not

  Everything below y=1056 is outside the viewport, so no renderer draws it — browser, printer,
  or Dompdf, which embeds the same SVG. Every sticker and every PDF shipped without the
  human-readable serial, which is the only thing tying a sticker back to a row.

  The test asserted `assertStringContainsString($serial, $svg)`. **It always passed** — the text
  element was in the file the whole time. What was needed was `canvas height == viewBox height`
  and the text's `y` inside the box. Found by the owner looking at the output, not by the suite.

  The three together — payload vs stored row, translation key vs rendered label, file contents
  vs rendered pixels — are one rule: **the artefact you assert on must be the artefact the user
  receives.** Every layer between the two is a place the value can be silently dropped.

- **A written artefact with no route is not a feature.** Ask "how does the user GET this?" before
  calling an output path done, and make the success message describe only what actually happened.

  Smart QR slice 8's export queued a job that built a correct ZIP into
  `storage/app/private/smartqr-exports/`. There was no route, no link and no listing — the path
  is not web-reachable. The admin's success flash read *"It will appear in storage when ready"*,
  which was **true and useless**: it described a filesystem the admin cannot browse, and it read
  like completion. Four real archives accumulated unreachable before the owner asked why nothing
  printed.

  The failure survives review because every piece works: the job is correct, the file is valid,
  the message is accurate. Only the *join* is missing, and nothing tests a join that was never
  conceived. Whenever a feature ends in "written to storage", "sent to the queue" or "recorded",
  name the specific screen or endpoint where a person sees the result — and if there isn't one,
  that is the unfinished half.

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

**Phase 1 slice 6 (add-on purchasing) is BLOCKED, not merely deferred.** It writes an
`entitlement_grant` on payment success, and `refund()` in all thirteen gateways revokes
nothing (BUG-032) — so a refunded add-on would work forever, where a refunded subscription at
least expires with its period. The prerequisite is a decision about what a refund does to an
entitlement, not more gateway code. Webhook idempotency is NOT a blocker: all thirteen gateways
already dedup through `WebhookIdempotencyService` against a real unique constraint — the
"7 of 15 stub handleWebhook, nothing is idempotent" claim was measured false and is recorded
as BUG-033, together with the non-unique `billing_events` index that probably caused it.
Separately, BUG-034: Paddle and PayPal never RELEASE that lock on handler failure, so a
transient error permanently dedups the event and the renewal is lost.

### Named items that must not be lost when a phase closes

- **BUG-003 triage pass — immediately after Phase 0.** The heavy unguarded-nullable-key files
  that 1c never touches: `Http/Controllers/Client/SettingsController.php` (12 candidates) and
  `Http/Controllers/Admin/ClientController.php` (10). Same root cause as BUG-001, which was a
  live customer-facing 500. Files touched during 1c get fixed opportunistically in their own
  module commit; these two do not, so they need a deliberate pass. See `docs/found-bugs.md`
  BUG-003 for the full list and the decided scope policy.
- **BUG-038: there is no AutomationXpert logo asset in the repo.** §14 requires one on printed QR
  artwork; the only logo files are WhatsMine-branded, inherited from `4ec7e3e`. Slice 8 uses the
  configured platform logo and renders PLAIN when none is set — it must NEVER fall back to the
  inherited asset, because a competitor's brand on a printed sticker is irreversible. ⚠️ Needs an
  owner decision before any kit is printed; it cannot be resolved in code.
- **`smart_qr_scans_per_month` — ✅ RESOLVED: there is NO scan limit, by owner ruling.** R-5
  originally recorded three enforcement tiers; there are TWO — a gauge at assignment and a
  boolean at display. The counter was **removed as a concept**, not deferred: the product sells
  CODES (bounded at 50 by `smart_qr_max_assigned`), metering scan volume would punish the most
  successful customers, enforcement would put a write on the redirect path slice 4 kept
  read-only, and a refusal would land on the customer's customer standing in a shop rather than
  on anybody who could act on it. ⚠️ Do not reinstate it as "the missing third tier" — see the
  amendment to R-5 in `docs/smart-qr-rulings.md`.
- **`ClientWorkspaceService::detachStaleWorkspaces()`** — deferred out of Phase 0. See
  `docs/phase-0-tenant-isolation-plan.md` §G-1d. Prerequisite for any partner-tier feature
  that can move a customer between organisations.

**Owed but never delivered** (requested during the §A.6 model rulings, not produced):

- **Propose (do not execute) `Template` → `SystemEmailTemplate`.** The bare name collides
  conceptually with `WhatsappTemplate` and will eventually cause a wrong-import bug.
- **Propose a rename for the `SocialAccount` basename collision.** `App\Models\SocialAccount`
  (OAuth logins, `social_accounts`) and `App\Modules\Social\Models\SocialAccount`
  (publishing, `social_media_accounts`) are different models with the same class basename.
  A global scope is being applied to exactly one of them — this is how a wrong-import bug
  gets written.

**Unfixed security findings that live only inside long audit documents.** All confirmed, none
scheduled, all would die quietly when Phase 0 closes:

| ID | Sev | Summary | Where |
|---|---|---|---|
| DEEP-03 | High | Client impersonation gated by the read-only `view_clients` permission — a "view" grant confers full impersonation of any client's administrator | `project-security-deep-dive.md` |
| DEEP-05 | Medium | `assignPlan` gated by `view_clients` — a read permission can change billing | same |
| SEC-004 | High | SVG accepted for logo/favicon upload and served from public storage — stored XSS | `project-security-findings.md` |
| SEC-006 | High | Sanctum tokens never expire (`expiration = null`) | same |

**Design constraints that must not be violated later** (agreed in conversation, easily lost):

- ⚠️ **Razorpay in-place plan change works ONLY for card-authorized subscriptions. A UPI or
  eMandate customer getting a refusal is CORRECT BEHAVIOUR, not a bug.**

  `RazorpayGateway::changePlan()` is implemented (PATCH `/v1/subscriptions/:id` with
  `schedule_change_at: 'now'`), but Razorpay's Update Subscription API documents two hard
  exclusions:

  > "Subscriptions cannot be updated when payment mode is UPI"
  > "Emandate subscriptions cannot be updated" — they are "immutable post-authentication"

  (`https://razorpay.com/docs/api/payments/subscriptions/update-subscription/`)

  This follows from mandate authorization, not from our code: the mandate the customer signed
  is what caps collectable amounts, and changing it needs a fresh authorization. Such a customer
  must cancel and re-subscribe.

  ⚠️ **The gateway cannot detect this in advance.** The app never records the authorization
  method and Razorpay does not return it on the subscription object we hold, so the check
  cannot move earlier than the API call. The refusal arrives as Razorpay's own
  `error.description`, passed through unchanged — do NOT "fix" this by replacing that message
  with a generic one; it is the only thing telling the customer what to do instead.

  ⚠️ **The ₹0.50 minimum is on the PRORATED difference, which we cannot compute.**
  Razorpay requires "the prorated amount difference between the existing and new plans is at
  least 50 currency subunits" and only "when you update a Subscription immediately"
  (`https://razorpay.com/docs/payments/subscriptions/update/`). Our pre-flight guard compares
  FULL PLAN PRICES, which is a deliberate one-way filter: proration only ever shrinks the
  difference, so refusing below 50 can never reject a change Razorpay would accept — but a
  full difference of 50+ can still prorate under the line late in a cycle, and Razorpay
  rejects those. The API error path is the real backstop; the guard exists to turn the common
  case into a sentence a customer can act on.

  ⚠️ **Stripe's identical guard is a PRODUCT choice, not an API constraint.** Stripe has no
  documented minimum proration difference and handles small amounts gracefully. Keeping the
  guard is purely a deliberate consistency choice with Razorpay, whose minimum is a real vendor
  constraint. Reconfirmed on 2026-09-07: both guards are staying, and same-price swaps (a zero
  difference) remain intentionally blocked on both gateways. If that consistency decision ever
  changes, drop the guard on the Stripe side and keep Razorpay's.

- **Per-workspace integrations need a SEPARATE `workspace_integration_connections` table**
  with encrypted per-workspace credentials. Do **not** extend `IntegrationConfig`, which is
  platform-global and admin-managed. Applies to Google Business Profile, Calendly, n8n.
- **SMTP will need a partner tier**: `workspace → partner → platform`. `WorkspaceSmtpConfig`
  is the tenant override, `SmtpConfiguration` the platform fallback. Do not build the partner
  layer now, but do not design anything that blocks inserting it.
- **`Subscription` (user_id) vs `ClientSubscription` (client_id)** — ⚠️ **VERIFIED 2026-08-09,
  and the earlier note here was backwards.** It said `ClientSubscription` was authoritative and
  `Subscription` "appears to have no live writers". Measured:

  | Table | Live writers |
  |---|---|
  | `subscriptions` | **15** — one per payment gateway (`StripeGateway`, `PaddleGateway`, …) |
  | `client_subscriptions` | **1** — `Admin\ClientController::assignPlan`, plus 2 seeder calls |

  Neither is authoritative alone. **They are two parallel billing paths**: `subscriptions` is
  self-serve/gateway billing, `client_subscriptions` is admin assignment. Do **not** deprecate
  `Subscription` — it carries the paying customers.

  **"Which subscription is in effect" is the entitlement resolver's first job**, not a detail
  it can defer. `Client::effectivePlan()` already encodes the precedence (admin assignment
  first, then any of the client's users' active subscriptions); `Client::activePlan()` sees only
  the first and is the narrower of the two.

  The cost of the wrong note was real: `EnforceLimit` was written against `activePlan()`, so
  every gateway-billed customer has been exempt from every plan limit since launch. See BUG-023.
- **BUG-037**: ⚠️ **`php artisan db:seed` CORRUPTS `resources/js/locales/*.json`.** Do not run it
  until this is fixed — see `docs/found-bugs.md` BUG-037 for a safe seeder sequence.
  `TranslationKeyScanner`'s fifth regex is unanchored and harvests `route('admin.clients.index')`
  as a translation key; `I18nFileService::unflatten()` then silently collapses real nested keys
  (4 one way, 25 the other). **It affects deployed installs** — `InstallerService::seedCore()`
  runs `i18n:seed-defaults`, so a fresh install damages en.json before anyone logs in. Non-English
  is worse than loss: the English string is written into hi/ar/zh and reads as translated.
  Predates all our work (`4ec7e3e`).
- ⚠️ **A HAND-EDIT TO `resources/js/locales/*.json` IS INVISIBLE UNTIL THE i18n CACHE IS
  CLEARED**, and it fails in the worst way: the change appears "not to work" for up to an hour,
  then silently starts working, so nobody learns the rule.

  ```bash
  php artisan tinker --execute='app(App\Services\I18n\I18nFileService::class)->invalidateCache();'
  ```

  (`php artisan cache:clear` also works but flushes everything else too.)

  `I18nFileService::getFlatDictionary()` wraps the file read in `Cache::remember(..., 3600)`,
  keyed `i18n:file:{locale}:{i18n_version}`. `i18n_version` is bumped ONLY by
  `invalidateCache()`, which the app calls on its own locale writes (the admin Locale UI).
  Editing the JSON on disk bumps nothing, so `/i18n/{locale}` keeps replaying the previous
  snapshot. A key missing from that snapshot renders as the RAW KEY in the UI — e.g. a filter
  labelled `smart_qr.filter_all_workspaces`.

  ⚠️ **Diagnose it at the endpoint, not in the file.** The file being correct proves nothing:

  ```bash
  curl -s http://127.0.0.1:8007/i18n/en | python3 -c "import json,sys; print(json.load(sys.stdin)['translation'].get('smart_qr.filter_all_workspaces'))"
  ```

  **Recurred four times** — `batches_subtitle`, then `filter_all_workspaces` and
  `view_qr_coming_soon` together. The third instance under-reported itself: only one broken
  label was noticed, but every key added since the last bump was stale.

  The fourth, **2026-09-05**: 15 keys hand-added to `en.json` for the new QR Dashboard
  (`Admin/SmartQr/Dashboard.jsx`) and `invalidateCache()` not run afterwards. The whole page
  rendered raw keys — `smart_qr.dashboard_title`, every `stat_*` label, the panel headings.
  Cross-checking all 29 `t()` calls against the file found 0 missing and 0 misspelled; the
  endpoint was serving 3684 keys against a file holding 3699. Diagnosing at the file would have
  found nothing, which is exactly what the warning above is for.

  ⚠️ **THE TEST SUITE STRUCTURALLY CANNOT CATCH THIS, and that is why it keeps recurring.**
  The vitest specs read `resources/js/locales/en.json` off disk directly
  (`fs.readFileSync`, as `smartqr-inventory-detail.test.jsx` does), so they see the correct
  file and pass while the running app serves the stale snapshot. A green suite is not evidence
  the labels render. The only detector is a person loading the page — which is how all four
  were found, each time after the work was believed finished.

  So: **run the invalidation in the same breath as the edit, before loading the page or
  believing a green suite** — not as a remembered follow-up step. A hand-edit to `en.json` and
  its `invalidateCache()` are one operation in two commands, and every recurrence so far has
  been the second command going missing.

  ⚠️ **The same caching hides BUG-037 rather than helping.** A corrupted `en.json` on disk keeps
  serving correct strings from cache long after the damage, so the seeder's corruption surfaces
  an hour later with no obvious cause. Same shape as BUG-036 below: a cache invalidated only by
  the application's own writes, never by the thing that actually changed the data.
- **BUG-036**: editing a plan's limits through `Admin\PlanController` never invalidates the
  entitlement cache, so the OLD limits stay enforced for up to
  `entitlements.cache_fallback_ttl_minutes` (default 60) — silently, in both directions.
  ⚠️ Not "an event with no dispatcher": `PlanChanged` IS dispatched, from `StripeGateway`, but it
  means *"this subscriber moved between plans"* and its constructor requires a User and a
  Subscription — so a plan-DEFINITION edit structurally cannot dispatch it. There is no event for
  "a plan's definition changed", and the fix is a fan-out over every subscriber, not a
  `dispatch()` call. `entitlements:reconcile` is the workaround; a workaround an operator must
  remember is not a fix. See `docs/found-bugs.md` BUG-036.
- **BUG-002**: 10 pre-existing PHPStan `property.notFound` errors in `app/Modules/Social`.
  Fix properly with `@property` annotations, or baseline as a *tracked decision* — not as a
  side effect of not looking. See `docs/found-bugs.md`.
