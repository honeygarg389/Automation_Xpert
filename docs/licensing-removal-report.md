# Licensing & Activation Removal Report

**Date:** 2026-08-01
**Scope:** Complete removal of the product licensing / activation / license-server update system from WhatsMine v1.5.0.
**Authorization:** Performed at the request of the copyright owner of the application.

---

## 0. Pre-flight findings

| Check | Result |
|---|---|
| `AGENTS.md` / `CLAUDE.md` / `.cursorrules` | **None present** in the repository. |
| `git status` | **Not a git repository** (`fatal: not a git repository`). No VCS state to preserve; no unrelated working-tree changes could be detected or protected by git. All changes below were made directly to the working tree. |
| Assumptions from other copies | None used. Every component below was located by repository-wide search and read in full before any change. |

---

## 1. Architectural inventory

The licensing implementation was a **Botble License Manager** client integration. It had **no database component** — all state lived in `config/`, three files under `storage/app/`, and the cache.

### 1.1 Configuration

| File | Purpose | Decision | Reason |
|---|---|---|---|
| `config/license.php` | Held `verify` kill-switch, XOR/base64-obfuscated license **server URL**, **API key** and **product id**, `verify_type`/`verify_types`, `cache_hours`, and `current_version` (read from `APP_VERSION`). | **Deleted** | Exclusively licensing. Its only non-licensing value, `current_version`, merely re-read `APP_VERSION`, which is still read directly elsewhere. |
| `.env` / `.env.example` | Searched for `LICENSE_SERVER_URL`, `LICENSE_API_KEY`, `LICENSE_PRODUCT_ID`, `LICENSE_VERIFY_TYPE`, `LICENSE_CACHE_HOURS`. | **No change** | **None of these variables were present in either file.** The values were baked into `config/license.php`, which is now gone. Nothing to remove. |
| `APP_VERSION` | Generic app version. | **Retained** | Read independently by `HandleInertiaRequests.php:79` and `:356` and surfaced to the UI as the `app_version` prop. Dependency tracing proves it is **not** licensing-only (rule 8). |

### 1.2 Services

| File | Purpose | Dependencies | Called by | Decision |
|---|---|---|---|---|
| `app/Services/License/LicenseManager.php` | The entire license client: `enabled()`, `activate()`, `verify()` (with cache + fail-open grace window), `deactivate()`, `checkUpdate()`, `downloadUpdate()`; wrote `storage/app/.license`, `.license_code`, `.license_type`; cached `license.verified`. Sent `X-API-KEY`, `X-API-URL`, `X-API-IP` headers to the license server. | `Http`, `Cache`, `Log`, `config('license.*')` | `EnsureLicensed`, `LicenseController`, `Admin\LicenseController`, `Install\InstallController`, `Updater` | **Deleted** |
| `app/Services/License/Updater.php` | Vendor auto-update: puts app in maintenance mode, downloads the update zip **via `LicenseManager::downloadUpdate()`**, extracts over the app, imports SQL, migrates, bumps `APP_VERSION`, clears caches. | `LicenseManager`, `EnvWriter`, `Artisan`, `DB` | `Admin\LicenseController::applyUpdate()` only | **Deleted** — see §2.1 |
| `app/Services/Install/EnvWriter.php` | Generic `.env` writer. | — | `InstallerService` **and** (formerly) `Updater` | **Retained** — still used by the installer. |

### 1.3 Middleware

| File | Purpose | Decision |
|---|---|---|
| `app/Http/Middleware/EnsureLicensed.php` | Gated the whole `/admin` panel; redirected to `license.show` or returned HTTP 403 JSON when unlicensed. Registered as alias `licensed`. | **Deleted** |

Middleware aliases and groups touched in `bootstrap/app.php`:
- alias `'licensed' => EnsureLicensed::class` — **removed**
- admin route group `['web', 'auth:admin', 'licensed', 'demo']` → `['web', 'auth:admin', 'demo']` — **`auth:admin` and `demo` retained**

