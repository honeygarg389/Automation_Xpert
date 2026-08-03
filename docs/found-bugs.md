# Found Bugs

Defects discovered incidentally while working on Phase 0 (tenant isolation), out of scope for
the branch that found them and tracked here instead of being fixed on the spot or silently
baselined away. Add an entry whenever a bug is found but not fixed in the moment.

Severity: **Critical** (crashes/data loss for ordinary use) · **Medium** (real but narrow or
non-crashing) · **Low** (cosmetic, debt, or static-analysis noise).

---

## BUG-001 — `SocialPostController::update()` 500s on an ordinary edit

- **Severity:** Critical — **fix before any customer exists, not "someday"**
- **File:** `app/Modules/Social/Http/Controllers/SocialPostController.php`, ~line 222
- **Status:** Live on `master`. Found 2026-08-03 while writing 1c tests for the Social module (`fix/workspace-context-1c-social`); not fixed there — unrelated to workspace-context.
- **User-facing:** Yes.

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

**Blast radius:** every workspace, every social post edit that doesn't explicitly resend a
scheduled time — plausibly the majority of edits to an already-drafted post.

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
