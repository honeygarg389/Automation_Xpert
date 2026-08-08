# Found Bugs

Defects discovered incidentally while working on Phase 0 (tenant isolation), out of scope for
the branch that found them and tracked here instead of being fixed on the spot or silently
baselined away. Add an entry whenever a bug is found but not fixed in the moment.

Severity: **Critical** (crashes/data loss for ordinary use) · **Medium** (real but narrow or
non-crashing) · **Low** (cosmetic, debt, or static-analysis noise).

---

## BUG-001 — Social post save 500s without `scheduled_at` — ✅ FIXED

- **Severity:** Critical — was user-facing
- **Status:** **FIXED in `5458466`**, branch `fix/social-post-update-500` (cut from `master`), merged into the 1c line. Found 2026-08-03, fixed 2026-08-04.
- **File:** `app/Modules/Social/Http/Controllers/SocialPostController.php`
- **User-facing:** Yes.

**Scope was wider than first recorded.** This entry originally named only `update()`. Fixing
it surfaced **four** unguarded reads, three of them in `store()` — so *creating* a post
without a schedule crashed too, not just editing.

| Method | Line (pre-fix) | Read |
|---|---|---|
| `store()` | ~169 | `'status' => $validated['scheduled_at'] ? …` |
| `store()` | ~172 | `if (! $validated['scheduled_at'])` |
| `store()` | ~181 | `$validated['scheduled_at'] ? 'scheduled' : …` |
| `update()` | ~221 | `$validated['status'] = $validated['scheduled_at'] ? …` |

**Fix:** both methods normalise the key once (`?? null`) before any read, rather than
scattering four guards. The two pre-existing `! empty(…)` checks were already safe.

**Regression cover:** `tests/Feature/Social/SocialPostScheduledAtTest.php` — 6 tests, each
"without scheduled_at" case paired with a counterpart proving the fix did not simply null the
field out, plus one confirming a past date is still rejected. Verified load-bearing: stashing
the fix fails exactly the 3 "without" tests.

**Workaround removed:** the `scheduled_at => ''` shim in `SocialWorkspaceScopingTest` is gone;
those 12 tests pass against the real fix.

**What triggers it.** `update()` validates `scheduled_at` as `nullable`, then does:

```php
$validated['status'] = $validated['scheduled_at'] ? 'scheduled' : 'draft';
```

with no `??` or `empty()` guard. Laravel's `validate()` **omits** an absent nullable key from
its returned array entirely — it does not include it as `null`. So any edit request that
simply doesn't send `scheduled_at` (the normal case for "edit an unscheduled post," since the
field is optional) throws `Undefined array key "scheduled_at"`, which becomes a 500.

**Reproduce:** `PUT /app/social/posts/{post}` with `body` and `target_accounts` but no
`scheduled_at` key at all (not even empty) → 500.

**Fix (not yet applied):** `$validated['status'] = ($validated['scheduled_at'] ?? null) ? 'scheduled' : 'draft';`

**Blast radius (pre-fix):** every workspace; every social post create or edit that did not
explicitly send a scheduled time — plausibly the majority of both.

---

## BUG-002 — 10 pre-existing PHPStan errors in `app/Modules/Social`

- **Severity:** Low — static-analysis debt, not a runtime defect
- **Files:** `app/Modules/Social/Http/Controllers/SocialPostController.php` (property-not-found on `SocialPost::$workspace_id`, `SocialPost::$status`, `SocialAccount::$...` at several lines)
- **Status:** Confirmed pre-existing, not introduced by Phase 0 work.
- **User-facing:** No.

**How it was verified, not assumed.** Found while running `phpstan analyse app/Modules/Social`
after the 1c commit for that module. Rather than assume the 10 errors were mine, the same
controllers were stashed out and PHPStan re-run: **10 errors either way.** Confirmed
unrelated.

**Why it exists.** The same class of error behind ~245 entries already in
`phpstan-baseline.neon` project-wide — PHPStan/Larastan sometimes cannot see an Eloquent
model's magic properties depending on how they're accessed, and flags `$model->column` as
"undefined property" even though it resolves fine at runtime. This file's occurrences were
simply never added to the baseline.

**Deliberately not baselined here.** Baselining silently is how this kind of debt disappears
from view. Recorded instead so it's chosen, not lost.

**Recommended fix:** either add proper PHPDoc `@property` annotations to `SocialPost` and
`SocialAccount` (fixes it for real, helps every future edit to these files), or add to the
baseline explicitly as a tracked decision — not as a side effect of not looking.


---

## BUG-003 — 87 candidate unguarded nullable-key reads across 30 files

- **Severity:** Unknown until triaged — same class as BUG-001, which was Critical
- **Status:** **Open. Scope decision pending** — deliberately not swept into one commit. Found 2026-08-04 by the codebase-wide scan requested alongside the BUG-001 fix.
- **User-facing:** Potentially, wherever a nullable field is genuinely optional in the UI.

Same root cause as BUG-001: Laravel's `validate()` omits an absent `nullable`/`sometimes` key
from its result, so `$validated['key']` on a field the client did not send is an undefined
array key.

**87 candidates across 30 files.** Heaviest:

| Count | File |
|---|---|
| 12 | `Http/Controllers/Client/SettingsController.php` |
| 10 | `Http/Controllers/Admin/ClientController.php` |
| 8 | `Modules/Social/Http/Controllers/SocialPostController.php` *(4 now fixed)* |
| 6 | `Modules/Whatsapp/Http/Controllers/WhatsappTemplateController.php` |
| 5 | `Modules/Whatsapp/Http/Controllers/WhatsappEmbeddedSignupController.php` |
| 4 | `Modules/Broadcasting/Http/Controllers/CampaignController.php` |
| 3 each | `Admin/EmailSystemController`, `Api/V1/MobileConversationController`, `Broadcasting/EmailAiController`, `Inbox/InboxController` |

**This is an upper bound, not a confirmed count.** The scan looks only ~14 characters behind
each access for a guard, so it reports false positives where the access sits inside an
`if (isset(…))` block, after an earlier `$request->has()` check, or on a field the UI always
sends. Real triage should shrink it.

### 📋 Scope policy — DECIDED 2026-08-04, does not need re-deciding

Ruled by the project owner. Do not re-open this question per module.

1. **Do NOT triage all 87 now.** The entry stays open and recorded as-is.

2. **Opportunistic fixes during 1c.** When a file is being touched for 1c work anyway, check
   that file's entries in the table above as part of that module's commit and fix any that are
   **genuinely reachable** — i.e. the field is really optional in the UI, not one the frontend
   always sends. No separate branch, no separate commit; it rides along with the module.
   Cheap because the file is already open and already under test.

3. **Heavy files 1c will not touch get their own pass AFTER Phase 0.** Specifically
   `Http/Controllers/Client/SettingsController.php` (12) and
   `Http/Controllers/Admin/ClientController.php` (10) — neither is in the 1c site inventory.
   Recorded as a named roadmap item so it is not lost when Phase 0 closes.

4. **Reachability is the test, not count.** A fragile pattern on a field the UI always sends
   is debt, not a bug. Prioritise anything reachable from a form with genuinely optional
   inputs — that is exactly the shape BUG-001 had.

**Suggested approach when scoped:** triage by whether the field is genuinely optional in the
UI — a field the frontend always sends cannot trigger the bug in practice, even though the
pattern is fragile. Prioritise anything reachable from a form with optional inputs. Note that
BUG-001 was found by accident, not by looking; the others will not surface on their own.

---

## BUG-004 — Unreachable error handling after `Http::retry()` at 5 AI call sites

**Severity:** Medium (not a crash — misleading code that will cause a wrong fix later)
**Found:** 2026-08-06, while planning Group D of the retry work. **Not fixed** — separate concern.

Five call sites follow `Http::retry(...)` with a `successful()` guard that **can never run**:

```php
$resp = Http::retry(2, 500)->timeout(60)->post(...);

if (! $resp->successful()) {
    throw new \RuntimeException('OpenAI chat failed: '.$resp->body());   // unreachable
}
```

`PendingRequest::retry()` defaults to `$throw = true`, and the send loop ends with:

```php
// vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php:1075-1077
if ($potentialTries > 1 && $this->retryThrow) {
    $response->throw();
}
```

With `times = 2`, `$potentialTries > 1` is true, so **any** unsuccessful response throws
`RequestException` from inside `retry()`. Execution never reaches the guard below it.

**Sites:**

| File | Method | Exception it *thinks* it throws |
|---|---|---|
| `AI/Services/Llm/OpenAiProvider.php` | `chat()` | `RuntimeException` |
| `AI/Services/Llm/OpenAiProvider.php` | `embed()` | `RuntimeException` |
| `AI/Services/Llm/AnthropicProvider.php` | `chat()` | `RuntimeException` |
| `AI/Services/Llm/GeminiProvider.php` | `chat()` | `RuntimeException` |
| `AI/Services/Llm/GeminiProvider.php` | `embed()` | `RuntimeException` |

**Why it matters.** `RequestException extends HttpClientException extends Exception` — it is
**not** a `RuntimeException`. Any caller written to `catch (\RuntimeException $e)` around these
providers is not catching the failure it was written for. `AiKnowledgeBaseController` catches
exactly that (`catch (\RuntimeException $e)` at two sites), so the intended user-facing error
message cannot be produced by an HTTP failure.

The two `IndexDocumentJob` sites differ: they `return ''` rather than throwing, so their guard
is unreachable too, but the consequence is that the exception propagates out of the job and
the (jittered) job-level backoff takes over — which is arguably the correct behaviour.