All other middleware (`EnsureInstalled`, `EnsureNotDemoMode`, `EnsureAdminRole`, `EnsureSuperAdmin`, `EnsureUserRole`, `RequirePermission`, `EnsureClientScope`, `EnforceLimit`, `CheckApiAbility`, `redirect.if.admin`) — **untouched**.

### 1.4 Controllers

| File | Purpose | Decision |
|---|---|---|
| `app/Http/Controllers/LicenseController.php` | Guest re-activation page (`license.show`, `license.activate`). | **Deleted** |
| `app/Http/Controllers/Admin/LicenseController.php` | Admin License & Updates screen: `index`, `checkUpdate`, `applyUpdate`, `activate`, `deactivate`. | **Deleted** |
| `app/Http/Controllers/Install/InstallController.php` | Installer. Contained `activateLicense()` plus license validation and an activation gate inside `run()`. | **Modified** — licensing stripped, installer retained. |

### 1.5 Routes

| Route | Name | Decision |
|---|---|---|
| `GET /license` | `license.show` | **Deleted** (`bootstrap/app.php`) |
| `POST /license/activate` | `license.activate` | **Deleted** (`bootstrap/app.php`) |
| `POST /install/activate-license` | `install.activate-license` | **Deleted** (`bootstrap/app.php`) |
| `GET /admin/license` | `admin.license.index` | **Deleted** (`routes/admin.php`) |
| `POST /admin/license/check-update` | `admin.license.check-update` | **Deleted** (`routes/admin.php`) |
| `POST /admin/license/apply-update` | `admin.license.apply-update` | **Deleted** (`routes/admin.php`) |
| `POST /admin/license/activate` | `admin.license.activate` | **Deleted** (`routes/admin.php`) |
| `POST /admin/license/deactivate` | `admin.license.deactivate` | **Deleted** (`routes/admin.php`) |
| `GET /install`, `POST /install`, `POST /install/test-database` | `install.show`, `install.run`, `install.test-database` | **Retained** |

### 1.6 Frontend (React / Inertia)

| File | Purpose | Decision |
|---|---|---|
| `resources/js/Pages/License/Activate.jsx` | Guest activation page. | **Deleted** |
| `resources/js/Pages/Admin/License/Index.jsx` | Admin License & Updates page (activate / deactivate / check update / apply update). | **Deleted** |
| `resources/js/Components/Install/LicenseStep.jsx` | Installer purchase-code step; POSTed to `install.activate-license`. | **Deleted** |
| `resources/js/Components/LicenseTypeTabs.jsx` | Envato / non-Envato code-type chooser. Used only by the three files above. | **Deleted** |
| `resources/js/lib/licenseLabels.js` | Envato/CodeCanyon copy, purchase-code help URL. Used only by the files above. | **Deleted** |
| `resources/js/Pages/Install/Setup.jsx` | Install wizard. Held `license_code` / `client_name` / `verify_type` form fields, the conditional License step and its gate. | **Modified** |
| `resources/js/Layouts/AdminLayout.jsx` | Admin sidebar; had the `admin.license` nav entry (`KeyRound` icon). | **Modified** — nav entry and now-unused `KeyRound` import removed. |
| `resources/js/Pages/Contacts/BulkImport.jsx` | Uses `VITE_HANDSONTABLE_LICENSE_KEY` / `licenseKey`. | **Retained** — third-party library license key, unrelated to product activation (rule 9). |

### 1.7 Translations

`license` (top-level, `{"activate": "Activate"}`) and `admin.license` ("License" nav label) removed from all 15 locale files that contained them: `ar, bn, de, en, es, fr, hi, id, it, ja, ko, pt, ru, tr, zh`. `au.json` and `cn.json` did not contain them. No other keys were touched.

### 1.8 Filesystem artifacts

| Path | Written by | Decision |
|---|---|---|
| `storage/app/.license` | `LicenseManager::storeLicenseData()` | **Deleted** — activation token, licensing-only. |
| `storage/app/.license_code` | `LicenseManager::storeCode()` | **Deleted** — raw purchase code, licensing-only. |
| `storage/app/.license_type` | `LicenseManager::storeType()` | **Deleted** — licensing-only. |
| `storage/app/updates/` (contained `update-7322e47d…sql`) | `Updater::apply()` only | **Deleted** — sole writer was the license-server updater. |

