# Test Suite Baseline

> ## 🟢 THE GATE IS ZERO
>
> The suite passes: **1,207 tests, 39,777 assertions, 0 failures, 1 documented skip.**
>
> **Front-end: 24 vitest tests, 3 files.** Previously ZERO ran — `setup.js` held JSX under a
> `.js` extension so vite refused to transform it. Fixed in Smart QR slice 3b.
>
> **Any failure from here is a regression.** There are no longer any "pre-existing
> failures" to hide behind — that excuse expired on 2026-08-03. A red suite blocks the
> merge, full stop.
>
> **But read the caveat below before treating green as assurance.**

**Current number recorded:** 2026-08-14, on `feature/smart-qr` after Smart QR slice 3b. Previously 2026-08-07 on `master` at `473d583`. Every figure here is measured, not carried forward.

**The 1 skip is documented and deliberate**, not a silent hole:
`CredentialNotInExceptionMessageTest::test_the_places_scraper_does_not_persist_the_key_to_the_job_error_column`
is skipped with the reason *"Owed until BUG-007 is fixed: `GooglePlacesScraper::run()` dies at
line 29 on an undefined `CredentialResolver::generic()`, so no HTTP request is ever made and
this assertion would pass vacuously."* It is skipped **precisely because it would pass
vacuously** — see "Zero is a floor" below. It un-skips when BUG-007 is built.

**How it reached 819 from the 625 recorded on 2026-08-06** — every step is a `--no-ff` merge
with its own full-suite run:

| Merge | Adds | Running total |
|---|---|---|
| `b4b0520` — BUG-005, Gemini key removed from URLs | 9 security tests | 600 |
| `7a2fb01` — Group D, retry wiring at 6 AI HTTP sites | 25 wiring tests | 625 |
| 1c workspace-context modules + SEC-003 `db:backup` / `db:restore` | — | 782 |
| `a6c888b` — **DEEP-03** + 3 sibling read-permission gates | 10 privileged-action tests | 792 |
| `637de50` — **SEC-004** upload extension spoofing | 13 upload tests | 805 |
| `473d583` — **SEC-006** token expiry + revocation | 14 token-lifecycle tests | **819** |
| Phase 0 — `BelongsToWorkspace`, slices 1–9 + BUG-019 | scope, guards, brake, job context | 949 |
| `25c9476` — partner tier data layer | 9 partner-tier tests | 957 |
| `54f965a` — **BUG-022/023** plan limits never enforced | 9 rewritten + 5 new limit tests | **966** |
| Phase 1 slices 1–7 + Smart QR slices 1–2 | catalog, resolver, cache, QR schema + generation | 1128 |
| `d4b5e80` — **Smart QR slice 3a**, admin batches / inventory / assignment | 18 assignment + 16 inventory/batch tests | 1162 |
| **Smart QR slice 3b**, admin React pages + QR navigation | 1 test for the `workspaces` prop the modal needed | 1163 |
| Smart QR slice 3b fixes — three walkthrough UI defects | 1 PHP (`qr_type` end to end) + 8 vitest | 1164 + 19 JS |
| **Smart QR slice 3c** — delete vs retire, rename, edit | 12 delete/retire + 7 edit PHP, 5 vitest (typed confirmation) | 1183 + 24 JS |
| **Smart QR slice 4** — the public redirect `/q/{token}` | 24 tests: seven outcomes, the serial-404 control, scan privacy | **1207** + 24 JS |

**Originally recorded:** 2026-08-03, immediately after §0.0 (test-database isolation).
**Purpose:** distinguish pre-existing failures from Phase 0 regressions.

## Command

```bash
php -d memory_limit=2G vendor/phpunit/phpunit/phpunit --no-coverage
```

`php artisan test` could not be used: it spawns child processes that do not inherit
`-d memory_limit`, so it dies at the 128 MB default. `vendor/bin/phpunit` is not executable
in this working copy (flattened symlinks).

## Result — current (2026-08-07, `master` @ `473d583`)

| Metric | Value |
|---|---|
| Tests | **966** |
| Assertions | 38,894 |
| **Failures** | **0** |
| Errors | **0** |
| Skipped | **1** (documented — BUG-007, see above) |
| Risky / Incomplete | **0** |
| Time | 32.4 s |
| Peak memory | 193 MB |
| Connection | `mysql` → **`whatsmine_test`** ✅ (working DB untouched) |

