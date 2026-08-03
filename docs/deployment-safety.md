# Deployment Safety — proposal

**Status: proposal only. Nothing here is built.**
Written for a non-developer operator. Investigated against this codebase on 2026-08-03.

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
| Database backup command | `php artisan db:backup` — **exists but has an open security flaw** (below) |
| Database **restore** command | **None. Does not exist.** |
| `Dockerfile` / `docker-compose.yml` | **None** (only a queue-worker compose file and a Supervisor config) |
| Web server config (nginx/apache) | **None in the repo** |
| Deployment script or documentation | **None** |
| Scheduled tasks needing cron | **16** |
| Background queue worker | Required (`database` driver) |

**Two things to know before relying on anything here.**

1. `db:backup` carries the security flaw recorded as **SEC-003**: it puts the database
   password directly into a shell command, where any other user on the server can read it
   from the process list. It also does not escape values, so a password containing certain
   punctuation could break it or run unintended commands. **It should be fixed before you
   depend on it.** Small job — under a day.
2. **There is no restore command.** A backup you cannot restore is not a backup. Restoring
   currently means typing MySQL commands by hand. This is the single biggest gap.

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

- `php artisan db:backup` exists, uploads to a storage disk, but has the **SEC-003** flaw.
- **No restore command exists.** Restoring means hand-typing MySQL commands.

**What I would propose building (not built yet):**

1. Fix `db:backup` (SEC-003) — under a day
2. Add `php artisan db:restore --file=<backup>` with a confirmation prompt — 1–2 days
3. Schedule daily automatic backups — a few hours
4. **Practise a restore into staging, on a calm afternoon, before you ever need it.** An
   untested backup is a guess.

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

## Suggested order

1. **Fix `db:backup` (SEC-003) and build `db:restore`** — without these, nothing else here is trustworthy
2. **Practise a restore into a scratch database** — proves the backup works
3. **Stand up staging** — before `fix/workspace-context` goes anywhere near production
4. **Add the emergency-brake flag** — as part of the isolation-scope work, not after
5. **Automate deploys** — once the manual process is understood well enough to automate

Item 1 is the prerequisite for everything else. Today there is no verified way to undo a bad
deploy, and that is the real risk — larger than any individual code change we have discussed.