Contents were **not** printed, logged, or copied anywhere (rule 12).

### 1.9 Components searched and confirmed **absent**

No licensing code existed in any of these, so nothing was changed:

- Service providers (`AppServiceProvider`, `ModuleServiceProvider`, `BroadcastChannelsServiceProvider`, `PusherSettingsServiceProvider`) — no license bindings; `LicenseManager` was auto-resolved by the container.
- Console commands (`app/Console/`), queue jobs (`app/Jobs/`), scheduled tasks (`routes/console.php`), events (`app/Events/`), listeners (`app/Listeners/`), models (`app/Models/`), policies (`app/Policies/`), traits/helpers (`app/Support/`, `app/Contracts/`).
- **Database:** zero matches for `licen*` / `activation` in `database/migrations/`. **No licensing tables, columns, models or migrations exist.** No migration was created or deleted (rule 10).
- Blade views (`resources/views/`) — no licensing markup.
- `composer.json` / `package.json` — no license-client packages. `composer.json`'s `"license": "proprietary"` is package metadata and was **retained** (rule 9).
- API routes (`routes/api.php`), webhooks, client routes, reports routes — no licensing references.
- `phpstan-baseline.neon` — zero licensing entries, so no stale baseline rows were orphaned.
- `.branding` — no licensing keys.

### 1.10 False positives — deliberately retained

| Match | Reason retained |
|---|---|
| `composer.json` → `"license": "proprietary"` | Package metadata (rule 9). |
| `resources/js/Pages/Contacts/BulkImport.jsx` → Handsontable `licenseKey` | Third-party library key (rule 9). |
| `database/seeders/DemoSeeder.php:1337` → "hiring a licensed massage therapist" | Demo content string. |
| `public/build/assets/*.js` → `@license lucide-react v0.575.0 - ISC` banners | Third-party open-source legal notices emitted by the bundler (rule 9). |
| `AdminUserController`, `EmailSystemController`, billing gateways, `Automation/*`, `SmsProviders`, `Integrations`, `SubscriptionStartedNotification` | Matched only on the ordinary words *activate* / *deactivate* (enabling users, providers, integrations, subscriptions) — unrelated to product activation. |

---

## 2. Dependency analysis

### 2.1 Vendor auto-update — removal justified (rule 5)

`Updater::apply()` obtains the update package **exclusively** through `LicenseManager::downloadUpdate()`, which:
1. requires `LicenseManager::enabled()` (license server URL + API key + product id),
2. requires a stored `license_data` token, and
3. POSTs to `{license_server}/api/external/update/{id}/download/{type}`.

Version discovery (`checkUpdate()`) likewise POSTs to `{license_server}/api/external/update/check`. There is **no alternative release source** in the codebase — no GitHub releases, no S3/CDN manifest, no packagist channel, no local artifact path. The updater is therefore inseparably dependent on the licensing server and was removed with it.

`APP_VERSION` itself is **not** licensing-only and was **retained** (rule 8): it is read directly by `HandleInertiaRequests` and exposed as the `app_version` Inertia prop, independently of any license code. Verified still present in the live `/install` response (see §5).

### 2.2 Inbound callers resolved