The two `S` markers in the original baseline (DNS-dependent cases in `PublicHttpUrlTest`) are
gone. The single remaining skip is a different one, added deliberately and with a reason
string — see above.

**A skip count above 1 is a regression** on the same terms as a failure. The one permitted
skip is named; any other means a test stopped running and nobody said why.

### Original baseline for comparison (2026-08-03, after §0.0)

| Metric | Value |
|---|---|
| Tests | 440 |
| Assertions | 975 |
| **Failures** | **29** |
| Errors | 0 |
| Skipped | 2 (`S` markers — DNS-dependent cases in `PublicHttpUrlTest`) |
| Time | 22.7 s |
| Peak memory | 137 MB |

## Failure attribution — historical (2026-08-03)

The attribution below describes the **original** 29 failures and is kept as the record of
how the gate reached zero. All of it is resolved; none of these tests fail today.

**18 pre-existing · 11 introduced by the SSRF commit (mine)**

### Mine — 11 failures, all test defects, no production-code defect

| # | Test | Cause |
|---|---|---|
| 1 | `PublicHttpUrlTest::test_it_allows_public_https_urls` (`subdomain`) | I used `hooks.example.com` in the allowed set. It does not resolve, so the rule correctly rejects it. **Test data bug** — use a resolving host or stub DNS. |
| 2–11 | `WebhookSsrfProtectionTest::test_api_endpoint_creation_rejects_non_public_urls` (10 data sets) | Returns **401, not 422**. `actingAs($user, 'sanctum')` does not authenticate `/api/v1/webhooks`; it needs `Sanctum::actingAs()` or a real token. **Test harness bug** — the rule itself is fine (proven by the 10 equivalent web-route cases, which pass). |

Neither indicates a flaw in the SSRF fix. Both are mine to fix.

### Pre-existing — 18 failures

| Test | Count | Observed |
|---|---|---|
| `Campaign\SchedulerTest` | 4 | 404 on campaign launch |
| `Inbox\LabelCrudTest` | 3 | 404 (incl. `cross_workspace_attach_forbidden` expecting 403) |
| `Inbox\HandoverTest` | 2 | 404 |
| `Realtime\TypingEndpointTest` | 2 | 404 |
| `Inbox\SlaTrackingTest` | 2 | 404 + null assertion |
| `ProductionHardening\PlanLimitTest` | 1 | 404 |
| `Polish\UpgradeModalTest` | 1 | 404 |
| `Meta\MetaInboundWebhookTest` | 1 | dedupe found 0 rows, expected 1 |
| `MarketingSuite\MultiTenantScopingTest` | 1 | 404, expected 403 |
| `Auth\RegistrationTest` | 1 | `false is true` |

**Dominant cause: 15 of 18 are HTTP 404s.** Two distinct root causes identified:

1. **Wrong route key.** `MultiTenantScopingTest::workspace_a_cannot_delete_workspace_b_contact`
   calls `delete("/app/contacts/{$contactB->id}")` — an **integer** — but
   `Contact::getRouteKeyName()` returns **`uuid`**. Binding cannot resolve it, so it 404s
   before reaching any authorization check.

2. **Routes that exist only under `api/v1/mobile/…`.** `handover`, `typing`, and
   conversation `labels` resolve only at `api/v1/mobile/conversations/{uuid}/…`; the tests
   appear to target `/app/…` paths that do not exist.

## ⚠️ This invalidates plan §G-4

§G-4 predicted that applying the global scope would flip
`MultiTenantScopingTest::workspace_a_cannot_delete_workspace_b_contact` from **403 → 404**,
and asked for approval of that expectation change.

**The premise was wrong.** The test already returns 404, for an unrelated reason (integer id
passed where a uuid is required). It is not asserting a working 403 today — it is failing.

Two consequences:

- The approved 403 → 404 expectation change is **not needed for this test**; it needs a
  different fix (pass `$contactB->uuid`).
- More seriously: **this test has never verified what it claims.** It 404s at route binding,
  so cross-workspace delete protection for contacts is currently **unproven**. Whether the
  underlying authorization works is an open question that Phase 0 must answer.

The 403 → 404 reasoning still holds *in principle* for uuid-bound models once the scope
lands — but it must be re-derived against tests that actually exercise the path.

