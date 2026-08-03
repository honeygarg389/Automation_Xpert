# Test Suite Baseline — pre-Phase 0

**Recorded:** 2026-08-03, immediately after §0.0 (test-database isolation).
**Purpose:** distinguish pre-existing failures from Phase 0 regressions.

## Command

```bash
php -d memory_limit=2G vendor/phpunit/phpunit/phpunit --no-coverage
```

`php artisan test` could not be used: it spawns child processes that do not inherit
`-d memory_limit`, so it dies at the 128 MB default. `vendor/bin/phpunit` is not executable
in this working copy (flattened symlinks).

## Result

| Metric | Value |
|---|---|
| Tests | **440** |
| Assertions | 975 |
| **Failures** | **29** |
| Errors | 0 |
| Skipped | 2 (`S` markers — DNS-dependent cases in `PublicHttpUrlTest`) |
| Time | 22.7 s |
| Peak memory | 137 MB |
| Connection | `mysql` → **`whatsmine_test`** ✅ (working DB untouched) |

## Failure attribution

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

## Regression gate for Phase 0

Any Phase 0 commit must keep the suite at **≤ 29 failures**, with the composition above.
A new failing test name that is not in this document is a regression.

Recommended before Phase 0 code lands (not yet done, not yet approved):

1. Fix my 11 (`Sanctum::actingAs`, resolving test host)
2. Fix `MultiTenantScopingTest` to bind by `uuid` — this is a prerequisite for §G-4 to mean
   anything
3. Triage the 15 route-404s: stale tests, or genuinely missing routes?