| Removed symbol | Inbound callers | Resolution |
|---|---|---|
| `LicenseManager` | `EnsureLicensed`, `LicenseController`, `Admin\LicenseController`, `Install\InstallController`, `Updater` | First four deleted or de-licensed; `Updater` deleted. |
| `Updater` | `Admin\LicenseController::applyUpdate()` | Caller deleted. |
| `EnsureLicensed` | alias `licensed` in `bootstrap/app.php`; admin route group | Alias and group usage removed. |
| `route('license.show')` | `EnsureLicensed` | Both deleted. |
| `route('license.activate')` | `Pages/License/Activate.jsx` | Both deleted. |
| `route('install.activate-license')` | `Components/Install/LicenseStep.jsx` | Both deleted. |
| `route('admin.license.*')` | `Layouts/AdminLayout.jsx`, `Pages/Admin/License/Index.jsx` | Nav entry removed; page deleted. |
| `licenseCopy()` / `typeLabel()` | `Setup.jsx`, `LicenseStep.jsx`, `Activate.jsx`, `Admin/License/Index.jsx` | All four handled. |
| `licensing` Inertia prop | `Setup.jsx` | Producer and consumer both removed. |
| `config('license.*')` | `LicenseManager`, `Admin\LicenseController` | Both deleted. |

**No dangling reference remains** — see §5.1.

---

## 3. Migration report

**No database migrations were created, modified or deleted.**

Repository-wide search of `database/migrations/` for `licen*`, `licence`, `activation`, `purchase_code`, `envato` returned **zero matches**. The licensing implementation stored no data in the database: activation state lived entirely in `storage/app/.license*` and the `license.verified` cache key. There is consequently no orphaned table, column, index, model, factory, seeder or policy to clean up, and no data migration is required.

**Operator note:** if a deployment previously ran with the license cache warm, a stale `license.verified` entry may remain in the cache store. `php artisan optimize:clear` (run below, and recommended on each deployment of this change) removes it. It is inert either way — nothing reads it any more.

---

## 4. Refactoring report

### 4.1 Deleted files (16)

```
app/Services/License/LicenseManager.php
app/Services/License/Updater.php
app/Services/License/                                  (directory, now empty)
app/Http/Middleware/EnsureLicensed.php
app/Http/Controllers/LicenseController.php
app/Http/Controllers/Admin/LicenseController.php
config/license.php
resources/js/Pages/License/Activate.jsx
resources/js/Pages/License/                            (directory)
resources/js/Pages/Admin/License/Index.jsx
resources/js/Pages/Admin/License/                      (directory)
resources/js/Components/Install/LicenseStep.jsx
resources/js/Components/LicenseTypeTabs.jsx
resources/js/lib/licenseLabels.js
storage/app/.license
storage/app/.license_code
storage/app/.license_type
storage/app/updates/                                   (directory + update-7322e47d…sql)
```

### 4.2 Modified files (20)

| File | Change |
|---|---|
| `bootstrap/app.php` | Removed `use App\Http\Controllers\LicenseController;` and `use App\Http\Middleware\EnsureLicensed;`. Removed the entire `/license` route group. Removed `install/activate-license` route. Removed `'licensed'` from the admin route group's middleware. Removed the `'licensed' => EnsureLicensed::class` alias. Updated the admin-panel comment. |
| `routes/admin.php` | Removed `use App\Http\Controllers\Admin\LicenseController;` and the "License & Updates" block (5 routes). |
| `app/Http/Controllers/Install/InstallController.php` | Removed `LicenseManager` import and constructor injection; removed the `licensing` Inertia prop; deleted `activateLicense()`; removed `license_code` / `verify_type` / `client_name` validation rules and the "Envato buyer name" attribute label; removed the activate+verify installation gate from `run()`; removed the now-unused `Illuminate\Validation\Rule` import; renumbered the remaining step comments 1–4. |
| `resources/js/Pages/Install/Setup.jsx` | Removed `LicenseStep` and `licenseCopy` imports and the `KeyRound` icon import; removed the `licensing` prop; removed `license_code` / `client_name` / `verify_type` form fields; removed `licenseOk` state, the conditional License step, its `canAdvance()` case and its render block; steps array is now static (`.filter(Boolean)` dropped). |
| `resources/js/Layouts/AdminLayout.jsx` | Removed the `admin.license` sidebar entry and the now-unused `KeyRound` import. |
| `resources/js/locales/{ar,bn,de,en,es,fr,hi,id,it,ja,ko,pt,ru,tr,zh}.json` (15 files) | Removed top-level `license` object and `admin.license` key. |

### 4.3 What was explicitly **not** done