## Progress log

| Date | Task | Tests | Failures | Change |
|---|---|---|---|---|
| 2026-08-03 | Baseline recorded (after §0.0) | 440 | **29** | — |
| 2026-08-03 | TASK 1 — repaired the 11 SSRF test defects (`fix/webhook-ssrf-tests`) | 440 | **18** | −11, zero regressions |
| 2026-08-06 | Phase 0 merges landed on `master` (`310580e`, `49a3b92`) | 591 | **0** | +145 tests, zero regressions |
| 2026-08-06 | BUG-005 merged (`b4b0520`) — Gemini key out of URLs | 600 | **0** | +9 security tests |
| 2026-08-06 | Group D merged (`7a2fb01`) — retry wiring at 6 AI HTTP sites | 625 | **0** | +25 wiring tests |
| 2026-08-07 | 1c workspace-context modules + SEC-003 (`db:backup` hardened, `db:restore` built) | 782 | **0** | +157, zero regressions |
| 2026-08-07 | **DEEP-03** merged (`a6c888b`) — 4 privileged actions off read permissions | 792 | **0** | +10 privileged-action tests |
| 2026-08-07 | **SEC-004** merged (`637de50`) — upload extension spoofing | 805 | **0** | +13 upload tests |
| 2026-08-07 | **SEC-006** merged (`473d583`) — token expiry + revocation | 819 | **0** | +14 token-lifecycle tests |
| 2026-08-08 | **BUG-019** merged (`c77f797`) — channel routing uniqueness | 838 | **0** | +19 routing tests |
| 2026-08-09 | **Phase 0** merged (`dece5a8`) — scope, guards, brake, 6 models + 2 orphans | 948 | **0** | +110; 36 new test files |

**TASK 1 detail.** Both causes were test defects; neither touched production code.

- `WebhookSsrfProtectionTest::test_api_endpoint_creation_rejects_non_public_urls` (10 cases) —
  `/api/v1/webhooks` is guarded by `api.ability:webhooks:write`, which reads
  `currentAccessToken()`. `actingAs($user, 'sanctum')` does not populate that, so the request
  401'd before validation ran. Now issues a real token via
  `createToken('ssrf-test', ['*'])->plainTextToken` + `withToken()`, mirroring
  `Tests\Feature\Api\V1\OutboundWebhookApiTest`.
- `PublicHttpUrlTest::test_it_allows_public_https_urls` (`subdomain`) — the case used
  `hooks.example.com`, which does not resolve, so the rule correctly rejected it. The case was
  testing the DNS resolver rather than subdomain handling. Now uses `www.example.com`, an IANA
  reserved name that genuinely resolves.

Assertions rose 975 → 995: the 10 API cases now reach their assertions instead of dying at auth.

## The arc: 29 → 0

| Stage | Tests | Assertions | Failures | What changed |
|---|---:|---:|---:|---|
| Baseline (§0.0) | 440 | 975 | **29** | Suite made runnable; database isolated from the working DB |
| TASK 1 | 440 | 995 | **18** | 11 SSRF-harness defects — `actingAs` does not populate `currentAccessToken()`; a non-resolving test host |
| TASK 2 | 441 | 998 | **17** | Cross-workspace contact delete — wrong route key, plus a non-load-bearing soft-delete assertion. Added a positive control |
| TASK 3 | 441 | 1022 | **2** | 15 route-404s, all one cause: integer `id` passed where the route binds by `uuid` |
| TASK 3b | 446 | 1041 | **0** | Webhook dedupe rewritten against realistic payloads; registration `agree_terms` + paired negative |

**Not one of these was a production defect.** Every failure was a defect in the test.

## ⚠️ Zero is a floor, not assurance

Five tests were passing — or failing for the wrong reason — while verifying nothing they
claimed to:

| Test | Claimed | Actually did |
|---|---|---|
| `MultiTenantScopingTest::workspace_a_cannot_delete_workspace_b_contact` | cross-workspace delete blocked | 404'd at binding; and `assertDatabaseHas` could not fail on a soft-deleting model |
| `LabelCrudTest::test_cross_workspace_attach_forbidden` | cross-workspace attach blocked | 404'd at binding |
| `TypingEndpointTest::test_typing_endpoint_forbidden_for_other_workspace` | cross-workspace typing blocked | 404'd at binding |
| `MetaInboundWebhookTest::global_webhook_dedupes_duplicate_entry_ids` | webhook dedupe works | empty payload produced no dedupe key; nothing recorded |
| `RegistrationTest::test_new_users_can_register` | registration works | omitted a required field; never created a user |

