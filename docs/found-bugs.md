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