- No license check was stubbed to `return true`; no UI was merely hidden. Every entry point, service, route, view and config file was removed at its architectural boundary.
- Authentication, authorization/RBAC (`permission:*`, roles), demo-mode restrictions, billing, subscriptions, the installer, WhatsApp functionality, automations and queues are untouched.

---

## 5. Commands executed and results

| # | Command | Result |
|---|---|---|
| 1 | Repository-wide search: `licen[cs]\|purchase_?code\|envato\|codecanyon\|EnsureLicensed\|LicenseManager` over `app config routes bootstrap resources tests database .env .env.example` | ✅ **Only 2 matches remain**, both intentional: the Handsontable `licenseKey` lines in `Pages/Contacts/BulkImport.jsx`. |
| 2 | `php -l` on `bootstrap/app.php`, `routes/admin.php`, `app/Http/Controllers/Install/InstallController.php` | ✅ *No syntax errors detected* (all three). |
| 3 | `composer dump-autoload` | ✅ Exit 0. |
| 4 | `php artisan optimize:clear` | ✅ config, cache, compiled, events, routes, views all `DONE`. (A pre-existing PHP 8.5 `PDO::MYSQL_ATTR_SSL_CA` deprecation notice from `config/database.php` is unrelated.) |
| 5 | `php artisan route:list` | ✅ 504 route lines; **zero** `license` routes. Installer intact: `install.show`, `install.run`, `install.test-database`. |
| 6 | `php artisan test` | ⚠️ **Could not be completed** — 350 failures, all `SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'`. See §7. |
| 7 | `phpstan analyse` (project config, `app/`, level 6) | ✅ **Zero licensing-related and zero `InstallController` errors.** The 722 reported errors are pre-existing baseline drift (PHP 8.4/8.5 deprecations, iterable type hints) present before this change. `phpstan-baseline.neon` contained no licensing entries, so no orphaned baseline rows. |
| 8 | `phpstan analyse bootstrap/app.php` | ⚠️ 1 error: `env()` called outside config at line 126 — that is the pre-existing `TRUSTED_PROXIES` line, untouched by this work, and `bootstrap/` is outside the project's configured phpstan `paths`. |
| 9 | Frontend production build (`vite build`) | ✅ **Built in 11.41s.** `Setup-kWMTTKRi.js` rebuilt without the license step. No `License*` / `Activate*` chunks emitted. Grep of `public/build/` for `purchase code`, `envato`, `license_code`, `admin.license.index` → **no matches**. (Required installing the missing `@rollup/rollup-darwin-arm64` optional binary with `--no-save`; `node_modules/.bin/vite` lacked the exec bit, so vite was invoked via `node node_modules/vite/bin/vite.js`.) |
| 10 | Frontend tests (`vitest run`) | ⚠️ **Could not be completed** — 1 file failed to load. See §7. |
| 11 | Live smoke test (`php -S 127.0.0.1:8899 -t public`) | ✅ See table below. |

### 5.1 Endpoint smoke test

| Request | Status | Expected |
|---|---|---|
| `GET /license` | **404** | ✅ removed |
| `POST /license/activate` | **404** | ✅ removed |
| `GET /admin/license` | **404** | ✅ removed |
| `POST /install/activate-license` | **404** | ✅ removed |
| `GET /install` | **200** | ✅ installer reachable |

Inertia payload for `GET /install`:
- `component: Install/Setup`
- props: `errors, csrf_token, flash, auth, currentWorkspace, workspaces, locale, dir, i18n, supportedLocales, rtlLocales, currencies, displayCurrency, theme, demo_mode, app_version, onboardingSummary, requirements, defaults`
- **`licensing` prop absent** ✅
- **`app_version` still present** ✅ (confirms rule 8 was honoured)

The PHP dev-server log for the session shows only the four 404s above — **no outbound request to the former license server was made** during page render or installer load. This is structurally guaranteed: the only code that ever issued those requests (`LicenseManager::http()`) no longer exists in the codebase.

---

## 6. Manual review checklist