Three of the five asserted a **4xx** status, which a 404 satisfies — so "not allowed" read as
proof of protection while proving only that a URL did not resolve.

**Consequence:** a green suite in this codebase is evidence that nothing got *worse*. It is
not evidence that behaviour is correct. When adding the workspace global scope, do not rely
on this suite to catch a mistake — it did not detect the absence of protection, so it will
not reliably detect its removal. See §G-1c in `phase-0-tenant-isolation-plan.md`.

Countermeasure now in force (`CLAUDE.md`): every "is blocked" test must carry a positive
control proving the same route succeeds for the legitimate user.

**The assertion count is not a coverage measure.** Of the 37,925 assertions, roughly 32,000
come from `JitterTest` alone, whose randomised-draw loops assert an invariant on every draw.
The Unit suite accounts for 36,711 assertions across just 104 tests; the Feature suite — where
tenant isolation is actually proven — carries only 1,174 across 487 tests. The jump from 1,041
to 37,925 assertions therefore reflects one loop-heavy unit test, **not** a 36× increase in
coverage. Judge coverage by what the Feature suite exercises, never by this total.

> **Addendum 2026-08-07 — the paragraph above is kept verbatim and its reasoning is
> unchanged.** The total is now 38,597. The +672 since 2026-08-06 came from 37 new **Feature**
> tests (DEEP-03, SEC-004, SEC-006), so the ratio moved the right way — but the conclusion
> stands exactly as written: `JitterTest` still dominates the total, and the total is still
> not a coverage measure.
>
> **The three security branches are the worked example of why.** Across them, six tests were
> caught proving nothing: two branding tests that passed on a **permission-denial redirect**
> without ever reaching the controller, and a four-part token probe where the guard cached a
> resolved user across requests and returned `200` for a check that should have been `401`.
> Both were found by a **positive control** and by **re-running in isolation** — not by the
> suite being green.

**Status of the workspace-context work on `master`.** The five completed 1c modules — Leads,
Social, Automation, Client-controllers (CampaignReport) and Broadcasting — are merged and
covered by 67 green isolation tests.

**Superseded 2026-08-07.** This paragraph previously said all three characterisation tests in
`WorkspaceContextTest` assert broken behaviour and invert together when 1c completes. Both
halves were wrong:

- `characterisation_switching_workspace_does_not_affect_controllers_today` **flipped early**,
  at 1c/shared, because it exercises `GET /app/contacts` — a route that commit migrated. It
  was inverted and renamed `switching_workspace_changes_which_contacts_the_list_returns`, and
  now proves the switcher works on that route.
- The remaining two evaluate the legacy expression directly rather than through a controller,
  so they **never flip** and are not a signal of 1c progress.

A characterisation test flips when the route it exercises is migrated, not when its phase
completes — so a mid-phase red gate here is expected, not a regression. See
`docs/phase-0-tenant-isolation-plan.md` for the full correction.

**The retry work is partial.** No test exercises `backoff()` on any job — 8 of the 16 jobs
have no test reference at all, and the only occurrences of "backoff" in `tests/` are two
comments. Job references are `Queue::assertPushed` under `Queue::fake()`, which never resolves
a backoff schedule. See `24ecbf1` for the full disclosure.

## Discovered along the way

**Two independent webhook idempotency layers**, which nobody had documented:

| Layer | Provider key | Location |
|---|---|---|
| Controller | `whatsapp_global` | `WhatsappWebhookController:68` — keyed on `sha256(m:<id>)` |
| Driver | `whatsapp_msg` | `WhatsappDriver:204` — keyed on the raw message id |

Found because a first-draft test failed for an unexpected reason (expected 1, got 2). Now
asserted explicitly by `inbound_message_is_recorded_by_both_idempotency_layers`.

## Running the suite

`php artisan test` cannot be used: it spawns child processes that do not inherit
`-d memory_limit`, so it dies at the 128 MB default. `vendor/bin/phpunit` is not executable
in this working copy (flattened symlinks). Use the command at the top of this document.
