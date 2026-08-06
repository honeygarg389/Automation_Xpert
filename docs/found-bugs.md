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

## BUG-005 — Gemini API key travels in the URL and reaches logs, the DB, and API responses — ✅ FIXED

**Severity:** Critical (credential disclosure) — violated the CLAUDE.md rule that provider
tokens are never logged.
**Found:** 2026-08-06, at the Group D Gemini gate. **Fixed:** 2026-08-06 on
`fix/gemini-key-in-url`.

> **Note on numbering.** This entry was first written on `fix/retry-backoff-jitter`
> (commit `75c2bea`) alongside BUG-004, listing only the two `GeminiProvider` sites. The
> version here is authoritative: it names the **third** site found later, and records the
> fix. When that branch merges, keep this entry and drop the older one.

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

**`RequestException` was never a vector** — `prepareMessage()` builds its message from the
response status and body only, never the request URL. Only `ConnectionException` leaked,
which is precisely the condition retries make more likely to be hit repeatedly.

**Fix:** send the key as the `x-goog-api-key` header at all three sites and remove the query
parameter from the URL entirely. Confirmed against Google's current REST documentation for
both `generateContent` and `batchEmbedContents`; the embeddings reference shows only the
header form. The `?key=` form still works for backwards compatibility, so this is not a
breaking migration.

All four paths close from this single change, because the secret is no longer in the URI for
`ConnectionException` to carry. **None of the four needed its own change.**

**What this fix does NOT do.** The four paths remain open as *mechanisms* —
`AiChatbotController:112` still returns a raw `$e->getMessage()` to the browser for any
exception. This change removed the secret from the message; it did not make those paths safe.
See BUG-006, which leaks through the same four paths and is not fixed.

---

## BUG-006 — Google Places API key leaks identically via the array query form

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

**Sites:**

| # | File | Line | Endpoint |
|---|---|---|---|
| 1 | `Leads/Services/GooglePlacesScraper.php` | 40, 45 | Places `textsearch` |
| 2 | `Leads/Services/GooglePlacesScraper.php` | 76–79 | Places `details` |
| 3 | `Integrations/Services/ConnectionTester.php` | 176 | Places `textsearch` (admin test button) |

It reaches the **same five paths** listed in BUG-005 — the errors log channel,
`failed_jobs.exception`, the admin queue UI, and any `catch (\Throwable)` that returns
`getMessage()` to a client. `GooglePlacesScraper` runs inside `ScrapeLeadsJob`, so path 2
(the `failed_jobs` table) is the most likely destination.

**Why it is not fixed with BUG-005.** The Google Places API has **no header-key equivalent** —
`key` is a required query parameter — so the `x-goog-api-key` remedy does not apply. This
needs a different fix and belongs on its own branch. Options, none evaluated in depth yet:

1. Scrub the query string from `ConnectionException` messages centrally, before anything
   reports them — this would also protect every future query-param credential, and is the
   only option that fixes the class rather than the instance.
2. Stop returning raw `$e->getMessage()` to clients and to logs at the four paths, which is
   worth doing regardless of this bug.
3. Catch `ConnectionException` at the Places call sites and rethrow with a scrubbed message —
   narrowest, but leaves the general mechanism open.

Option 1 is the one that would have prevented BUG-005 too. **Decide deliberately; do not
default to the narrowest fix.**