- [ ] **Restore `node_modules` properly** (`rm -rf node_modules && npm ci`) — the checked-out tree has non-executable `.bin` shims and a missing `@rollup/rollup-darwin-arm64`. Pre-existing, unrelated to this change.
- [ ] **Fix `resources/js/__tests__/setup.js`** — it contains JSX but has a `.js` extension, so vitest cannot parse it. Rename to `setup.jsx` (and update `vitest.config.js`). Pre-existing, unrelated to this change.
- [ ] **Run `php artisan test` against a reachable MySQL instance** and confirm the suite passes.
- [ ] Review `bootstrap/app.php` admin route group — confirm `['web', 'auth:admin', 'demo']` is the intended stack now that `licensed` is gone.
- [ ] Confirm no deployment script, Dockerfile, CI job, cron entry or ops runbook outside this repository references `/license`, `admin.license.*`, `storage/app/.license*`, `LICENSE_SERVER_URL`, `LICENSE_API_KEY` or `LICENSE_PRODUCT_ID`.
- [ ] Confirm no production `.env` on any server still sets `LICENSE_*` variables (they are now inert but should be tidied).
- [ ] Delete `storage/app/.license`, `.license_code`, `.license_type` and `storage/app/updates/` on **every existing deployed instance** — they are stale activation tokens and are no longer read.
- [ ] **Rotate/revoke the license-server API key** that was baked into the old `config/license.php`, if that server is still operated. It was distributed in every prior release.
- [ ] Decide on a replacement release/update channel now that the license-server-backed auto-updater is gone (updates are now a manual deploy).
- [ ] Review whether the `view_settings` / `manage_settings` permission set should be trimmed now that the License page no longer consumes them (they are used by many other admin screens, so no change was made).
- [ ] Confirm the removed `license` / `admin.license` translation keys are not referenced by any external/CMS-authored content.

## 7. Verification that could not be completed

| Item | Why | Assessment |
|---|---|---|
| **`php artisan test`** | The suite requires a live MySQL server. `phpunit.xml` has the sqlite/in-memory lines commented out (lines 26–27), so tests use the `.env` MySQL connection. `mysql -h127.0.0.1 -uroot` → `ERROR 1045 (28000): Access denied`. All 350 failures are the identical connection-auth error at `Connection.php:838`, raised before any application code runs. | **Environment limitation, pre-existing and unrelated.** No test in the suite referenced licensing (`grep -rl licen tests/` → no matches), so no test needed updating or deleting. |
| **`vitest run`** | Fails to load `resources/js/__tests__/components.test.jsx` because its setup file `resources/js/__tests__/setup.js:8` contains JSX in a `.js` file, which vite refuses to transform. That file was **not touched** by this work. | **Pre-existing and unrelated.** Zero tests ran, so this change is not covered by frontend tests either way. |
| **End-to-end install without a purchase code** | Cannot run a real installation without a reachable MySQL database. | **Partially verified:** `GET /install` returns 200, renders `Install/Setup`, and the Inertia payload no longer carries a `licensing` prop — so the wizard now builds a static 5-step flow (Requirements → Application → Database → Admin → Finish) with no License step and no code field. Server-side, `InstallController::run()` no longer validates or requires `license_code`/`client_name`, and the activation gate is gone. The remaining install path (migrate, seed, create admin, mark installed) is unchanged code. |
| **Authenticated admin / client area access** | Requires a seeded database and credentials. | **Not exercised.** The change to these areas is limited to removing `'licensed'` from the admin route group's middleware list and deleting one sidebar nav entry; `auth:admin`, `demo`, and all `permission:*` gates are unchanged. `php artisan route:list` resolves all 504 routes without error, which proves every remaining controller and middleware reference still binds. |
| **Confirming zero network traffic to the license server under full app browsing** | Requires a running, installed instance. | **Structurally guaranteed rather than empirically measured:** the only HTTP client that ever contacted the license server was `LicenseManager::http()`, and neither that class nor any reference to it exists in the codebase (verification command #1). The smoke-tested requests produced no such traffic. |
