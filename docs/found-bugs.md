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

## BUG-005 — Gemini API key travels in the URL and reaches logs, the DB, and API responses

**Severity:** Critical (credential disclosure) — **violates the CLAUDE.md rule that provider
tokens are never logged**
**Found:** 2026-08-06, at the Group D Gemini gate. **Not fixed** — GeminiProvider was
deliberately excluded from Group D pending this decision.

`GeminiProvider` authenticates with a query-string parameter, not a header:

```php
// AI/Services/Llm/GeminiProvider.php:44 and :76
->post(self::BASE."/models/{$model}:generateContent?key={$this->apiKey}", $body);
```

On a **connection failure** (DNS, TLS, timeout — not an HTTP error status), Guzzle builds the
message and appends the URI verbatim:

```php
// vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:271
$message .= sprintf(' for %s', $redactedUriString);
```

`Utils::redactUserInfo()` redacts only `user:pass@host` credentials — **it does not touch the
query string**. Laravel wraps that message unchanged in `ConnectionException`. The key is now
inside an exception message, and four paths record it:

| # | Path | Where it ends up |
|---|---|---|
| 1 | `bootstrap/app.php:146` — `Log::channel('errors')->error($e->getMessage(), …)` | **every** reportable exception's message is logged, on disk |
| 2 | `failed_jobs.exception` (`longText`) via `IndexDocumentJob` → `LlmGateway::embed()` | persisted in the database |
| 3 | `Admin/QueueController.php:29` → `Admin/Queue/Index.jsx:72` | rendered in the admin UI (`title={job.exception}` shows the full text on hover) |
| 4 | `AiChatbotController.php:112` — `catch (\Throwable $e)` → `response()->json(['error' => $e->getMessage()], 422)` | **returned to the browser** |

Path 4 is the worst: it is not merely logging, it hands the key to the HTTP client. The chain
is reachable — `AiChatbotController::test()` → `ChatbotRunner::run()` → `LlmGateway::chat()` →
`LlmManager::forWorkspace()`, and `LlmManager:91` constructs `GeminiProvider` whenever the
workspace selects `gemini` (it is in both the chat and embed fallback orders).

Sentry (`bootstrap/app.php:154`) would capture it too; currently inert because no DSN is set.

**Note:** `RequestException` is *not* a vector — `prepareMessage()` builds its message from the
response status and body only, never the request URL. **Only `ConnectionException` leaks.** So
this needs a network failure, not an API error, which is exactly the condition retries make
more likely to be hit repeatedly.

**Suggested fix (not applied, not approved):** send the key as the `x-goog-api-key` header,
which the Gemini REST API accepts, so it never enters a URI. Failing that, scrub the query
string from `ConnectionException` messages centrally before anything reports them. The
existing log/response paths should also be reviewed for returning raw `getMessage()` to users.