**Deliberately not fixed in Group D.** Group D changed only retry *timing* and the retry
*predicate*; it did not touch `times` or error handling. Fixing this means choosing between
`throw: false` plus a real guard, or deleting the dead guard and catching `RequestException`
at the callers — a behaviour change with its own blast radius. Decide it on its own.

---

## BUG-005 — Gemini API key travels in the URL and reaches logs, the DB, and API responses — ✅ FIXED

**Severity:** Critical (credential disclosure) — violated the CLAUDE.md rule that provider
tokens are never logged.
**Found:** 2026-08-06, at the Group D Gemini gate. **Fixed:** 2026-08-06 on
`fix/gemini-key-in-url`.

> **Note on numbering.** This entry was first written on `fix/retry-backoff-jitter`
> (commit `75c2bea`) alongside BUG-004, listing only the two `GeminiProvider` sites. That
> older duplicate was dropped when the branches merged, but two facts it alone recorded — the
> Sentry sink and the reachability chain below — were ported across rather than lost.

Gemini authenticated with a query-string parameter rather than a header:

```php
->post(self::BASE."/models/{$model}:generateContent?key={$this->apiKey}", $body);
```

On a **connection failure** (DNS, TLS, timeout — not an HTTP error status), Guzzle appends
the URI to the exception message verbatim:

```php
// vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:271
$message .= sprintf(' for %s', $redactedUriString);
```

`Utils::redactUserInfo()` redacts only `user:pass@host` — **it does not touch the query
string**. Laravel wraps that message unchanged in `ConnectionException`.

**Three affected sites** (the third was missed in the original write-up and found only by
grepping the whole repo for `[?&]key=`, per the CLAUDE.md rule about searching for the
concept rather than the symbol already in hand):

| # | File | Line | Endpoint |
|---|---|---|---|
| 1 | `AI/Services/Llm/GeminiProvider.php` | 45 | `generateContent` |
| 2 | `AI/Services/Llm/GeminiProvider.php` | 77 | `batchEmbedContents` |
| 3 | `Integrations/Services/ConnectionTester.php` | 105 | `models` (the admin "Test connection" button) |

Site 3 is admin-triggered and interactive, so its failures surface to a user more directly
than the background paths. Fixing only `GeminiProvider` would have closed two of three and
looked complete.

**Five paths recorded the key:**

| # | Path | Where it ended up |
|---|---|---|
| 1 | `bootstrap/app.php:146` — `Log::channel('errors')->error($e->getMessage(), …)` | every reportable exception message, on disk |
| 2 | `failed_jobs.exception` (`longText`) via `IndexDocumentJob` → `LlmGateway::embed()` | persisted in the database |
| 3 | `Admin/QueueController.php:29` → `Admin/Queue/Index.jsx:72` | admin UI (`title={job.exception}` shows full text on hover) |
| 4 | `AiChatbotController.php:112` — `catch (\Throwable $e)` → `response()->json(['error' => $e->getMessage()], 422)` | **returned to the browser** |
| 5 | `ConnectionTester.php:27-35` — `catch (\Throwable $e)` → `$config->update(['last_test_message' => $e->getMessage()])` | **persisted to `integration_configs.last_test_message`** and rendered in the admin UI |

Path 5 was found while writing the tests, after the first four were documented.
`ConnectionTester::test()` wraps its whole dispatch in `catch (\Throwable)` and writes the
message straight to the database, so an admin pressing "Test connection" while DNS is down
would have stored the key in a column and displayed it back. It is the same class of sink as
the other four and needed no separate fix — removing the key from the URL closed it too.

The chain was reachable, not theoretical: `AiChatbotController::test()` →
`ChatbotRunner::run()` → `LlmGateway::chat()` → `LlmManager::forWorkspace()`, and
`LlmManager:91` constructs `GeminiProvider` whenever the workspace selects `gemini` — it is in
both the chat and embed fallback orders. Path 4 is the most severe of the five: it does not
merely log the key, it hands it to the HTTP client.

Sentry (`bootstrap/app.php:154`) would have captured it as well; it is currently inert only
because no DSN is set. Enabling Sentry without this fix would have opened a sixth sink.

**`RequestException` was never a vector** — `prepareMessage()` builds its message from the
response status and body only, never the request URL. Only `ConnectionException` leaked,
which is precisely the condition retries make more likely to be hit repeatedly.

**Fix:** send the key as the `x-goog-api-key` header at all three sites and remove the query
parameter from the URL entirely. Confirmed against Google's current REST documentation for
both `generateContent` and `batchEmbedContents`; the embeddings reference shows only the
header form. The `?key=` form still works for backwards compatibility, so this is not a
breaking migration.

All five paths close from this single change, because the secret is no longer in the URI for
`ConnectionException` to carry. **None of the five needed its own change.**

**What this fix does NOT do.** The five paths remain open as *mechanisms* —
`AiChatbotController:112` still returns a raw `$e->getMessage()` to the browser for any
exception. This change removed the secret from the message; it did not make those paths safe.
See BUG-006, which leaks the same way but into a *different* sink set — the log and
`lead_scrape_jobs.error` rather than these five.

---

## BUG-006 — Credentials in GET query params leak via ConnectionException — 7 sites, 4 providers

**Severity:** Critical (credential disclosure) — same class as BUG-005, **not fixed**
**Found:** 2026-08-06, while grepping for the BUG-005 pattern repo-wide.

Passing the key as an **array** parameter is not safer than string concatenation — it only
looks safer. Guzzle serialises the array into the request URI, so `ConnectionException`
carries it exactly as it did for Gemini:

```php
// Leads/Services/GooglePlacesScraper.php:40,45
$params = ['query' => $query, 'key' => $apiKey];
$res = Http::get(self::PLACES_URL, $params)->json();
```

**Places sites:**

| # | File | Line | Endpoint |
|---|---|---|---|
| 1 | `Leads/Services/GooglePlacesScraper.php` | 40, 45 | Places `textsearch` |
| 2 | `Leads/Services/GooglePlacesScraper.php` | 76–79 | Places `details` |
| 3 | `Integrations/Services/ConnectionTester.php` | 181–183 | Places `textsearch` (admin test button) |

### ⚠️ Two corrections to the first write-up

**1. It does NOT reach the five BUG-005 sinks.** That claim was wrong, carried over by
analogy rather than checked. `GooglePlacesScraper::run()` catches `\Throwable` **itself**
(lines 62–65), so the exception never reaches `failed_jobs` at all:

```php
} catch (\Throwable $e) {
    Log::error('GooglePlacesScraper error: '.$e->getMessage());
    $job->update(['status' => 'failed', 'error' => $e->getMessage(), …]);
}
```

The real sinks for sites 1–2 are the log **and `lead_scrape_jobs.error`** — a per-workspace
database column written on every failed scrape, which makes this **customer-visible**, not
admin-only. Site 3 lands in `integration_configs.last_test_message`, as Gemini's did.

**Sites 1–2 are currently LATENT, not live.** `GooglePlacesScraper::run()` dies at line 29
before any HTTP call is made — see BUG-007 — so those two sites cannot leak today because
they never reach the network. They become live the moment BUG-007 is fixed, which is why the
scrub is worth having in place first. **Only site 3 (`ConnectionTester:183`) leaks live
today**; it is reachable through the admin "Test connection" button.

This also means the scraper sites **cannot be given a meaningful regression test yet**: a
consequence test would pass vacuously — no credential in `lead_scrape_jobs.error` because no
request was ever made, not because anything was scrubbed. That coverage is owed once BUG-007
is fixed, and is recorded as a skipped test in
`tests/Feature/Security/CredentialNotInExceptionMessageTest.php`.

**2. It is not a three-site bug. It is seven sites across four providers.** Found by sweeping
for credentials in query arrays and filtering to `GET` — `Http::post($url, $array)` sends the
array as the request **body**, which never enters the URI, so only `GET` leaks.

| # | Site | Credential | Provider |
|---|---|---|---|
| 1 | `GooglePlacesScraper.php:45` | `key` | Google Places |
| 2 | `GooglePlacesScraper.php:76` | `key` | Google Places |
| 3 | `ConnectionTester.php:183` | `key` | Google Places |
| 4 | `ConnectionTester.php:47` | **`access_token`** | Meta / Graph |
| 5 | `ConnectionTester.php:145` | **`api_key` + `api_secret`** | Nexmo / Vonage |
| 6 | `Broadcasting/Services/Sms/SmsDotBdDriver.php:18` | **`api_key`** | SMS.bd |
| 7 | `Social/Http/Controllers/SocialAccountController.php:104` | **`access_token`** | Social OAuth |

Not leaking, by verb alone: `BulkSmsBdDriver`, `NexmoDriver`, `MimSmsDriver`, and
`InboxSetupController` ×4 — the last passes `appId|appSecret` and is `POST`, so it is safe
only by luck of the verb. Any of these becoming a `GET` reintroduces the leak.

**Why the BUG-005 remedy does not apply.** The endpoint in use —
`maps.googleapis.com/maps/api/place/textsearch/json` — has **no documented header-key form**;
the legacy Text Search reference lists only `query` and `radius` as required parameters and
shows `?key=` in every example.

A header form *does* exist, but only on the **Places API (New)** (`places.googleapis.com/v1`),
which accepts `X-Goog-Api-Key` exactly like Gemini. That is a different host with a different
request shape, response shape and field-mask model — an API migration, not a security patch.
See the roadmap note below.

**Fix chosen: the central scrub (option 1 below).** The seven-site inventory is the argument:
per-site patching fixes three of seven and does not survive the next
`Http::get($url, ['key' => …])` anyone writes.

1. Scrub the query string from `ConnectionException` messages centrally, before anything
   reports them — protects every future query-param credential, and fixes the class rather
   than the instance. **This is the one being implemented.**
