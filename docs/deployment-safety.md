# Deployment Safety

> # 🚧 HARD GATE — NOT NEGOTIABLE
>
> **NO REAL CUSTOMER DATA TOUCHES THIS SYSTEM UNTIL ALL SEVEN ARE TRUE:**
>
> **Recoverability** — added 2026-08-03
>
> 1. ☑ `db:backup` is fixed (SEC-003 — closed 2026-08-07, see below)
> 2. ☑ `db:restore` exists and works — round-trip tested (backup → destroy → restore → verify)
> 3. ☑ The owner has **personally practised a restore**, with their own hands —
>        **done 2026-08-07.** Run by hand against `whatsmine_test`: backup, `--dry-run`, then
>        the real restore — database name typed to confirm, the pre-restore **safety backup
>        fired as designed**, finished with `✅ Restore finished.` The guards were exercised by
>        the person who will need them at 3am, which is the only test of them that counts.
> 4. ☐ Production runs on a **fresh, clean VPS** — not the testing box promoted in place
>        — **a deploy-day action, not a code change. Nothing further is blocked on
>        development**; there is no engineering work left standing between here and a first
>        customer.
>
> ⚠️ **ITEM 1 IS NOT SELF-EXECUTING — the production host's cron must invoke `schedule:run`
> every minute.** Noted 2026-09-02. `db:backup` is scheduled in `routes/console.php`
> (`041779a`, `dailyAt('01:30')`), but a Laravel schedule is inert on a box whose crontab never
> calls it: the command is registered and nothing runs it, which looks exactly like a working
> backup until the morning you need one. This is a **second deploy-day action** alongside item 4
> — a fresh VPS with no cron entry gives you a backup command nobody runs. Verify with
> `crontab -l` on the production host, then confirm a dump actually lands the next day. This
> development machine has no crontab at all, so nothing fires here.
>
> **Security** — added 2026-08-04
>
> 5. ☑ **DEEP-03** — impersonation is gated by a dedicated permission — **closed 2026-08-07.**
>        Shipped `impersonate_clients` (SUPER_ADMIN only, by migration so existing installs
>        get it). *Differed from the write-up:* the sweep of all 74 non-GET admin routes found
>        **four** actions gated by read permissions, not one — assign-plan, refund and
>        support-reply too — and the `SUPPORT` role description ("View clients and
>        subscriptions only") was the delivery mechanism, not a cosmetic issue.
> 6. ☑ **SEC-004** — stored-XSS upload path closed — **closed 2026-08-07.**
>        *The write-up was half wrong:* the **logo** rule already rejected SVG (Laravel 12's
>        `image` rule dropped svg unless passed `allow_svg`) — only the favicon accepted it.
>        The real defect was the stored **filename**: `mimes:` validates sniffed content while
>        every site built the path from `getClientOriginalExtension()`, so a GIF-magic polyglot
>        named `payload.html` was stored as `.html` and served as text/html from this origin.
>        **Tenant-reachable via `POST /media`, not admin-only.** Fixed at all four sites.
> 7. ☑ **SEC-006** — tokens now expire, and are revoked — **closed 2026-08-07.**
>        Mobile 30 days, API tokens 90 days by default, an explicit choice capped at 1 year.
>        *The write-up understated it:* nothing **revoked** tokens either — a token survived
>        deactivation of its own user and survived a password change, both proven. Revocation
>        now lives on the `User` model (five writers of `status`/`password` were found,
>        including two admin paths that change someone else's password) with
>        `EnsureUserIsActive` re-checking every API request.
>
> **All seven. Not six. Not "mostly done."**
>
> **Six of seven are closed. Box 4 — a fresh, clean VPS — is the only one left, and it is a
> deploy-day action rather than development work.** Until it is ticked the answer to "can we
> onboard a customer yet?" is still **no**. But nothing is waiting on code: every
> recoverability and security condition is met and tested.
>
> A note worth keeping: **all three security findings differed from how they were recorded** —
> one was four bugs, one named the wrong defect and the wrong severity, one described half of
> its own problem. Every difference was found by opening the file rather than trusting the
> document. Treat the remaining entries in `project-security-findings.md` and
> `project-security-deep-dive.md` the same way.
>
> Gate-optional but belongs in the same pass: **DEEP-05** — `assignPlan` gated by the
> read-only `view_clients` permission. A billing-integrity bug: a read permission can change
> what a customer is paying for. Fix it while the others are open.
> **☑ Closed 2026-08-07**, in the DEEP-03 branch — it was one of the four sites the sweep
> found. Now `manage_subscriptions`.
>
> This is a rule, not a recommendation. It exists because the predictable failure mode is
> deferring these once the structural work starts feeling good — which is precisely when the
> cost of not having them begins to rise. Recorded and committed to by the project owner on
> 2026-08-03, extended 2026-08-04.
>
> If you are reading this and any box is unticked, the answer to "can we onboard a customer
> yet?" is **no**.
>
> ---
>
> ### ✅ DEEP-03 was a white-label blocker, not just an admin-panel issue — CLOSED 2026-08-07
>
> **Fixed.** Kept in full because the reasoning is why the partner tier can now proceed, and
> because the same shape will recur the moment a new privileged admin action is added.
>
> ~~Today~~ **Until 2026-08-07**: an admin granted only **`view_clients`** — the permission you
> would give a read-only support or analyst role — could fully impersonate **any client's
> administrator** and perform every action that user can. `ClientPolicy::impersonate()`
> returned `hasPermissionTo('view_clients')`, identical to `view()`.
>
> It now requires **`impersonate_clients`**, held by SUPER_ADMIN only. Both layers were
> changed — the policy and the route middleware were checking the same `view_clients`, which
> reads as defence in depth and was not.
>
> **Under the partner model this gets materially worse.** The attacker is no longer only a
> platform employee: it is **a partner's staff account**. If a partner's low-privilege user
> can reach impersonation, the blast radius is **other partners' customers** — precisely the
> boundary the white-label tier exists to guarantee.
>
> A reseller platform whose read-only role confers cross-tenant impersonation is not sellable.
> ~~**This must be fixed before the partner tier ships, independently of the customer-data
> gate.**~~ **Done — the partner tier is no longer blocked on this.**
>
> Still true, and the reason to keep reading this: the partner model raises the stakes of
> *every* permission mistake, because the attacker becomes a partner's staff account and the
> blast radius becomes other partners' customers. `BUG-010` in `docs/found-bugs.md` records
> the next instance — `client_role` (`administrator`/`staff`) is gated by **nothing**.



**Status: proposal only. Nothing here is built.**
Written for a non-developer operator. Investigated against this codebase on 2026-08-03.

---

## ⚠️ Current reality — read this first (updated 2026-08-03)

**There is no production environment, and there are zero customers.**

| Environment | What it actually is |
|---|---|
| This Mac | Development. Dev data only. |
| **Hostinger VPS** | **Effectively STAGING.** Bought for the owner's own testing. **Zero live customers, no customer data.** |
| Production | **Does not exist yet.** |

Three consequences, and they matter:

**1. The VPS is staging, not production.** Nothing on it is customer-facing. A mistake there
costs time, not trust.

**2. Production must start CLEAN.** When the first real customer approaches, production should
be a **fresh VPS**, provisioned deliberately — *not* this testing box promoted in place. The
testing box has accumulated unknown state: hand-edited config, test records, half-finished
experiments, possibly an old database schema. Promoting it means inheriting all of that
invisibly. Resist the temptation; it will feel like a shortcut and it is not.

**3. ⚠️ The VPS runs the original CodeCanyon build and does NOT have any of our fixes.**

It therefore still contains:

| Missing fix | Consequence on that box |
|---|---|
| Webhook SSRF fix | The SSRF vulnerability is **live** there — a customer-supplied webhook URL can read internal addresses and return the response |
| Test-database isolation | `php artisan test` on that box would **drop its database** |
| WooCommerce signature hardening | Signature check still skippable |
| Licensing removal | Still contains the licence/activation system |
| The `whatsmine` → AutomationXpert work | None of it |

**Do not run `php artisan test` on the VPS.** Until it is redeployed from this repository, treat
it as a divergent copy: useful for trying things, not a reference for how the app behaves.

### VPS sandbox rules — agreed 2026-08-03

The VPS is a **throwaway sandbox**. Treat everything on it as disposable and assume it may be
compromised, because the SSRF hole is live there.

- ❌ **No real credentials** — no live payment gateway keys, no production API keys
- ❌ **No real customer data** — not even a sample export
- ❌ **No Meta / WhatsApp Business connection** to a real business account
- ❌ **No live payment gateway** in anything but sandbox/test mode
- ❌ **Never run the test suite on it** — it would drop the database
- ✅ Fake data, test credentials, sandbox gateway modes only
- ✅ Fine to wipe and rebuild at any time — nothing on it should be worth keeping

When production is stood up on a fresh VPS, the sandbox should be **rebuilt from `master`** or
destroyed. It should never become production, and it should never hold anything real.

The divergence also means the VPS cannot validate our changes. Exercising Phase 0 work there
requires redeploying it from `master` first.

---

Plain-language glossary, used throughout:

- **Production** — the live app your customers use.
- **Staging** — a private copy of the app, with copied data, where changes are tried first.
- **Deploy** — copying new code onto a server and restarting things.
- **Migration** — a script that changes the database's *shape* (adds a column, etc.).
- **Rollback** — putting the previous version back after something goes wrong.

---

## ☎️ Who to call — FILL THIS IN NOW, not during an incident

Leave nothing blank. If a line does not apply, write "n/a" so you know it was considered
rather than forgotten. **Do not put passwords in this file — it is in version control.**
Record only *where* a credential lives (e.g. "1Password → Server vault").

| What | Detail | Fill in |
|---|---|---|
| **Hosting provider** | Name (DigitalOcean, Hetzner, …) | |
| | Support URL | |
| | Support phone / ticket page | |
| | Account email used to log in | |
| | Out-of-hours support? Yes / No | |
| **Server** | Production IP or hostname | |
| | How you log in (SSH? provider console?) | |
| | Where the SSH key or password lives | |
| **Database** | Host / port | |
| | Database name | |
| | Where the credentials live | |
| **Domain / DNS** | Registrar (GoDaddy, Namecheap, …) | |
| | Login location | |
| **Backups** | Where backup files are stored | |
| | How to reach them if the server is down | |
| **Developer** | Name | |
| | Phone (for genuine emergencies) | |
| | Email | |
| | Hours they are normally reachable | |
| | Agreed response time out of hours | |
| **Second developer / agency** | Fallback if the first is unreachable | |
| **GitHub** | Repo URL | `https://github.com/honeygarg389/Automation_Xpert` |
| | Account that owns it | `honeygarg389` |
| **Payment providers** | Which gateways are live | |
| | Support contact for each | |
| **WhatsApp / Meta** | Business account ID | |
| | Meta support contact | |

**Print this page, or keep a copy on your phone.** If the server is down you may not be able
to reach a wiki, and if this file is only on the server you cannot read it at all.

---

## What exists today (facts, not assumptions)

| Thing | Status |
|---|---|
| Staging environment | **None** |
| Feature-flag package (e.g. Laravel Pennant) | **Not installed** |
| Config-driven switches | Yes — `config/saas.php`, and `app.demo_mode` is an existing on/off pattern |
| Runtime settings store | Yes — `SystemSetting`, a database key/value table |
| Database backup command | `php artisan db:backup` — exists, and **SEC-003 is closed** (2026-08-07, see below). Scheduled daily since 2026-09-02. |
| Database **restore** command | **`php artisan db:restore` now exists**, with seven guards, and is round-trip tested (2026-08-07). |
| `Dockerfile` / `docker-compose.yml` | **None** (only a queue-worker compose file and a Supervisor config) |
| Web server config (nginx/apache) | **None in the repo** |
| Deployment script or documentation | **None** |
| Scheduled tasks needing cron | **18** as of 2026-09-02 — ⚠️ drifts whenever a job is added (it was 16 on 2026-08-03). Count the rows of `php artisan schedule:list` rather than trusting this number. |
| Background queue worker | Required (`database` driver) |

**Two things to know before relying on anything here.**

1. ~~`db:backup` carries the security flaw recorded as **SEC-003**~~ — **FIXED 2026-08-07.**

   **The original description was partly wrong, and testing rather than trusting it is how
   that surfaced.** It asserted the password was readable "from the process list". That
   specific claim does not hold: `exec("MYSQL_PWD=… mysqldump …")` puts the value in the
   child's *environment*, not its argv, so `ps -o command` never showed it. Verified with an
   isolated probe: 0 matches in argv.

   What was actually true:

   - **Command injection — confirmed, not theoretical.** The password was interpolated into a
     shell string unescaped. A password of `x; touch /tmp/pwned; echo` executed the injected
     command; proven by observing the file appear.
   - **Environment exposure — confirmed.** The value was visible via `ps -E`, and on Linux —
     which production runs on — is readable from `/proc/<pid>/environ` by the same user or
     root.
   - `escapeshellarg()` was applied to the temp path (harmless) and to none of the password,
     host, port, user or database name.

   **The fix.** `mysqldump`/`mysql` are now invoked through `Symfony\Component\Process` with an
   **argument array**, so no shell parses anything and injection is structurally impossible
   rather than merely escaped. The password travels in a **0600 `--defaults-extra-file`**
   deleted in a `finally` — chosen over `MYSQL_PWD` precisely because of the `/proc` exposure
   above. Gzip moved into PHP; a shell pipe would have reintroduced a shell.

   Also fixed while in here: the dump now passes `--single-transaction --routines --triggers`.
   Routines and triggers were being silently omitted, which is a restore that quietly loses
   things. And backup filenames gained a random suffix — second precision alone meant a safety
   backup taken in the same second as another backup **silently overwrote it**.

2. **`php artisan db:restore` now exists**, with seven guards, and is round-trip tested
   (backup → destroy → restore → verify) — **closed 2026-08-07.** This was recorded here as
   "the single biggest gap" while restoring meant typing MySQL commands by hand; it no longer
   does.

---

## (a) Staging environment

### What it is, and why it matters here

A second, private copy of the whole app — its own code, its own database, its own web
address (e.g. `staging.yourdomain.com`). You try every change there first. If it breaks,
customers never see it.

For the upcoming work this matters more than usual. `fix/workspace-context` changes how the
app decides *which customer's data you are looking at*, across roughly 90 places. That is
precisely the kind of change where "it looked fine" is not good enough.

### What it needs

Identical to production, just smaller:

- PHP **8.2+** with: pdo, pdo_mysql, mbstring, openssl, tokenizer, ctype, json, bcmath, fileinfo, curl
- **MySQL 8+**
- Node.js (to build the frontend assets)
- A **cron** entry, so the 16 scheduled tasks run
- A **background queue worker** kept alive (Supervisor — a config already exists at `docker/supervisor/`)
- Its own `.env`, with `APP_ENV=staging`, `APP_DEBUG=false`, and **its own database**

### Rough cost

Approximate, and worth checking current prices — I cannot verify live pricing.

| Option | ~Monthly | Notes |
|---|---|---|
| **Same server, second site** | **$0** | Cheapest. A second folder + second database on your existing server. Risk: a staging mistake can affect production (they share CPU, memory, disk). |
| **Separate small VPS** (Hetzner, DigitalOcean, Vultr) | **~$6–15** | **Recommended.** Truly separate, so staging cannot take production down. |
| **Managed platform** (Laravel Forge + VPS) | **~$12 + server** | Forge automates deploys and can add a deploy button and rollback. Easiest to operate long-term; a developer sets it up once. |

**My recommendation: a separate small VPS.** The isolation is the entire point, and sharing a
server with production undermines it for the sake of a few dollars.

### Staging data

Copy production's database into staging, **then scrub it**: replace real customer emails and
phone numbers with fake ones, and clear stored provider credentials. Otherwise a staging
mistake emails real customers. Scrubbing needs a written script — **a developer task.**

---

## (b) Feature flags

### What exists

No flag package is installed. But this codebase already uses the pattern, in the form of
`app.demo_mode`, which switches whole behaviours on and off from a single setting.

Two mechanisms are available without installing anything:

| Mechanism | Change takes effect | Needs server access? |
|---|---|---|
| **Config/env value** (like `demo_mode`) | after a short command on the server | Yes |
| **`SystemSetting`** (database key/value) | immediately, from the admin panel | **No** |

### Can the workspace global scope be gated behind a flag?

**Yes.** The scope is registered in one place — the `BelongsToWorkspace` trait — so a single
condition controls all 27 models:

```
if (config('tenancy.enforce_workspace_scope')) {
    // register the scope
}
```

Setting `ENFORCE_WORKSPACE_SCOPE=false` and clearing the config cache switches the new
behaviour off everywhere, without changing code and without a full deploy.

**An important caution, stated plainly.** This scope is a *security* control — it is what
stops one customer seeing another's data. A flag that turns it off is also a flag that turns
protection off.

So:

- It must default to **ON**. Never ship with it off.
- Treat it as an **emergency brake**, not a routine setting.
- It should not be switchable from the admin panel (`SystemSetting`), because an
  accidental click would disable data isolation. Server-side config only.

For ordinary, non-security features later, `SystemSetting` is the better home — you can flip
those yourself from the admin UI.

**Laravel Pennant** is Laravel's official flag package. It is genuinely nice, but it is
another dependency to install and learn. For one emergency brake, a config value is simpler
and equally effective. I would not install Pennant just for this.

---

## (c) Backups and restore

### Before every deploy

1. Back up the database (a full copy of all data)
2. Note the current version (the git commit), so you know what to return to
3. Keep the backup **off the server** — a server failure must not take the backup with it

Retention worth having: keep every deploy backup for 7 days, plus one daily backup for 30
days.

### The honest position today

**Updated 2026-08-07.**

- `php artisan db:backup` exists, uploads to a storage disk, and **SEC-003 is closed**.
- **`php artisan db:restore` now exists**, with seven guards, and is round-trip tested.

### `db:restore` — the guards, and why each exists

This is the most dangerous command in the codebase: it overwrites a database. Each guard is
tested, and each was stash-checked — removed, and its test shown to fail.

| # | Guard | Why |
|---|---|---|
| 1 | Refuses in production unless `--force` **and** confirmed | Laravel's `ConfirmableTrait`, the house pattern |
| 2 | **Requires typing the database name** | A y/n prompt is muscle memory; typing `whatsmine` is a deliberate act. `--force` does NOT bypass this |
| 3 | **Takes a safety backup first, and aborts if it fails** | This is what makes a mistake recoverable rather than terminal |
| 4 | Verifies the archive before touching the DB | Rejects corrupt/truncated gzip, archives with no `CREATE TABLE`, and archives taken from a *different* database |
| 5 | `--dry-run` | Reports target, archive, table count and size; changes nothing |
| 6 | Never guesses the target | There is deliberately no `--database` option; the target is always the configured connection |
| 7 | Refuses a non-`_test` target without `--force` | The bootstrap guardrail protects the suite; this puts the same rule *inside the command*, so it does not depend on the caller remembering |

Guards 2 and 3 are the non-negotiable pair.

### Still owed — recorded, not fixed

- **Backups land inside the repo tree** (`storage/app/private/backups/` via the `local` disk).
  This contradicts "keep the backup off the server" above: a backup on the same box dies with
  the box. Needs an off-server disk (S3 or equivalent) before production.
- **`db:backup` reads the whole dump into memory** when uploading
  (`file_get_contents($tmpPath)`). Irrelevant at 0.12 MB, not irrelevant on a real production
  database. The dump itself is streamed; only the upload is not.

**What was proposed here — all four are now built** (with the two caveats under item 3):

1. ☑ Fix `db:backup` (SEC-003) — **done 2026-08-07**
2. ☑ Add `php artisan db:restore --file=<backup>` with a confirmation prompt —
   **done 2026-08-07**, with seven guards
3. ☑ Schedule daily automatic backups — **done 2026-09-02** in `041779a`. `db:backup` now runs
   `dailyAt('01:30')` in `routes/console.php`, with `->withoutOverlapping()->onOneServer()` like
   every other command entry. 01:30 sits after `smartqr:aggregate` (00:20) so the dump is not
   taken mid-aggregation, and before both destructive weekly prunes (Sun 03:00 scans, Sun 04:00
   exports), so a backup always exists before anything deletes rows or files.

   ⚠️ **DEPLOY-DAY ACTION, NOT DONE BY THIS COMMIT: the target machine's cron must actually
   invoke `schedule:run`.** A Laravel schedule entry is inert on a box whose crontab does not
   call it every minute — the command is registered and nothing runs it, which looks identical
   to a working backup until the day you need one. This development machine has **no crontab at
   all** (`crontab: no crontab for honey`), so nothing fires here regardless. Verify on the
   production host with `crontab -l`, then confirm a backup actually lands the following morning
   rather than assuming the schedule implies execution.

   ⚠️ **Retention is still missing.** Nothing deletes an old dump. A naive age-based prune is
   not safe to bolt on: `db:restore` writes its own pre-restore safety backup into the same
   directory, and that is the one archive that must never be reaped.
4. ☑ **Practise a restore into staging, on a calm afternoon, before you ever need it.** An
   untested backup is a guess. — **done 2026-08-07, by hand.**

Step 4 is the one people skip and the one that matters.

---

## (d) Rollback — what to do at 11pm when something is wrong

Read this top to bottom before acting. **Do not skip step 1.**

### Step 1 — Stop the bleeding (30 seconds)

Put the app into maintenance mode. Visitors see a holding page instead of errors or, worse,
each other's data:

```
php artisan down --retry=60
```

Do this **first**, before diagnosing. It is instantly reversible.

### Step 2 — Decide which kind of problem you have

**Was the last deploy code-only, or did it also change the database?**

Check the deploy notes for the words *migration* or *schema*.

- **Code-only** → Step 3. Quick and safe.
- **Database changed** → Step 4. Slower, more care needed.

If you cannot tell, **assume the database changed** and use Step 4.

### Step 3 — Rolling back code only

```
git log --oneline -5          # find the commit before the bad one
git checkout <that-commit>
php artisan optimize:clear
php artisan up
```

Check the site. If it is healthy, you are done. Tell your developer what you did.

### Step 4 — Rolling back when the database changed

This is where you may need help, and that is fine — the app is safely in maintenance mode
and can wait.

1. Confirm you have the pre-deploy backup and know exactly where it is
2. Restore that backup into the database
3. Put the code back as in Step 3
4. `php artisan optimize:clear`
5. `php artisan up`

⚠️ **Restoring a backup erases everything that happened after the backup was taken.** Orders
placed, messages received, and customers created in the meantime are lost. If the app has
been live for hours since the deploy, this trade-off is real — ring your developer before
restoring.

### Step 5 — Confirm

- Home page loads
- You can sign in
- A customer's data looks right
- `storage/logs/laravel.log` is not filling with errors

Then `php artisan up`.

### If the flag is available

If the problem is the workspace isolation scope specifically, try the emergency brake
**before** a full rollback — it is far less disruptive:

```
# set ENFORCE_WORKSPACE_SCOPE=false in .env
php artisan config:clear
```

---

## (e) Pre-deploy checklist

Run every time. Do not skip items because a change "looks small".

**Before**

- [ ] The full test suite passes at or below the recorded baseline (`docs/test-suite-baseline.md`) — no new failing test names
- [ ] The change has been running on **staging** for at least a day
- [ ] Database backup taken **and its file confirmed to exist and be non-empty**
- [ ] Current commit written down, so you know where to return to
- [ ] You know whether this deploy changes the database
- [ ] `php artisan migrate --pretend` reviewed if it does — shows the SQL without running it
- [ ] Not deploying on a Friday, or before you leave for the night
- [ ] Someone who can help is reachable

**During**

- [ ] `php artisan down --retry=60`
- [ ] Deploy the code
- [ ] `php artisan migrate --force` (only if there are migrations)
- [ ] `php artisan optimize:clear`
- [ ] Rebuild frontend assets if they changed
- [ ] Restart the queue worker — **often forgotten**; workers keep running the old code until restarted
- [ ] `php artisan up`

**After**

- [ ] Home page loads
- [ ] Sign in works
- [ ] One real customer workspace shows the right data — **and only its own data**
- [ ] Send one test message end to end
- [ ] Watch `storage/logs/laravel.log` for 10 minutes
- [ ] Confirm the queue is processing, not stuck
- [ ] Keep the backup for at least 7 days

---

## What you cannot realistically do alone

Honest assessment. Not a judgement — these are developer tasks.

| Task | Why |
|---|---|
| **Setting up the staging server** | Server provisioning, web server config, PHP, MySQL, cron, Supervisor. One-time, ~1 day. |
| **Writing the data-scrubbing script** | Must know which columns hold personal data and credentials. |
| **Fixing `db:backup` (SEC-003)** | Code change. |
| **Building `db:restore`** | Code change, and it must be careful — a restore command that misfires destroys data. |
| **Adding the feature flag to the scope** | Code change in the trait. |
| **Automating deploys** (Forge, or a script) | Removes most of the manual checklist and most of the human error. Worth the money. |

| Task | You can do this yourself |
|---|---|
| Running the pre-deploy checklist | Yes |
| Taking a backup, once the command is fixed | Yes — one command |
| `php artisan down` / `up` | Yes |
| Code-only rollback (Step 3) | Yes, with the steps above |
| Flipping the emergency-brake flag | Yes |
| Deciding **not** to deploy at 6pm on a Friday | Yes — and it is the highest-value one on this page |

---

## Suggested order — REVISED for "no production, zero customers"

The original order put backup/restore first, on the reasoning that there was no verified way
to undo a bad deploy. **That reasoning was written before I knew there are no customers.** It
does not survive that fact, and I would rather revise it than defend it.

Backup and restore protect *data*. With zero customers, the only data at risk is dev and test
data that migrations and seeders can rebuild in minutes. The genuinely irreplaceable asset —
the code and these documents — is already backed up on GitHub.

### Do now, while the house is empty

1. **The structural work** — `fix/workspace-context`, the isolation scope, the partner tier.
   These are far cheaper to do wrong now than later. A mistake costs an afternoon, not a
   customer relationship or a breach notification.
2. **The emergency-brake flag** (`ENFORCE_WORKSPACE_SCOPE`) — build it *with* the scope, not
   bolted on afterwards.

### Do before the first real customer — non-negotiable

3. ☑ **Fix `db:backup` (SEC-003) and build `db:restore`** — done 2026-08-07
4. ☑ **Practise a restore** into a scratch database, on a calm afternoon — done 2026-08-07
5. ☐ **Provision a fresh production VPS** — clean, never the testing box.
   **The last hard-gate item.**
6. ☐ **Adopt the pre-deploy checklist** as routine
7. ☐ **Schedule `db:backup`** — it is built and hardened but nothing runs it on a timer yet

### Do when it starts paying for itself

7. **Automate deploys** (Forge or a script) — once the manual process is well understood

**The one thing that does not move:** items 3–6 must be complete *before* the first customer's
data exists. It is tempting to defer them again once the structural work feels good. Don't —
that is exactly the moment the cost of not having them starts rising sharply.
