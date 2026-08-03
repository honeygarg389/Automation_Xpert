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

**Suggested approach when scoped:** triage by whether the field is genuinely optional in the
UI — a field the frontend always sends cannot trigger the bug in practice, even though the
pattern is fragile. Prioritise anything reachable from a form with optional inputs. Note that
BUG-001 was found by accident, not by looking; the others will not surface on their own.