2. Stop returning raw `$e->getMessage()` to clients and to logs, which is worth doing
   regardless of this bug. **Still open** — see the note under BUG-005.
3. Catch `ConnectionException` at each call site and rethrow scrubbed — narrowest, leaves the
   general mechanism open. **Rejected.**

Option 1 is the one that would have prevented BUG-005 too.

### 📌 Deliberately out of scope, recorded so it is not lost

- **Places API (New) migration.** The endpoint in use is marked *Legacy* by Google and the
  documented replacement is `places.googleapis.com/v1`, which supports the `X-Goog-Api-Key`
  header. Worth doing — it would remove the credential from the URI entirely rather than
  scrubbing it after the fact — but it is an API migration with its own testing burden.
  **Roadmap item, not a security patch.**
- **Per-provider tests for sites 4–7.** The central middleware covers them by construction,
  but this branch's consequence tests cover only the three Places sites. Meta, Nexmo, SMS.bd
  and the Social OAuth callback each deserve their own leak test. **Separate task.**

---

## BUG-007 — Google Places lead scraping has NEVER worked: four stacked defects

**Severity:** Critical — a shipped feature that has never functioned, and one layer is a
tenant-isolation defect
**Found:** 2026-08-07, while writing the BUG-006 regression tests. **Not fixed.**

> **This is a BUILD TASK, not a patch.** Do not attempt a one-line fix. The defects are
> stacked: fixing each one wakes the next, and the path below the first has never executed at
> all. It needs building deliberately against live Google Places documentation, with tests.

