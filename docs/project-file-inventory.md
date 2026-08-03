# Project File Inventory & Modification-Safety Baseline

**Generated:** 2026-08-02
**Working directory:** `/Users/honey/Desktop/whatsmine-V1.5.0`
**VCS:** none — not a Git repository (`git status` → `fatal: not a git repository`)

Because there is no Git, a SHA-256 manifest was captured **before** the audit began and re-verified **after** it finished. See §6.

---

## 1. Baseline manifest

| Property | Value |
|---|---|
| Files hashed | **994** |
| Algorithm | SHA-256 |
| Manifest digest (initial) | `53fbe165b7357d9ac7b490f6adfe2132aaf291ff8d6a81ef8100b770e1786f88` |
| Manifest location | scratchpad (outside the repository) |

**Excluded from the manifest** (volatile or vendored, not application source):
`vendor/`, `node_modules/`, `storage/logs/`, `storage/framework/`, `public/build/`, `.DS_Store`

---

## 2. File counts

| Metric | Count |
|---|---|
| Total files (excl. `vendor/`, `node_modules/`) | 1,304 |
| PHP | 694 |
| JSX | 187 |
| SVG | 30 |
| JS | 26 |
| JSON | 23 |
| PNG | 6 |
| NEON (PHPStan) | 2 |
| Other (yml, xml, tsx, css, ico, htaccess, …) | ~14 |

## 3. Main directories

| Directory | Purpose | PHP files |
|---|---|---|
| `app/Modules/` | 10 business modules (modular monolith) | 204 |
| `app/Http/` | Controllers, middleware, requests | 128 |
| `app/Models/` | Core Eloquent models | 36 |
| `app/Services/` | Cross-cutting services (billing, analytics, storage) | 35 |
| `app/Notifications/` | Notification classes | 17 |
| `app/Console/` | Artisan commands | 16 |
| `app/Events/` | Domain events | 16 |
| `app/Listeners/` | Event listeners | 15 |
| `app/Providers/` | Service providers | 4 |
| `app/Policies/` | Authorization policies | 4 |
| `app/Support/` | Helpers | 3 |
| `app/Jobs/` | Root-level queue jobs | 2 |
| `app/Contracts/` | Interfaces | 1 |
| `app/Mail/` | Mailables | 2 |
| `routes/` | 9 route files | — |
| `database/migrations/` | 49 root migrations (+21 in modules) | — |
| `resources/js/` | React/Inertia frontend | — |
| `tests/` | 81 test files | — |
| `docker/` | Supervisor queue-worker config only | — |
| `docs/` | Audit output + prior licensing-removal report | — |

## 4. Technology stack (detected, not assumed)

| Layer | Value | Source |
|---|---|---|
| Framework | **Laravel 12.54.1** | `php artisan about` |
| PHP (runtime) | **8.5.8** | `php artisan about` |
| PHP (required) | **^8.2** | `composer.json` |
| Composer | 2.10.2 | `php artisan about` |
| Frontend | **React 19.2 + Inertia.js 2.3** | `package.json` |
| Build tool | **Vite 6.4.1** (`laravel-vite-plugin` 1.2) | `package.json`, `vite.config.js` |
| CSS | Tailwind CSS 3.x + `@tailwindcss/forms` | `package.json` |
| Node / npm | 26.5.0 / 11.17.0 | `node -v`, `npm -v` |
| Test (PHP) | PHPUnit 11.5 | `composer.json` |
| Test (JS) | Vitest 4.1 + Testing Library | `package.json` |
| Static analysis | PHPStan level 6 via Larastan 3.9 | `phpstan.neon` |
| Lint | ESLint 9 + Prettier 3 | `eslint.config.js` |

## 5. Drivers in effect

| Driver | Value | Note |
|---|---|---|
| Database | **mysql** (MySQL 9.3.0) | 94 tables, InnoDB throughout |
| Cache | **database** | not Redis |
| Session | **database** | |
| Queue | **database** | supervisor config assumes **redis** — mismatch, see AUD-PERF-002 |
| Broadcasting | **log** | realtime disabled |
| Mail | **log** | nothing sent |
| Filesystem | **local** | S3 supported but unconfigured |
| Logs | stack → single | plus `errors`, `json` channels |

## 6. Post-audit verification

| Property | Value |
|---|---|
| Files hashed (final) | **994** |
| Manifest digest (final) | `53fbe165b7357d9ac7b490f6adfe2132aaf291ff8d6a81ef8100b770e1786f88` |
| Result | ✅ **IDENTICAL — no application file was modified, added, or removed** |

Files created by this audit (all inside `docs/`, all new, none overwriting existing source):

- `docs/complete-project-audit-report.md`
- `docs/project-file-inventory.md`
- `docs/project-module-map.md`
- `docs/project-security-findings.md`
- `docs/project-remediation-roadmap.md`

> `docs/licensing-removal-report.md` pre-existed this audit and was not touched.

## 7. Missing project documentation

| File | Status | Impact |
|---|---|---|
| `README.md` | **absent** | No setup, no architecture overview, no onboarding path |
| `AGENTS.md` | absent | — |
| `CLAUDE.md` | absent | — |
| `CONTRIBUTING.md` | **absent** | No contribution or branching guidance |
| `LICENSE` | absent | `composer.json` declares `proprietary`; no licence text shipped |
| `.env.example` | present ✅ | Well-commented; the de-facto configuration documentation |
| `Dockerfile` / `docker-compose.yml` | **absent** | Only `docker-compose.queues.yml` + a supervisor conf exist |
| `CHANGELOG.md` | absent | No release history |