> **Provenance.** This was first recorded as a one-line "undefined method" bug. That
> understated it. A full trace of the dead path found **four stacked defects**, one of which
> (#4) is a tenant-isolation issue that has nothing to do with Places. The entry was rewritten
> so the next person treats it as a build, not a patch. It supersedes a narrower BUG-007
> written on `fix/places-key-in-url`, which was removed when the branches merged.
>
> Worth knowing for next time: the two entries did **not** conflict in git. They landed in
> different regions of the file and auto-merged into two contradictory sections with no
> warning. The duplicate had to be deleted deliberately, not resolved. **A clean merge is not
> evidence that a document is coherent.**

Every layer below was verified against the actual code. Nothing here is inferred by analogy.

### 1. `generic()` does not exist — fatal on the first statement

`app/Modules/Leads/Services/GooglePlacesScraper.php:29`:

```php
$creds = $this->credentials->generic('google_places');
```

`CredentialResolver` has seven public methods — `meta`, `oauth`, `llm`, `sms`, `googlePlaces`,
`google`, `qdrant` — and **no `generic()`, no `__call()`, no `__get()`**. The method it wants
is `googlePlaces()`. Proven at runtime:

```
method_exists generic():  NO
method_exists __call():   NO
generic() -> Error: Call to undefined method CredentialResolver::generic()
```

`run()` wraps everything in `catch (\Throwable)` and writes the message to
`lead_scrape_jobs.error`, so this is invisible as a crash. **Every lead-scrape job any
customer has ever run has failed here**, with "Call to undefined method …" sitting in that
column. Nothing reaches `failed_jobs`, so `ScrapeLeadsJob`'s `$tries = 2` never fires.

### 2. The naive fix converts the fatal into a different fatal

`googlePlaces(): ?GenericCredentials` takes **no arguments** and is **nullable** — `resolve()`
returns `null` when the provider has no `IntegrationConfig`, when it is disabled, or when its
credentials are empty. Line 30 dereferences it unguarded:

```php
$apiKey = $creds->get('api_key');
```

Confirmed at runtime: `googlePlaces() -> NULL`. So swapping the method name alone turns
"Call to undefined method" into **"Call to a member function get() on null"** for every
workspace that has not configured Places — which is the **default state**. The existing
`if (! $apiKey)` guard on line 31 was clearly meant to cover this; it sits one line too late.

### 3. The Places `status` field is never checked — silent success on failure

The legacy Places API returns **HTTP 200** with a `status` body field: `OK`, `ZERO_RESULTS`,
`REQUEST_DENIED`, `OVER_QUERY_LIMIT`, `INVALID_REQUEST`. The scraper reads only
`$res['results'] ?? []` and never looks at `status`.

An invalid key, a billing failure or a quota breach therefore returns 200, `results` is
absent, `$count` stays 0, and the job is marked **`done` with 0 leads**. The user sees
"completed, found nothing" — indistinguishable from a genuinely empty search.

`Integrations/Services/ConnectionTester.php:186` **does** check `status` for the same API.
One concept, two implementations, only one correct — the pattern CLAUDE.md warns about.

### 4. ⚠️ Cross-tenant lead theft — a tenant-isolation defect, not a Places bug

```php
Lead::updateOrCreate(['google_place_id' => $placeId], ['workspace_id' => $workspaceId, …]);
```

- `google_place_id` is **globally `unique()`** in the schema — not composite with
  `workspace_id` (`create_leads_tables.php:26`).
- `Lead` has **no `BelongsToWorkspace` trait and no global scope** — only `HasFactory` and
  `MasksDemoData`.
- `updateOrCreate` matches on `google_place_id` **alone**, then overwrites `workspace_id`.

So when workspace B scrapes an area workspace A has already scraped, the match finds **A's
row** and reassigns it to B. The lead is **transferred, not duplicated** — workspace A
silently loses data to another tenant. For a lead-generation product where two customers
plausibly scrape the same city, this is not a corner case.

**This intersects Phase 0 directly.** It is exactly the class of defect the
`BelongsToWorkspace` global scope exists to prevent, and `Lead` is a customer-owned model
with a `workspace_id` column that does not carry the trait. It should be picked up by the
Phase 0 CI guard (any model whose table has `workspace_id` but lacks the trait), and the fix
needs a schema decision — a composite unique on `(workspace_id, google_place_id)` plus a data
migration for existing rows — not a code tweak. **Do not fix this inside a Places branch.**

### What must be verified against live documentation when this is built

Nothing below has ever executed, so none of it is confirmed by observation:

- **Response shape** — `results[].place_id / name / formatted_address / types / rating /
  user_ratings_total / geometry.location`, and details `result.formatted_phone_number /
  website`. These match the legacy API as documented, and every read is `?? null` guarded,
  but no real response from this account has ever been seen.
- **The endpoint is Legacy.** Google marks `maps.googleapis.com/maps/api/place/*` as legacy
  and directs new work to the Places API (New) at `places.googleapis.com/v1`, which also
  accepts the key as an `X-Goog-Api-Key` header instead of a query parameter. Decide which
  API to build against **before** writing code — that choice also determines whether BUG-006
  applies at all.
- **Cost.** `upsertPlace()` makes **one Place Details call per result**, billed separately and
  more expensively than Text Search. A 60-result scrape becomes 60+ billed calls. Nobody has
  ever seen this bill.
- **`fields=formatted_phone_number,website,url`** requests `url`, which is never consumed.
- **`sleep(2)`** between pagination pages runs inside a queued job, stalling a worker.
- **Unbounded loop** — `while ($pageToken)` has no page cap, no result cap, and no plan-limit
  check before scraping.
- **`UsageMeter::track(…, 'lead_credits', $count)`** has never incremented; the billing path
  goes live with the feature.

### Interaction with BUG-006

The two `GooglePlacesScraper` leak sites in BUG-006 **stay latent until this is built**. They
are protected-in-advance by the `ConnectionExceptionScrubber` middleware once
`fix/places-key-in-url` merges, but are not reachable meanwhile — execution never gets as far
as the network. The `ConnectionTester` Places site is the only one that leaks live today.

That is also why BUG-006's `GooglePlacesScraper` consequence test is **skipped** rather than
written: with the scraper dead, it would pass because no request was ever made, not because
anything was scrubbed. Un-skip it when this feature is built.

---

## BUG-008 — the GDPR data export shipped the WRONG workspace's data — ✅ FIXED

**Severity:** Critical — user-facing, in a regulatory feature
**Found:** 2026-08-07, during the 1c Core analysis. **Fixed:** same day, branch
`fix/workspace-export-wrong-workspace`.

A user switched into workspace B who requested a data export received workspace **A's**
data — contacts, conversations and messages from their HOME workspace, packaged as "your
export" and delivered by email as a 72-hour signed URL.

Not a cross-tenant leak: the requester is a member of both workspaces, so they received data
they were entitled to. But it was **the wrong workspace's data, silently**, in the one feature
whose entire purpose is regulatory correctness. Export workspace B to answer a GDPR request
and you hand the regulator A's records.

### Why it was invisible

The workspace was known at dispatch and **thrown away**:

```php
// DataExportController::store — before
GenerateWorkspaceExportJob::dispatch($request->user()->id);   // just the id

// GenerateWorkspaceExportJob::handle — before
$user = User::findOrFail($this->userId);                      // fresh, no auth context
$exportService->generate($user);

// WorkspaceExportService::generate — before
$workspaceId = $user->current_workspace_id ?? $user->workspace_id;   // always HOME
```

Neither half looked wrong on its own. The controller appeared to pass "the user"; the service
appeared to derive "the user's workspace". The defect only existed in the seam — and
`current_workspace_id` does not exist on `User`, so the fallback was unconditional.

**A queued job cannot recover this.** There is no authenticated user, so
`WorkspaceContext::id()` is null; and a `User` carries a **home** workspace but not a
**current** one. The information was destroyed at dispatch.

### The fix, and the precedent it sets

The workspace is **captured where it is known and carried to where it is used**:

| | after |
|---|---|
| `WorkspaceExportService::generate` | `(User $user, int $workspaceId)` |
| `OnboardingService::getProgress` | `(User $user, ?int $workspaceId)` |
| `OnboardingService::markStep` | `(User $user, ?int $workspaceId, string $step, bool $verify)` |
| `GenerateWorkspaceExportJob::__construct` | `(int $userId, ?int $workspaceId)` |

**Rejected alternatives, recorded so they are not revisited:**

1. *Resolve via `WorkspaceContext::id()` inside the services.* Returns null in a queued job, so
   it converts a wrong answer into **the same wrong answer**, now dressed as migrated.
2. *Add `WorkspaceContext::forUser(User $user)`.* Cannot help — the question is not "which
   workspace does this user belong to" but "which workspace were they **in**", which a `User`
   cannot answer. It would also be a second resolver with subtly different semantics from
   `id()`: the "one concept, two definitions" trap that has already bitten this codebase twice
   (`accessibleWorkspaces` / `isAccessibleBy`, and the two webhook dedupe layers).

### Fails loudly

`app/Exceptions/MissingWorkspaceContextException.php` **lands with this fix**. The plan
(§B.3) specified it and deferred it "to whichever commit first throws it" — this is that
commit. A queued export that cannot establish a workspace **throws** rather than guessing.

`workspaceId` is nullable on the job **only** so that jobs already queued when this shipped
fail loudly instead of silently exporting home data.

The rule, which generalises beyond these three sites and is the reason this shape was chosen:
**a workspace is captured where it is known and carried to where it is used; code that cannot
establish one says so rather than guessing.** That holds for the `BelongsToWorkspace` global
scope, for Smart QR scan processing, and for every webhook — all of which arrive with no
session and a tenant that must come from the payload or the bound record. The alternatives all
encoded "when in doubt, use home", which is the assumption 1c spent eight branches removing.

### Regression cover

`tests/Feature/Workspace/WorkspaceExportScopingTest.php` — 8 tests asserting on the **content
of the generated archive**, not on the job being dispatched. That distinction is the whole
point: a dispatch assertion passed throughout the life of the bug, because the dispatch was
fine and the payload was wrong.

Stash-checked: reverting the service to derive from `$user` makes the bug test fail **by
producing `HomeContact` in an export requested for the other workspace** — the original defect
reproduced exactly, not an unrelated error. Reverting the job's guard to fall back to home
makes the loud-failure test fail by **writing an archive** instead of throwing.

---

## BUG-009 — onboarding completions are stored per USER, not per workspace — ✅ FIXED

**Severity:** Medium — wrong progress display, no data exposure
**Found:** 2026-08-07, while writing the BUG-008 regression tests. **Not fixed** — needs a
schema change.

`onboarding_steps` has columns `id, user_id, step, completed, completed_at, created_at,
updated_at` — **no `workspace_id`**. And `markStep()` persists on that key:

```php
OnboardingStep::updateOrCreate(
    ['user_id' => $user->id, 'step' => $step],
    ['completed' => true, 'completed_at' => now()]
);
```

`isCompleted()` short-circuits on a persisted row before any workspace query runs:

```php
if (in_array($step, $manuallyCompleted, true)) { return true; }
```

So once a user completes "import your first contacts" in workspace A, it reads as complete in
workspace B too — even though B has no contacts. The onboarding checklist for a brand-new
workspace can show as finished before anything has been set up in it.

**BUG-008's fix does not close this.** That fix corrected which workspace is **queried**;
this is about what is **persisted**. Both were needed and only one was in scope.

**How it was found, and why it matters as a pattern:** the first draft of the onboarding test
checked the home workspace first, then the switched one — and passed. It passed because the
first call *persisted* the completion, so the second call short-circuited on the row rather
than querying the switched workspace. The test was asserting the bug. It now checks the
switched workspace **first**, and the ordering requirement is recorded in the test docblock so
it is not "tidied" back.

**Fix when scoped:** add `workspace_id` to `onboarding_steps`, include it in the
`updateOrCreate` key and in the `OnboardingStep::where(...)` lookup in `getProgress()`. A data
migration must decide what existing rows mean — most plausibly, backfill them to each user's
home workspace, since that is the only workspace they could have been recorded against.

---


### ✅ Closed 2026-08-09 — Phase 0 slice 9

Fixed in `2685677`, by migration and code together:

- `onboarding_steps.workspace_id` added, backfilled from the owner's home workspace, then
  `NOT NULL`.
- `OnboardingService::markStep()` now keys `updateOrCreate` on
  `(user_id, workspace_id, step)`.
- `detect()` reads with the workspace too.
- **UNIQUE `(user_id, step)` widened to `(user_id, workspace_id, step)` in the SAME
  migration.** Without that, fixing the record key would have replaced a silent wrong answer
  with a duplicate-key error the first time anyone completed the same step in a second
  workspace.

Pinned by `OrphanModelsScopeTest::a_step_completed_in_one_workspace_does_not_read_as_complete_in_another`
— which asserts the READ in workspace B, not the existence of a row, because a row existed
before the fix too.

Two migration facts recorded in the migration's own comments, both learned the hard way:
dropping an index that backs a foreign key fails with MySQL 1553 unless the replacement is
created first; and `dropIndex()` takes a STRING, because an array means "these columns".
The second was caught only by running the rollback.

## BUG-010 — `client_role` looks like an authorization mechanism and is not one

**Severity:** Unknown until investigated — potentially Critical
**Found:** 2026-08-07, while sweeping for DEEP-03 siblings. **Not fixed.**

`User::client_role` holds `administrator` or `staff`, is set at registration, invitation and
social/Firebase login, and is displayed in the team UI. It reads like a permission tier.

**No route, middleware or policy gates on it.** Grepping every use outside the admin panel
finds only assignment at signup and `orderByRaw` for display ordering. Every client route uses
`['web', 'client-app']`; there is no `permission:`/`can:`/`role:` middleware anywhere in
`routes/client.php` or the ten module route files.

**So a `staff` user appears to have the same route-level access as an `administrator`** —
including, on the evidence of the 1c work, deleting contacts, deleting stores, changing
billing-adjacent settings and managing team members. Workspace scoping (Phase 0 / 1c) confines
them to their own workspace; nothing confines them *within* it.

This was noticed because DEEP-03's fix asked the same question of the admin panel — which
*does* have a real RBAC layer — and the client side turned out to have none.

**Not investigated further**, deliberately: it is a different class from DEEP-03 and needs its
own pass. What that pass should answer: is `staff` meant to be restricted, and if so, which of
the ~200 client routes should it lose? That is a product decision before it is a code one.

---

## BUG-011 — coupons and tax rates ride on the plans permissions

**Severity:** Low — sloppy modelling, not a privilege escalation
**Found:** 2026-08-07, during the DEEP-03 route sweep. **Not fixed.**

| Route | Gate |
|---|---|
| `POST /coupons`, `PUT /coupons/{coupon}` | `create_plans` |
| `DELETE /coupons/{coupon}` | `delete_plans` |
| `POST /tax-rates`, `PUT /tax-rates/{taxRate}` | `create_plans` |
| `DELETE /tax-rates/{taxRate}` | `delete_plans` |

Write actions gated by write permissions, so this is **not** the DEEP-03 defect class and is
not an escalation. But it means "can manage plans" silently also means "can manage coupons and
tax rates", and an operator granting `create_plans` has no way to know that from the
permission's name or description.

**Fix when scoped:** `create_coupons`/`delete_coupons` and `manage_tax_rates`, or an explicit
note in the `create_plans` description saying what else it carries. The second is cheaper and
honest; the first is correct.

---

## 📌 Owed — better modelling for support-ticket permissions

Recorded so the choice reads as deliberate rather than an oversight.

`POST /support/{supportTicket}/reply` was gated by `view_settings` (a read permission — part of
the DEEP-03 cluster) and is now gated by **`manage_settings`**.

`manage_settings` is the honest counterpart of the permission it replaced and closes the
read-gates-write hole, but it is a **poor conceptual fit**: replying to a customer support
ticket is not a settings operation. There is no Support permission category at all.

`manage_support` was deliberately **not** created. Inventing a permission category for a single
route is a product decision, not a security fix, and mixing one into a security branch would
have widened its blast radius. When support grows its own surface, it should get its own
category — `view_support` / `manage_support` — and this route should move to it.

---

## 📌 Status note — retry work (Group D), as of 2026-08-06

Not a bug. Recorded here because it corrects a **pushed, immutable** commit message, and
because the two findings it depends on — BUG-004 and BUG-005 — are on this page.

**Landed on `master` (`7a2fb01`).** Four of the five files named as untouched are wired and
tested: `OpenAiProvider` (chat + embed), `AnthropicProvider`, `EmbeddingStore`
(`qdrantClient()`, keeping its 300ms base), `IndexDocumentJob` (fetchUrl + processSitemap).
Six sites, 25 wiring tests, all stash-checked.

**Genuinely outstanding:**

1. **`GeminiProvider`'s retry wiring** — both sites still use the bare `Http::retry(2, 500)`.
   It was deferred behind BUG-005, because the key travelled in the URL and retrying a
   connection failure multiplied the leak. **BUG-005 is now fixed, so this can proceed.**
2. **Per-job `backoff()` tests for the 16 queued jobs** — still none. No test exercises
   `backoff()` on any job; 8 of the 16 have no test reference at all. `Queue::fake()` never
   resolves a backoff schedule, so the green suite says nothing about them.

Both live on `fix/retry-backoff-jitter`, which is kept for that reason.

**⚠️ `24ecbf1`'s PARTIAL disclosure now overstates what is outstanding.** It lists
`OpenAiProvider, AnthropicProvider, GeminiProvider, EmbeddingStore, IndexDocumentJob:138,240`
as untouched; only `GeminiProvider` still is. It also says the work was never verified against
the suite, which was true when MySQL access was broken on the dev machine and is no longer.

It is **left unedited on purpose** — it is pushed history and an accurate record of what was
known at that commit. This note supersedes it. Read them together, not separately.

---

## BUG-012 — the CSP is not a mitigating control for any XSS in this application

**Severity: Medium — recorded, not fixed. Found while fixing SEC-004.**

`SecureHeaders::buildCsp()` emits `script-src 'self' 'unsafe-inline'`.

Both halves defeat the purpose independently:

- `'self'` — stored XSS is BY DEFINITION same-origin. An uploaded file served from
  `/storage/...` is `'self'`.
- `'unsafe-inline'` — the payload in a stored-XSS file is an inline `<script>` or an
  `onload=` attribute. Allowed.

So the SEC-004 payload would have executed with the CSP fully applied. And it was not
applied at all: uploads on the `public` disk are served by the **web server**, so the
request never enters PHP and no middleware runs on it.

This matters beyond SEC-004. Any future review that reasons "we have a CSP, so XSS is
contained" is reasoning from a header that permits exactly the thing it appears to prevent.

**Not fixed here** because removing `'unsafe-inline'` requires nonces or hashes on every
inline script, which touches `app.blade.php`, the Inertia bootstrap and the Vite output —
a change of a completely different size and risk from a validation fix, and one that breaks
the app visibly if it is wrong.

## BUG-013 — `media.mime_type` disagrees with the file it describes

**Severity: Low — recorded, not fixed. Found while fixing SEC-004.**

`MediaService::store()` writes `mime_type` from `$file->getMimeType()` — the **sniffed**
value — while, before SEC-004, `path` took its extension from the **client filename**.

For the polyglot that means the row said `image/gif` for a file the browser rendered as
HTML. The two columns describe different realities and always have.

SEC-004 makes them agree going forward: the stored extension is now derived from the same
sniffed value as `mime_type`. **Rows written before the fix keep the mismatch.**

The trap for later: `mime_type` looks like the authoritative answer to "what is this file"
and is the obvious column for a future safety check to read. It is not authoritative about
how the file will be **served** — the extension is, because that is what the web server
reads.

## 📌 Note — cloud storage disks change the origin picture

Recorded during SEC-004.

Today `StorageManager::diskName()` falls back to the local `public` disk, so uploads are
served from the application's own origin and stored XSS is same-origin.

On S3 / DigitalOcean Spaces the files sit on a **different** origin, which reduces (does not
remove) the impact of an executable upload.

**The white-label roadmap reverses that.** Custom partner domains fronting a bucket put
uploads back on an origin the customer's session trusts. Anyone reasoning "we're on S3, so
this is contained" must re-check whether a custom domain has since been pointed at it.

The SEC-004 fix is disk-independent — it constrains what is written, not where — which is
why it does not need revisiting when the storage backend changes.

## 📌 Note — the `local` disk has `'serve' => true`

`config/filesystems.php` sets `'serve' => true` on the `local` disk, which registers
Laravel's own file-serving route.

`StorageManager` never returns `local` — it returns `public` or a cloud disk — so this is
**not live**. Recorded because it is a second file-serving path with different header
behaviour from the web-server-served `/storage` path, and any future reasoning about how
uploads reach a browser has to account for both.

## 📌 Proposed — a `storage:inventory --dry-run` command (NOT built)

SEC-004 fixed what gets written. **It cleaned nothing that is already stored.**

Verified on this machine: `storage/app/public` holds 3 `.png` files, the `media` table is
empty, no client has a `logo_path`, and there are no branding settings rows. So there is
nothing to clean **here**. On any deployed install that is unknown and must be checked.

Proposed shape:

- Walk every configured disk plus the `media` table.
- Report files whose extension is in the SEC-004 denylist (`html`, `htm`, `xhtml`, `shtml`,
  `svg`, `xml`), and — separately — files whose extension **disagrees with their sniffed
  content**, which is the polyglot signature and the more interesting signal.
- `--dry-run` by default. Report only. No deletion flag in the first version.

**Why it must not delete.** A legitimately-uploaded `.svg` logo and an attack SVG are
byte-for-byte indistinguishable in kind — both are valid SVG served from the public disk.
Only a human who knows the tenant can say which is which. An automatic delete would remove
customers' working branding, and a tool that is dangerous to run will not be run.

The extension/content mismatch check is the part worth building: that one has no legitimate
explanation.

---

## BUG-014 — Sanctum's own config comment is wrong about how `expiration` works

**Severity: Low (documentation) — recorded during SEC-006. Will mislead someone.**

Laravel ships this in `config/sanctum.php`:

> This value controls the number of minutes until an issued token will be considered expired.
> **This will override any values set in the token's "expires_at" attribute**, but first-party
> sessions are not affected.

The emphasised clause is **false**. `vendor/laravel/sanctum/src/Guard.php:128-129`:

```php
(! $this->expiration || $accessToken->created_at->gt(now()->subMinutes($this->expiration)))
&& (! $accessToken->expires_at || ! $accessToken->expires_at->isPast())
```

The two checks are **ANDed**. Nothing overrides anything — **the stricter of the two wins**.
A global value cannot extend a short per-token expiry, and a long per-token expiry cannot
escape a global cap. Verified by test, not by reading:

```
per-token expires_at in the PAST, no global expiration        => 401
expires_at = +1 year, created 2d ago, global expiration = 60m => 401
```

A second fact the comment omits: the global value is measured from **`created_at`, never
`last_used_at`**. It is an absolute lifetime, not an idle timeout — an actively-used token
dies on the same schedule as an abandoned one.

Why this matters beyond pedantry: someone reading "override" will reasonably conclude that
setting a global expiry is the complete answer and that per-token values are cosmetic. The
opposite is true, and acting on the comment would silently truncate every long-lived
integration token a customer had deliberately created.

**Recorded, not fixed** — it is upstream's text and would reappear on any `config:publish`.
A correction is appended beneath it in `config/sanctum.php` instead.

## BUG-015 — `remember_token` on the impersonation path outlives the admin's session

**Severity: Medium — recorded, not fixed. Found during the SEC-006 sibling sweep.**

`ClientController::impersonate()` calls:

```php
Auth::guard('web')->login($targetUser, $request->boolean('remember', false));
```

With `remember = true` Laravel issues a **remember-me cookie for the impersonated customer
account**. Laravel's remember tokens have **no expiry** — they are valid until the password
changes.

So an admin impersonating a client can end up with a persistent credential for that
customer's account that outlives their own admin session, their own logout, and the
revocation of their admin permissions. DEEP-03 has just made impersonation require a
dedicated `impersonate_clients` permission — **revoking that permission does not invalidate a
remember cookie already issued.**

Not fixed here because SEC-006 is scoped to API tokens and this is the session guard. The
likely fix is simply to never pass `remember` on the impersonation path — impersonation is
by nature a short, deliberate act — but that is a behaviour change on a route that was
touched last commit, and it deserves its own branch and its own test.

## BUG-016 — webhook secrets never rotate and there is no rotation UI

**Severity: Low — recorded, not fixed. Found during the SEC-006 sibling sweep.**

Webhook signing secrets (`EcommerceStore`, `IntegrationConfig`, payment gateway configs) are
generated or entered once and then live forever. There is no rotation endpoint, no UI
control, and no expiry.

They are handled correctly in every other respect — encrypted at rest via `encrypted:array`
casts, verified with `hash_hmac` + `hash_equals`. This is a different family from bearer
tokens: a shared secret, not a credential a user carries. But it has the same
"issued once, lives forever" shape SEC-006 was about, and a leaked secret currently has no
remedy short of deleting and recreating the integration.

## 📌 Owed — decide whether mobile tokens still need `['*']`

`MobileAuthController::login` issues `createToken($deviceName, ['*'], …)`. SEC-006 bounded
the lifetime and left the abilities alone, deliberately, pending this decision.

**The inventory (what the mobile app actually calls):**

Every `/api/v1/mobile/*` route and every `/api/v1/auth/*` route — 20 endpoints across
`MobileConversationController` and `MobileInboxController` — carries **no `api.ability`
middleware at all**. Their stack is `auth:sanctum`, `user.active`, `throttle:api`, `demo`.

So: **the mobile surface does not need `['*']`. It does not need any ability.** It would work
identically with an empty ability list.

What `['*']` actually buys is the **other** surface. `CheckApiAbility` short-circuits on a
wildcard:

```php
if (in_array('*', (array) $token->abilities, true)) { return $next($request); }
```

so a mobile login token also unlocks the entire scoped business API — contacts, campaigns,
messages, AI, automations, social, webhooks, analytics — every route the 12 named scopes were
built to gate.

**The consequence:** a phone, authenticated with an email and a password, holds strictly more
authority than any token a customer can create for themselves in the UI, where they must pick
scopes explicitly.

Options, for the decision:

1. Issue mobile tokens with a named `mobile` ability and add `api.ability:mobile` to the
   mobile route groups. Cleanest; the mobile surface stops being a wildcard.
2. Issue with the specific scopes the mobile app needs (`conversations:read`,
   `messages:write`, `contacts:read`). More precise, but the mobile routes do not check
   abilities, so it only constrains the /v1 surface.
3. Keep `['*']` — defensible only if the mobile app is intended to be a full client.

**Not chosen here.** It is a product question about what the mobile app is for.

## 📌 Proposed — `sanctum:prune-expired` as scheduled housekeeping (NOT built)

Sanctum ships `sanctum:prune-expired`. It is not scheduled, and nothing else prunes
`personal_access_tokens`.

Now that tokens carry an `expires_at`, expired rows will accumulate. This is **housekeeping,
not security** — an expired token is already refused by the guard, so pruning removes clutter,
not risk. Suggested: `Schedule::command('sanctum:prune-expired --hours=24')->daily()`.

Deliberately not in the SEC-006 branch: mixing table maintenance into a security fix makes
the security change harder to review, and there is currently nothing to prune.

---

## 📌 BelongsToWorkspace — four hazards found during the STEP 1 inventory (2026-08-07)

Recorded **before slice 1 is written**, deliberately: each of these is a place the global
scope would **change behaviour rather than enforce it**, and each is invisible at the point
of failure. Discovering them mid-slice would mean discovering them as a red test with no
context.

None is a bug today. All four become one the moment the trait reaches the relevant model.

### H-1 — `Admin\DashboardController` returns platform-wide counts with no `where`

```php
// app/Http/Controllers/Admin/DashboardController.php:79-80
'contacts_total'      => Contact::count(),
'conversations_total' => Conversation::count(),
```

Under the scope these become *some* number — plausibly 0, plausibly one tenant's. **No error,
no exception, no failing test: a wrong figure on the admin dashboard.**

This is the archetype for the whole change. The ruled null-behaviour (match nothing, with an
explicit admin-guard exception inside the scope) is what protects it, which makes the admin
exception load-bearing rather than convenient — hence its own named test in slice 1.

**Action when `Contact`/`Conversation` take the trait (slices 6/7):** verify these two counts
against a seeded multi-tenant fixture, not against "the page still loads".

### H-2 — `AnalyticsService` already filters by `workspace_id` in 9 places

Lines 288, 294, 336, 475, 495, 746, 766, 796, 836 all read
`Model::where('workspace_id', $wsId)`. With the scope active the SQL becomes
`WHERE workspace_id = ? AND workspace_id = ?`.

Harmless **when the two agree**. Silently **empty** when they do not — e.g. an admin viewing a
client's report, where the explicit `$wsId` is the client's and the scope resolves to null or
to a different workspace. An empty analytics panel reads as "no activity", not as "broken".

**Action:** these 9 sites must be read individually, not bulk-edited. The question at each is
*where does `$wsId` come from* — if it is `WorkspaceContext`, the explicit filter is now
redundant; if it is a route parameter or an admin's selection, the explicit filter is the
correct one and the scope must be bypassed.

### H-3 — the webhook lookup cannot be scoped, structurally

```php
// app/Modules/Whatsapp/Http/Controllers/WhatsappWebhookController.php:101,117
$waba = WhatsappBusinessAccount::findByWebhookToken($token);
```

This query **must cross workspaces in order to discover which workspace it is**. There is no
authenticated user, and the workspace is the *answer*, not an input. A scope that needs the
answer to run the query is incapable of running it.

This is the most dangerous bypass in the codebase: it is on the inbound message path, it runs
unauthenticated, and CLAUDE.md's standing rule is that the webhook flow must never be
duplicated or worked around.

**Action:** an explicit `withoutWorkspaceScope('reason: …')` with a test proving the token
lookup resolves the correct workspace **and** that everything downstream of it is scoped to
that workspace via `WorkspaceContext::for()`. The bypass must be one query wide, not one
request wide.

### H-4 — the test and factory surface is larger than the app surface

**242 model call sites across 61 test files. Only 6 of 17 factories set `workspace_id`.**

Tests create models with no authenticated user, so under the scope they create rows the
subsequent assertion cannot read back. This is the single most likely cause of "the slice was
a day of work and three days of test repair".

The ruling that **the trait filters reads only and never writes `workspace_id` on create**
(2026-08-07) narrows this considerably — a factory that sets the column explicitly keeps
working — but it does not remove it: a factory that does *not* set the column produces a row
with `workspace_id = 0`/null that no scoped query will return.

**Action:** before slice 5, audit the 11 factories that do not set `workspace_id` and decide
per factory whether it should. That is a prerequisite of the canary, not a consequence of it.

### Recorded ruling — auto-fill on create is NOT in scope, and here is why

Considered and **deliberately rejected for now** (2026-08-07):

- 11 of 17 factories do not set `workspace_id`;
- jobs and webhooks have no workspace context at create time;
- 68 controller sites already write it explicitly — auto-fill would either **conflict** with
  those or make them **redundant, invisibly**.

**Read filtering is enforcement. Write auto-fill is a behaviour change.** This phase ships
enforcement only.

A later slice may add auto-fill, and if it does it must be its own slice with its own
stash-check, because its failure mode — rows silently written to the wrong workspace — is
worse than the one it prevents.

---

## BUG-017 — "PHPStan must pass at level 6" is false, and has never been true

**Severity: Medium (false assurance) — recorded 2026-08-07, not fixed.**

`CLAUDE.md` lists under Commands:

```
./vendor/bin/phpstan analyse          # must pass at level 6
```

and instructs "Run tests + PHPStan + Pint before declaring any task complete."

**Measured on `master` @ `c77b95d`: 733 errors.** Not 10, which is what BUG-002 implies by
naming only the `app/Modules/Social` `property.notFound` cluster.

### Inherited, not broken by us

Checked, because "did we do this" is the first question:

- `phpstan.neon` was added in **`4ec7e3e` "Development whatsmine" (2026-08-03)** — the initial
  import of the WhatsMine codebase — and **has never been modified since**
  (`git log -- phpstan.neon` returns exactly one commit).
- It has **always** included `phpstan-baseline.neon`, which carries **1,213 suppressed
  entries**.

So the position is: the project shipped with a 1,213-entry baseline **and** 733 errors on top
of it, and the config has not been touched. **"Must pass at level 6" was aspirational from the
start.** We did not break it and we have not made it worse.

### Why this is worth recording rather than shrugging at

`CLAUDE.md` is read at the start of every session. A line saying PHPStan must pass is
currently an instruction to run a command that always fails — which trains the reader to
ignore its output. That is how the one error that *is* yours gets lost in 733 that are not.

The working practice that has actually held all week is the honest version: **run PHPStan on
the files you changed and compare against master.** That is how the SEC-006 `TransientToken`
fatal was caught — a real bug in new code, found because the comparison was scoped to 5 files
rather than drowned in a repo-wide run.

### The 734th error is mine, and it is temporary

`feature/workspace-isolation-scope` reports 734. The addition is:

```
app/Models/Concerns/BelongsToWorkspace.php:38: Trait ... is used zero times and is not analysed. [trait.unused]
```

Accurate: `phpstan.neon` analyses `app/` only, and in slice 1 the trait's sole user is a
fixture model defined inside the test file. **It clears the moment slice 5 applies the trait
to `Lead`.** Recorded here so it is not mistaken for a regression in the meantime.

### Options, not chosen

1. Regenerate the baseline to absorb all 733, making the command genuinely pass — cheap, and
   makes the assurance real, but converts 733 unexamined errors into 733 permanently
   invisible ones.
2. Correct `CLAUDE.md` to say what is true: "PHPStan is not currently clean; run it on changed
   files and compare against master."
3. Fix the 733 — not a Phase 0 activity.

**Option 2 is the honest minimum** and costs one line. Not done here because editing
`CLAUDE.md`'s stated workflow is the project owner's call, not a side effect of a scope commit.

## 📌 Slice-5 decision — `BelongsToWorkspace::workspace()` collides with three existing definitions

The trait defines a `workspace()` relation. PHP resolves **class-over-trait silently** — no
error, no warning — so a model with its own `workspace()` keeps its own and a model without
one gets the trait's.

This is the **"one concept, two definitions"** shape that has already bitten this codebase
twice (`User::accessibleWorkspaces()` vs `Workspace::isAccessibleBy()`; the two WhatsApp
webhook dedupe layers). Both times it was found only because a test failed for an unexpected
reason.

**Models that already define `workspace()`:**

| Model | Definition |
|---|---|
| `App\Modules\Inbox\Models\InboxLabel` | `belongsTo(Workspace::class)` |
| `App\Modules\Inbox\Models\CannedReply` | `belongsTo(Workspace::class)` |
| `App\Models\User` | `belongsTo(Workspace::class, 'workspace_id')` — **never takes the trait**, so not a collision, listed for completeness |

The two real ones are **semantically identical** to the trait's version today, so nothing is
broken. The risk is entirely future: the moment one of them diverges — a different FK, a
`withDefault()`, a filtered relation — half the scoped models will resolve one way and half
the other, with nothing to indicate it.

**Decide at slice 5, three options:**

1. **Remove `workspace()` from the trait.** The trait's job is the scope; the relation is a
   separate concern that happens to travel with it. Cleanest separation, but 25 models then
   lack the relation entirely unless each declares it.
2. **Keep it in the trait and delete the two duplicates.** One definition, enforced by the
   trait. Requires touching two Inbox models in a slice that is otherwise about Shared.
3. **Keep both and add a guard test** asserting no scoped model overrides `workspace()`.
   Matches how every other "two definitions" trap in this codebase is now handled.

Not decided here. Flagged so the choice is made deliberately at slice 5 rather than
discovered at slice 12.

---

## ⚠️ The workspace scope does NOT protect against a WRONG dispatch — only a missing one

**Recorded 2026-08-08, during Phase 0 slice 4. Not a bug — a limit, recorded because the
sentence "we have a global scope now" will be read as covering it, and it does not.**

`EstablishesWorkspaceContext` resolves a queued job's tenant with one deliberately unscoped
lookup:

```php
$workspaceId = Campaign::withoutGlobalScope(WorkspaceScope::class)
    ->whereKey($this->campaignId)->value('workspace_id');
```

**It trusts the key it was handed.** Dispatch `SendCampaignMessageJob` with another tenant's
campaign id and the middleware will faithfully establish *that* tenant's context and do the
work — correctly, scoped, and to the wrong customer.

The same is true in the request path. The scope constrains what a query returns; it says
nothing about which id reached the query. A controller that accepts `campaign_id` from the
request and dispatches without checking ownership is exactly as wrong after Phase 0 as before.

### What actually protects against a wrong id

The **68 controller sites migrated in Phase 1c**, and route-model binding, which resolves
through the scope and therefore 404s on another tenant's uuid. Those remain load-bearing.
Phase 0 does not replace them; it removes a *different* failure — the query that silently
returned everything because nobody remembered to filter it.

### Why this is worth writing down

There is a predictable reasoning error waiting here: "isolation is enforced by the database
now, so the controller checks are redundant." They are not redundant, they defend a different
boundary, and deleting one of them would reintroduce a cross-tenant write with a green suite
and a global scope both saying everything is fine.

The one-line version, for a reviewer: **the scope answers "whose rows may this query see".
It never answers "was this the right id to ask about".**

### ⚠️ And it must not be allowed to answer WHO IS AUTHORIZED

Same family as the wrong-dispatch limit above, found in Phase 0 slice 7 and worth naming
because it is the more seductive of the two.

**The scope answers "whose rows may this query see". It must never be allowed to answer
"who is authorized".**

The case that produced it: `BroadcastChannelsServiceProvider`'s conversation channel did

```php
$conversation = Conversation::find($conversationId);
return self::userCanAccessWorkspace($user, (int) $conversation->workspace_id);
```

`userCanAccessWorkspace()` is **deliberately broader** than the current workspace — it grants
pivot membership, ownership and same-client access. Once `Conversation` was scoped, `find()`
resolved only the CURRENT workspace, so a user with two workspaces was **denied a channel they
were entitled to** whenever the other one was selected.

Nothing failed. Authorization still ran, on a `$conversation` that no longer existed as far as
the query was concerned, and returned false. **The scope had silently replaced a considered
authorization rule with a narrower one, by accident.**

### The shape to look for

Any code that **resolves a model first and judges it second**:

```php
$thing = Model::find($id);          // <- discovery
if (! $thing) { return false; }     // <- now means "not authorized", not "not found"
return someAuthorizationRule($user, $thing);   // <- never reached
```

When the discovery query is scoped and the authorization rule is broader than the scope, the
scope wins and nobody is told. It is worse than the wrong-dispatch limit because it FAILS
CLOSED — it produces a denial, which looks like the system working.

### The rule

Where a check resolves a model in order to judge it, **the discovery query must not be
scoped**. Bypass it, one query wide, inventoried — and leave the authorization rule as the
only thing that decides. That is what the broadcast channel now does.

Where to look: anything with its own membership or access definition. This codebase already
has several, and CLAUDE.md's "grep for OTHER definitions of the same concept" rule exists
because they keep disagreeing.

### Related, from the same slice

- **`failed()` handlers run OUTSIDE job middleware.** Laravel invokes them from the worker's
  exception path, so they have no workspace context and, under the scope, see nothing.
  `ProcessEcommerceWebhookJob` and `ProcessInboundMessageJob` both define one; both only call
  `Log::error()` with ids from their own payload, so neither is broken today. Anyone adding a
  *query* to a `failed()` handler will get an empty result and no indication why. Pinned by
  `failed_handlers_run_outside_the_middleware_and_therefore_have_no_context`.

- **"No context" and "cross-tenant" are not the same thing**, and conflating them was a real
  bug in the first draft of this slice. Because the scope fails closed, running a scheduler
  with *no* context gives it **zero** due campaigns rather than every tenant's — the exact
  silent no-op the middleware exists to abolish. Declared cross-tenant work therefore has to
  actively suppress the scope (`WorkspaceContext::crossTenant()`), not merely decline to set
  one. Caught by a test, not by review.

---

## BUG-018 — the weekly digest window is a day short, from a Carbon mutation

**Severity: Low — recorded 2026-08-08 while writing slice 4b's tests. Not fixed.**

`SendWeeklyDigestCommand::handle()`:

```php
$from   = Carbon::now()->subWeek()->startOfDay();
$to     = Carbon::now()->startOfDay();
$period = $from->format('M j').'–'.$to->subDay()->format('M j, Y');   // <- mutates $to
...
$stats = $this->buildStats($workspace->id, $from, $to);               // <- gets the mutated $to
```

`Carbon` is **mutable**. `$to->subDay()` inside the label expression permanently moves `$to`
back one day, and every `whereBetween('created_at', [$from, $to])` in `buildStats()` then runs
against a **six-day** window ending yesterday, not the seven-day window the email claims.

The label happens to be right; the numbers under it are computed over a different period than
the one printed.

**How it was found:** slice 4b's test seeded conversations at `now()` and got zero, which
initially looked like the workspace scope failing closed — the exact symptom the test was
written to detect. It was not. That is the trap worth recording: *a count of zero has more
than one cause, and the new one will be blamed first.*

**Not fixed here** because it is unrelated to Phase 0 and changing the reporting window
changes numbers customers see. The fix is `$to->copy()->subDay()` (or `CarbonImmutable`).

**Worth a wider look when it is fixed:** `Carbon::now()` is used throughout this codebase, and
this is the failure mode that leaves no trace. Grepping for `->sub`/`->add` used inline inside
a `format()` or a string concatenation would find any siblings.
## BUG-019 — a channel routing identifier claimed by two workspaces sent every message to the wrong tenant — ✅ FIXED

**Severity: High. Found 2026-08-08 while planning Phase 0 slice 4c. Fixed on
`fix/channel-routing-uniqueness`, ahead of the rest of Phase 0.**

`channel_accounts.phone_number_id` had **no unique constraint** — the live schema carried only
`PRIMARY` and a non-unique `workspace_id` index, and the single migration that creates the
table never added one. Meanwhile the sibling tables *are* constrained
(`whatsapp_phone_numbers.phone_number_id` UNIQUE, `whatsapp_business_accounts.waba_id`
UNIQUE), which is what makes it read as an oversight rather than a decision.

### How a duplicate was created — the application produced it

```php
$account = ChannelAccount::firstOrNew([
    'workspace_id'    => $waba->workspace_id,   // ← keyed on the NEW workspace
    'phone_number_id' => $phoneNumberId,
]);
```

Keyed on **both** columns, a number already held by another workspace was a MISS, so a second
row was INSERTED. The duplicate was not merely unprevented; it was the designed outcome of the
query. All four attach sites had this shape.

In the same method, `WhatsappPhoneNumber::updateOrCreate(['phone_number_id' => $id], …)` is
keyed on the identifier **alone**, so that table *moved* while `channel_accounts` *forked* —
the two tables then disagreed about who owned the number.

### What it cost

The inbound router called `->first()` on an unordered query, so it silently picked one row —
in practice the oldest. **Every message for that number kept landing in the previous tenant's
inbox, indefinitely, with no error.** The new tenant saw silence and assumed setup had failed.

HMAC signature verification does **not** bound this. It proves the payload came from Meta; it
says nothing about which workspace the identifier belongs to.

### Accidental, not malicious — and routine under the roadmap

Meta OAuth blocks the malicious path: you can only connect WABAs and Pages you own. The
accidental path needs no attacker — a business leaving reseller A and re-onboarding under
reseller B reconnects the same number. **The partner tier makes that a supported business
event**, so this would have moved from edge case to normal operation.

### The three shapes, and why the schema fixes only one

| Channel | Identifier | Protection |
|---|---|---|
| WhatsApp | `phone_number_id` — a real column | **UNIQUE index** ✓ |
| Messenger | `meta_json->page_id` — a JSON path | guard only |
| Instagram | `meta_json->instagram_page_id` **OR** `instagram_account_id` | guard only |

Three findings from the sweep that changed the design:

1. **`webhooks/meta/{token}` validates a PLATFORM-GLOBAL verify token** — identical for every
   tenant. So Messenger and Instagram carry **no per-tenant identifier in the URL at all**,
   and routing rests entirely on the JSON match. WhatsApp at least has the per-WABA token
   path, whose token is unique. **The Meta channels are the worse case, not the equal one.**
2. **Instagram matches two keys with `orWhere`**, so uniqueness has to hold across the *set*.
3. **A generated column + unique index was therefore rejected.** It would cover Messenger and
   not Instagram, and a schema that protects two of three while appearing to protect all three
   is worse than one that protects one and says so.

**`ChannelAccountRouting` is the load-bearing control. The index is a backstop.** That is
stated in the service, in the migration, and in the guard test — because anyone reading the
unique index will otherwise assume the database handles this.

### Ownership on conflict: REFUSE. Ruled 2026-08-08.

Zero customers today, no legitimate reconnect flow exists, and refusing fails closed. A real
customer blocked by this opens a support ticket — that is a person noticing. A silent reassign
is visible to nobody, and if it is wrong one company reads another's conversations.

**Deferred design, to build when the partner tier makes moving a channel a real event:**
deactivate the old row, write an audit entry, notify both workspaces, and require an explicit
confirmation naming the losing workspace. That is a feature, not a constraint change.

### Ambiguity is visible without reading logs

If two rows ever exist anyway — legacy data, a race, a bug — the router **refuses** rather than
guessing, and writes an `audit_logs` row (`channel.routing_ambiguous`) surfaced at
`/admin/audit-log`, plus `report()` for Sentry when configured.

It does **not** fail the job. The drivers' per-message `try/catch` is a decision already made
correctly: one poisoned identifier must not stop the other messages in the same payload.
A counter-then-throw was considered and rejected — it would retry the whole payload forever
and make "some messages were ambiguous" indistinguishable from "this job is broken".

### Detection before the index

`ALTER TABLE … ADD UNIQUE` fails naming exactly **one** arbitrary offending value, which is
useless for planning. The migration therefore **pre-flights** and aborts with the complete
list, and `php artisan channels:audit-routing` reports all three channels.

**Report only, no `--fix`, and there will not be one:** which workspace legitimately owns a
number is a business fact. The newest row may be a genuine migration between agencies or a
mis-onboarding, and choosing wrong causes the exact failure this branch prevents. Same
reasoning as the SEC-004 storage inventory.

Verified on this machine: `channel_accounts` **0 rows**, 0 duplicates. Nothing to reconcile
here; a deployed install must run the audit first.

### Recorded, not fixed

- **`campaign_recipients.provider_message_id` is not unique.** SMS delivery-status callbacks
  match on it. Lower severity — a status update, not message content, and provider SIDs are
  globally unique in practice — but it is the same shape and nothing enforces it.
- **`ecommerce_stores.webhook_secret` is not unique**, which is fine: the store is identified
  by the route's uuid and the secret only verifies HMAC for that store. Recorded so the next
  sweep does not re-raise it.

---

## 📌 Correction — the BUG-009 orphan sweep had a blind spot (recorded 2026-08-08)

In the Phase 0 STEP-1 inventory I reported: **"BUG-009 has exactly one sibling."** That was
wrong, and the shape of the error matters more than the count.

The sweep looked for tables with `client_id` or `user_id` and **no** `workspace_id`. Tables
with **none of the three** were invisible to it — and child tables keyed only to a scoped
parent are exactly that shape. Re-running properly finds **six**:

| Table | Reaches its workspace via |
|---|---|
| `automation_runs` | `automation_id` → `automations` |
| `ai_runs` | `conversation_id` → `conversations` |
| `campaign_recipients` | `campaign_id` → `campaigns` |
| `contact_tag_pivot` | `contact_id` → `contacts` |
| `inbox_label_conversation` | `conversation_id` → `conversations` |
| `segment_contact` | `contact_id` → `contacts` |

So it was one sibling **of that shape**. These are a second shape the sweep could not see.

**Four are pure pivots** (`contact_tag_pivot`, `inbox_label_conversation`, `segment_contact`,
and effectively `campaign_recipients`) and are protected by their parent: they are only
reachable through a row the scope already filtered.

### Decision: `automation_runs` stays unscoped — recorded, not omitted

Considered and rejected for Phase 0:

- Its parent `Automation` **is** in the 27, so a run is only reachable through an
  already-filtered automation.
- Adding the column means a migration plus a backfill, on a table that will be large.
- `SendWeeklyDigestCommand` already joins `ai_runs` and `campaign_recipients` **through their
  parents**, which is the pattern that keeps working either way.

`ExecuteAutomationRunJob` therefore resolves its tenant through the relation
(`EstablishesWorkspaceContext::through(AutomationRun::class, $runId, 'automation_id',
Automation::class)`) rather than from a column on the run itself.

**Revisit if** a query ever needs to reach runs *without* going through their automation —
a partner-level "all automation activity" report is the obvious candidate, and it is on the
roadmap.

### The lesson worth keeping

A sweep is only as good as the shape it looks for. This one asked "which tables have a tenant
key but the wrong one" and could not see "which tables have no tenant key at all". The second
question needed a different query, and nothing about the first hinted that it was missing.

---

## BUG-020 — the lead scraper's write key collides across workspaces

**Severity: High if it were reachable. It is not — the scraper has never worked (BUG-007).
Found 2026-08-08 by Phase 0 slice 5, with Lead as the canary. Recorded, not fixed.**

`GooglePlacesScraper::persistPlace()`:

```php
Lead::updateOrCreate(
    ['google_place_id' => $placeId],          // <- the ONLY lookup key
    ['workspace_id' => $workspaceId, ...],
);
```

and the schema (`2026_04_30_000800_create_leads_tables.php:26`):

```php
$table->string('google_place_id', 128)->nullable()->unique();   // GLOBAL unique
```

**Same shape as BUG-019**: a globally-unique third-party identifier used as a lookup key
without the tenant.

### Two different failures, before and after the workspace scope

**Before** — workspace B scrapes a business workspace A already scraped. The lookup matches
**A's row** and the update overwrites `workspace_id` to B. **A's lead is silently moved to
another tenant.** No error, no trace.

**After** (Phase 0 slice 5) — the lookup becomes `google_place_id = X AND workspace_id = B`,
misses A's row, attempts an INSERT, and the global unique index refuses it. The scrape job
fails loudly instead.

The scope converts silent cross-tenant theft into a hard failure. That is an improvement and
not a fix: the scraper still cannot scrape a business another tenant has already scraped —
which, for a lead-generation product where popular businesses are the point, is not a rare
edge case.

Both behaviours are pinned by
`LeadScopeTest::the_scraper_write_key_collides_across_workspaces_and_the_scope_turns_theft_into_a_hard_failure`,
with a positive control proving a SAME-workspace re-scrape still updates in place.

### The fix, when BUG-007's scraper is built

Two changes, and they belong together:

1. Key the upsert on `['workspace_id' => $wsId, 'google_place_id' => $placeId]`.
2. Replace the global unique index with a composite `UNIQUE (workspace_id, google_place_id)`
   — the same correction BUG-019 made for `channel_accounts`.

**Do not do (1) without (2):** the composite lookup would then attempt an insert that the
global index still refuses. And **(2) needs the same pre-flight as BUG-019's migration** —
on a deployed install, existing rows may already collide.

**This must be part of building the scraper, not a follow-up.** Shipping BUG-007's fix alone
would take a dormant defect and make it live — precisely the "when a fix reveals a second bug
the first was masking" rule in CLAUDE.md.


---

## 📌 `subscriptions` is NOT an orphan — do not give it a workspace_id

Recorded 2026-08-09, during Phase 0 slice 9, so it is not picked up later as unfinished work.

`subscriptions` has `user_id` and no `workspace_id`, which makes it look like the same shape as
`onboarding_steps` and `webhook_endpoints`. It is not.

It is a **client-level billing model**, and `CLAUDE.md` already records the open question:

> **`Subscription` (user_id) vs `ClientSubscription` (client_id)**: `ClientSubscription` is
> authoritative for billing. `Subscription` appears to have no live writers — **verify against
> the billing gateways and seeders before marking it deprecated.**

Adding `workspace_id` would be **deciding the relationship between two billing models** — which
of them is authoritative, and whether a subscription is per workspace or per organisation.
That is a billing decision requiring the gateway and seeder verification the handover asks
for, not a tenancy cleanup.

Billing belongs to the client, not the workspace: a client with three workspaces has one
subscription. Scoping it per workspace would be wrong even if it were easy.

**Leave it alone until the Subscription/ClientSubscription question is settled.**

---

## BUG-021 — 121 of 154 foreign-key-shaped columns have no foreign key

**Severity: Medium — STRUCTURAL, not a live defect. Recorded 2026-08-09. Deferred by ruling:
not a patch, and not a same-day follow-up.**

Measured on the working database:

| | Count |
|---|---|
| `*_id` columns (excluding `id`) | **154** |
| With a real FK constraint | **35** |
| **Without** | **121** |

Across the three tenant keys specifically:

| Column | Lacking an FK |
|---|---|
| `client_id` | 3 of 6 (`audit_logs`, `users`, `workspaces`) |
| `workspace_id` | **30 of 31** |
| `user_id` | 5 of 17 |

**38 unconstrained tenant-key columns. Zero orphan rows** — every affected table is empty, so
nothing is broken today. This is a structural gap, not a defect with symptoms.

### Why it happened, at least in part

Not carelessness. The three `client_id` cases are documented in the migrations themselves as
an **ordering constraint** — `users` (`0001_01_01`), `workspaces` (`2025_02_25_000004`) and
`audit_logs` (`2025_02_25_600001`) were all created BEFORE `clients` (`2026_03_04_100001`),
and you cannot reference a table that does not exist yet:

> `// workspaces.client_id is a plain column (no FK) — clients are created in a later migration.`

The three `client_id` columns that DO have FKs were all created after `clients`. Whether the
same explanation covers the 30 unconstrained `workspace_id` columns has not been checked.

### Why this is a project, not a patch

Each column needs a **per-table decision** — `cascade`, `restrict`, or `nullOnDelete` — and
those are not interchangeable:

- an `audit_logs` row should probably OUTLIVE the client it refers to (`nullOnDelete`);
- a `workspaces` row must NOT be silently orphaned (`restrict`);
- a pivot row probably should cascade.

Getting one wrong is worse than having none: `cascade` where `restrict` belonged deletes
customer data on an operation nobody thought was destructive, and `nullOnDelete` where
`restrict` belonged silently reparents rows — the same failure mode recorded for
`clients.partner_id`, where `nullOnDelete` would have converted a partner's customers into
direct ones and changed who bills them.

Thirty-eight of those decisions is a body of work with its own review, not a migration
someone writes between two other tasks.

### ⚠️ Schedule it BEFORE launch, not after

It gets materially harder once there are customers. Today every table is empty, so each
constraint is a pure `ALTER` with nothing to reconcile. With live data, every one needs an
orphan sweep first, and any orphans found are a data decision per row — the shape BUG-019's
migration pre-flight exists for. The cheapest this will ever be is now.

### ⚠️ DO NOT "fix" the inconsistency by removing `clients.partner_id`'s FK

`clients.partner_id` has a proper FK with `ON DELETE RESTRICT`, added 2026-08-09 with the
partner tier. That makes it inconsistent with 121 columns that have none.

**Keep it.** New work should be correct even where old work is not. The consistency argument
runs the wrong way here: the right resolution is to raise the other 121, not to lower this
one. Anyone reading the schema and seeing a lone FK should read it as the standard the rest
has not reached yet — which is exactly what it is.
