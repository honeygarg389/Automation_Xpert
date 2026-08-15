# Smart QR — rulings

Decisions made in conversation, recorded because a ruling that lives only in a chat log is
indistinguishable six weeks later from a decision nobody made.

**⚠️ Which is which:** the production spec is `docs/specs/Complete production-focused Smart QR &
Dynamic QR Module.pdf`. Some rulings below are the OWNER'S, layered on top of the spec after it
was written. Where that is the case it is marked, because a later reader must be able to tell a
spec requirement from a decision made afterwards.

---

## R-1 — Assignment is to a WORKSPACE, not a client

**Resolves a genuine ambiguity in the spec.**

The spec assigns a QR to a *"tenant/customer"* and separately requires that the WhatsApp channel
and the assigned user *"belong to the selected tenant"*. This codebase has **both** a `client`
(organisation) and a `workspace`, and `ChannelAccount` is **workspace**-scoped — so for a client
with three workspaces, "the channel belongs to the tenant" has no single answer.

**Ruled: the workspace.** CLAUDE.md rule 2 makes `workspace_id` the operational tenant boundary
for all customer-owned data, and the spec was written without knowing both existed.

---

## R-2 — §13 wins over §25 on advanced QR customisation

**The spec contradicts itself.** §13: *"Do not expose advanced pattern/finder customization in
MVP."* §25, final line: *"**Do** implement advanced QR patterns, coloured QR styling, custom
finder shapes, or customer-logo designer in the MVP."*

§25's line also contradicts §14 (*"For MVP, use AutomationXpert logo only"*) and the MVP list two
lines above it. Almost certainly a dropped "not".

**Ruled: build to §13.** No advanced patterns, no coloured styling, no custom finder shapes, no
customer-logo designer. Flagged for the owner to confirm.

---

## R-3 — QR type and destination type are OWNER RULINGS, not spec requirements

⚠️ **Neither is in the spec.** Recorded here so nobody later cites the document for them.

- **QR type** = placement/category: Counter, Table, Reception, Staff, Packaging, Storefront,
  Event, Product, Custom. The spec carries a `qr_type` field but **never enumerates its values**.
  Stored as a plain string so the vocabulary can change without a migration.
- **Destination type** = a separate field, MVP value `whatsapp`, fixed. **The spec has no
  destination-type concept at all** — it is WhatsApp-only end to end. The architecture must sit
  behind a resolver so `url`, `google_review`, `vcard`, `wifi`, `lead_form`, `appointment`,
  `payment`, `catalogue`, `custom` can be added later, but **no UI and no runtime** for any of
  them.

---

## R-4 — Lifecycle ownership: platform-owned at birth, tenant-owned on assignment

A QR code is printed before anyone knows which customer will receive it, and can be reassigned.
`BelongsToWorkspace` fails closed, so an unassigned code would be invisible to every tenant **and**
to the Super Admin inventory screen that exists to manage exactly those rows. A nullable column
does not help: NULL satisfies no equality comparison.

**Ruled: option (a)** — `smart_qr_codes` carries **no `workspace_id`** and no trait. Tenancy lives
on `smart_qr_assignments`, which is workspace-scoped normally. Two non-negotiable conditions,
both met in the same commit as the model:

1. **The grep guard ships with the model.** `SmartQrAccessGuardTest` fails the build on any
   `SmartQrCode::` query outside `SmartQrAccess` and the admin namespace. A guard that lags
   behind the thing it guards protects against the *next* mistake, not this one.
2. **The `NEVER_SCOPED` standard gained a third branch** — *"not yet"*, for lifecycle-owned rows —
   with this module as the worked example. See `WorkspaceScopeCoverageGuardTest`.

**Scans carry `smart_qr_assignment_id`, not `workspace_id`.** Denormalising a tenant onto a row
whose tenant *changes* produces two sources of truth that disagree the moment a QR is reassigned:
the old scans would keep asserting the old workspace while the code belongs to a new one, and
someone would eventually "fix" one of them. Keying by assignment makes the period intrinsic, so
"a reassignment hides the previous tenant's analytics" needs no date arithmetic and no data
migration.

---

## R-5 — Where entitlements are enforced: three different places, three different kinds

**Nothing is consumed at generation.** Codes are platform inventory; no workspace exists to
charge, and `smart_qr_codes` has no `workspace_id` to resolve an entitlement *for*.

| Key | Kind | Enforced at | Slice |
|---|---|---|---|
| `smart_qr_max_assigned` | **gauge** — `COUNT(*)` of current assignments | assignment | 3 |
| `smart_qr_scans_per_month` | **counter** — `UsageMeter` on the scan path | scan | 4 |
| `smart_qr_enabled` | **boolean** — feature flag | display | dashboard |

The gauge slots into `GaugeSources` and inherits everything Phase 1 slice 4 built. Recorded as
three rows rather than prose so slice 3 and the dashboard slice each know which one is theirs.

---

## R-6 — QR library: `endroid/qr-code:^5.1`, at slice 8

**Approved, and NOT to be installed until slice 8 needs it.**

| Option | PHP | Verdict |
|---|---|---|
| `endroid/qr-code` **v6** | **^8.4** | rejected — raises the floor against CLAUDE.md's 8.2+ |
| `endroid/qr-code` **v5.1** | ^8.1 | **approved** |
| `bacon/bacon-qr-code` standalone | ^8.1 | viable, but see below |
| JS-side generation | — | rejected, see below |

**There is no zero-transitive PHP option.** Bacon *is* the rendering engine; endroid is a thin
wrapper over it. Standalone bacon still pulls `dasprid/enum`. So the real choice is
**wrapper + engine**, or **engine plus hand-written logo compositing and an SVG writer** — work
endroid already does, correctly, under MIT.

**The JS route was rejected** because §14 requires server-side SVG, high-resolution PNG, printable
PDF and a ZIP of 500, generated in a queued job with no browser present. A JS-only approach means
either headless Chrome in the queue — a far heavier dependency — or two libraries that must agree
pixel-for-pixel. Client-side generation remains fine for the customer's on-screen preview.

This is the **first new package added in this work**. CLAUDE.md says do not add unnecessary
packages; this one is necessary, and it was verified that the project contains no QR library and
no internal helper (2FA only produces an `otpauth://` string and renders nothing).

---

## R-7 — The gauge filter lands in slice 3, not its own branch

`smart_qr_max_assigned` must count **current** assignments only, and `GaugeReader` has no
filtered-count support. That is Phase 1 code changing inside a Smart QR slice, which is the shape
that produced the cross-branch tangle of 2026-08-13 — so it was escalated rather than absorbed.

**Ruled: do it here.** Two measurements decided it:

- **Inertness is provable by ABSENCE.** All seven existing `GaugeSources::MAP` entries carry
  exactly `model` and `scope` — `0` entries carry any third key. A branch guarded on a `where`
  key cannot execute for them.
- **No other branch touches the files.** `master`, `docs/billing-findings`,
  `docs/billing-gateway-cleanup` and `feature/entitlement-presentation` each modify
  `GaugeSources.php` and `GaugeReader.php` **zero** times.

### ⚠️ Two conditions on the implementation

1. **Guard on the KEY's absence, with `isset()`** — not on truthiness. A future
   `'where' => null` must still not fire the branch. Truthiness would treat an explicitly-null
   filter as "no filter", which is the same conflation of *absent* and *null* that BUG-030 is
   about.
2. **The unmoved-seven test must discriminate.** "The seven are unchanged" passes trivially
   against untouched code and proves nothing. The test must temporarily add a `where` to one of
   the seven, prove its count **changes**, remove it, and prove it **returns**. Otherwise it is
   the vacuous shape this project has caught eight times.

### The discriminator for the assignment gauge

`smart_qr_assignments` keeps history: a reassignment sets `unassigned_at` and leaves the row. So
an **unfiltered** count returns every assignment the workspace has *ever* held.

A workspace that held five codes and had all five reassigned away would read **5 used, 0 current**
— at its limit while owning nothing. Worse, the count is monotonic, so a workspace that churns
codes is permanently locked out.

**The test:** `N = 2` current and `M = 3` ended assignments for one workspace.

| Implementation | Returns |
|---|---|
| filtered (correct) | **2** |
| unfiltered (wrong) | **5** |

Distinct numbers, so it cannot pass by coincidence.

---

## R-8 — Over-limit assignment: refuse by default, override with a REQUIRED reason

An admin assigning a QR to a workspace already at `smart_qr_max_assigned` is **refused**, with the
count in the error.

An **override** exists, and matches the shape CLAUDE.md rule 9 already establishes for manual
entitlement grants:

- permission-gated
- **carries a REQUIRED reason, enforced at the signature — not a nullable column**
- audit-logged

⚠️ **The reason must be structurally required.** An optional reason is an empty reason six weeks
later, and then nobody knows why a limit was broken. This is the same reasoning as
`withoutWorkspaceScope('reason: …')` taking its argument rather than documenting it: a rule that
depends on remembering is not a rule.

Refusing by default keeps the limit meaningful; the override keeps admins from having to fight the
tool for legitimate exceptions — and records which was which.

---

## R-9 — Modal, not the spec's ten-step wizard

The spec (§6) describes assignment as ten sequential steps ending in "confirm" — a wizard.

**Ruled: build it as a single modal form**, matching the existing admin surfaces.

⚠️ Recorded as a **deliberate departure**, not a shortcut. This codebase contains no wizard
component anywhere; introducing one to match a described UX means maintaining a pattern with a
single caller, which is a cost paid forever for one screen. The spec was written without seeing
the code — the same reason its batch field list omitted `failure_reason` (found in slice 2) and
its "tenant/customer" needed R-1.

The ten steps become the fields of one form. Nothing in the described flow requires sequencing:
no step's options depend on a later step, and the only dependency — channel and user must belong
to the chosen workspace — is a validation, not an ordering.

---

## R-5 — AMENDED: the gauge lands in TWO declarations, not one

R-5's table said `smart_qr_max_assigned` "slots into `GaugeSources`". That is half the wiring
and the record should not read as though it were all of it.

`plans.limits` carries no kind and no unit, so `PlanLimitKinds::MAP` is what declares a key to
BE a gauge. Without an entry there:

- `PlanLimitKinds::kindOf('smart_qr_max_assigned')` returns `null`, so nothing downstream knows
  it is a cardinality limit rather than a per-period counter;
- `GaugeReaderTest::every_gauge_is_either_sourced_or_explicitly_excluded` iterates
  `PlanLimitKinds::keysOfKind('gauge')`, so the key would never be checked for having a source
  at all — the guard that exists to stop a gauge going silently unenforced would not see it.

**Ruled: both.** `PlanLimitKinds::MAP` declares the kind and unit; `GaugeSources::MAP` declares
the model, the tenant boundary and — new in this slice — the filter.

---

## R-7 — AMENDED: the tripwire is NARROWED, and condition 2 was not implementable as written

Two things R-7 did not anticipate, both found by reading the code it applies to.

### (a) The tripwire fires here, and its own instruction is wrong for this case

`GaugeReaderTest::the_bypass_becomes_load_bearing_when_slice_8_scopes_a_gauge_model` asserts
that NO model in `GaugeSources::MAP` carries `scopeWithoutWorkspaceScope`. `SmartQrAssignment`
uses `BelongsToWorkspace`. So adding `smart_qr_max_assigned` to `MAP` fails that test
immediately — designed to fire when Phase 0 slice 8 CONVERTS an existing gauge model, firing
instead because a new model arrived already scoped.

Its docblock says "delete this test at that point". **That instruction is wrong here.** The
other six models are still unscoped, so `gauges_count_correctly_with_no_workspace_context`
— which seeds `chatbots` — still cannot fail. Deleting the tripwire would erase the
MEASUREMENT that fact represents.

**Ruled: narrow it to the still-unscoped six**, and take the other half of the observation:
**re-point `gauges_count_correctly_with_no_workspace_context` at `smart_qr_max_assigned`.**
It is the first gauge where `GaugeReader`'s scope bypass is LIVE, so the re-point converts a
dormant test into one that genuinely fails when the bypass is removed. Both tests carry an
inline note saying why they changed.

### (b) Condition 2 required a seam that did not exist

R-7 condition 2: the unmoved-seven test must "temporarily add a `where` to one of the seven,
prove its count changes, remove it, prove it returns."

**That was not implementable against the code as written.** `GaugeSources::MAP` is a `const` on
a `final` class — nothing can mutate it, and no test double can replace it, because
`GaugeReader` called the static directly.

The alternative was a test asserting only "the seven are unchanged", which passes trivially
against untouched code — the exact vacuous shape condition 2 exists to forbid.

**Ruled: add the seam.** `GaugeReader::sourceFor(string $key): ?array` is a protected method
returning `GaugeSources::for($key)`, and the discriminator test subclasses `GaugeReader` to
override it. This is production code shaped by a test, deliberately and with the reason stated
at the method. The name is neutral — it describes what it does, not that a test uses it.

---

## R-10 — `codes.status` is PHYSICAL only; "assigned" is derived, never stored

The spec (§5) gives ONE status list: `generated, printed, assigned, active, inactive, damaged,
lost, retired`. Slice 1 built TWO status columns, because those values answer two different
questions about two different rows.

**Ruled:**

| Column | Vocabulary | Answers |
|---|---|---|
| `smart_qr_codes.status` | `generated, printed, damaged, lost, retired` | what happened to the physical sticker |
| `smart_qr_assignments.status` | `active, inactive, ended` | whether the tenant's mapping is live |
| — *(nothing)* | `assigned` | **derived** from the current-assignment index |

**Storing `assigned` would be a second source of truth beside a DB-ENFORCED one.** Whether a
code is assigned is already answered, exactly and atomically, by the unique index over the
`current_code_id` generated column. A status string maintained in application code beside it
would disagree the first time an assignment was written by a seeder, a raw insert, or a request
that failed between the two writes — and then someone would "fix" whichever one they found
first.

This is the same reasoning R-4 used to keep `workspace_id` off `smart_qr_codes`, and the shape
this codebase has been bitten by repeatedly: `accessibleWorkspaces()` vs `isAccessibleBy()`,
`whatsapp_global` vs `whatsapp_msg`, `PlanLimits.jsx` vs `defaultLimits()`, `activePlan()` vs
`effectiveSubscription()`.

---

## R-11 — Bulk assignment is ALL-OR-NOTHING, in one transaction, with the count in the error

§6 step 1 is "select one or more QR codes", so five codes may be assigned to a workspace with
three slots remaining. R-8 ruled the refusal; it did not rule the multiplicity.

**Ruled: refuse all five. One transaction. The error names current, limit and requested.**

Partial assignment is the worst outcome available. The admin believes five landed, three did,
and nobody finds out until a customer reports a QR that goes nowhere — silent, and discovered
by the customer rather than by us. Refusing five is loud, immediate and recoverable.

⚠️ **The test must discriminate.** "An error was returned" passes against partial assignment,
because the partial implementation errors too — after committing three rows. The assertion is
**zero rows written**.

---

## R-12 — `assigned_count` is DERIVED, never stored

The spec's §4 batch field list names `assigned_count`. Slice 1 did not build the column.

**Ruled: keep it derived** — a `withCount` over current assignments, computed at read time.

A stored counter must be incremented on assign and decremented on unassign, by every path that
ever writes an assignment, forever. It will drift, and **drift in a count nobody checks is
invisible** — the number stays plausible and stops being true. The derived count cannot drift,
because there is nothing to keep in step.

### ⚠️ FIVE measured discrepancies between the spec and itself, or the spec and the code

Recorded in one table so the pattern is visible rather than rediscovered a sixth time. Every
row was found by building the thing, not by reading the document again.

| # | What the spec did | Found in |
|---|---|---|
| 1 | asks for "record failure reason if a batch generation partially fails", omits `failure_reason` from the §4 field list | slice 2 |
| 2 | assigns to "tenant/customer" in a codebase with both a client and a workspace | R-1 |
| 3 | lists `assigned_count` as a stored batch field where a derived count is correct | R-12 |
| 4 | gives ONE §5 status list for what are two columns with two lifetimes | R-10 |
| 5 | **§6 requires preventing assignment to "inactive or disconnected" channels. There is no `disconnected` state.** | slice 3a |

Plus the §13-vs-§25 self-contradiction (R-2), which is a sixth of a different kind — the spec
contradicting itself outright rather than mismatching the code.

#### On #5, because the fix is not the obvious one

`channel_accounts.status` is `enum('active','inactive','error')` — **measured**, after the
first version of the slice-3a test wrote `'disconnected'` and MySQL truncated it to `''`.

⚠️ **The rule is written as "must be `active`", not "must not be `disconnected`",** and that is
the whole point. A deny-list phrased from the spec's vocabulary would have tested against a
value that cannot occur and let `'error'` through — a channel in a genuine failure state,
pointed at by a printed sticker, passing validation because the spec named a state this system
does not have.

The test asserts BOTH non-active values for the same reason: naming only one would leave the
other untested, and "not active" is the actual rule.

**The spec is a requirements document, not a schema, and not a description of this codebase.**
Its field lists and its vocabulary are read as intent, and checked against the code before
anything is built from them.

---

## R-13 — `smart_qr_max_assigned` = 50 on ALL THREE seeded tiers  ⚠️ OWNER RULING

⚠️ **This supersedes two earlier proposals, and the record shows the progression deliberately —
the equality across tiers will read as an oversight otherwise.**

| # | Values | Source | Status |
|---|---|---|---|
| 1 | Starter 5 / Pro 50 / Business 1000 | proposed in the slice-3 plan | **superseded** |
| 2 | *(the owner's message recalled this as 5/50/5000; no 5000 value was ever proposed)* | — | n/a |
| 3 | **Starter 50 / Pro 50 / Business 50** | **OWNER** | **current** |

**The ruling: 50 on every seeded tier.** Not a platform-wide hard cap sitting above the plan —
a plan limit that happens to hold the same value on all three tiers today.

**Why the number is not a tier differentiator.** 50 is a business decision about the Business
Kit product: it is how many codes a customer of that product is expected to hold. It is not a
technical ceiling and it is not a way to sell an upgrade. Making it differ by tier would encode
a pricing decision nobody has made.

**Why it lives in `plans.limits` rather than in code.** From there the owner can raise it for
one plan, create a new plan carrying a different value, or sell an add-on that grants more —
none of which needs a code change or a deploy. A hard cap above the plan would need one, and
would also make the R-8 override meaningless, since code cannot be overridden by a permission.

**The R-8 override remains the escape hatch for the individual exception.** "This customer
needs 60" is answered by a permission-gated assignment carrying a reason required at the
signature and written to the audit log — without touching the seeder, the plan, or the code.

### ⚠️ Business now carries a finite value where every other limit on that tier is `null`

That is deliberate, it is the departure ruled for, and **it is the owner's number, not the
implementer's.** A gate that cannot fire is not a gate: leaving Business unlimited would make
R-8's refusal unreachable for exactly the customers most likely to hold many codes, which is
how BUG-024's nine unenforceable keys happened.

---

## R-14 — Assignment membership is decided by `Workspace::isAccessibleBy()`

§6 requires "validate that assigned user belongs to the selected tenant". This codebase has
**four** candidate answers, and CLAUDE.md records that two of them have already diverged into a
complete bypass once.

| Candidate | Where | What it actually means |
|---|---|---|
| `users.workspace_id` | column; `Workspace::users()` | the user's PRIMARY workspace — one value, not membership |
| `workspace_user` pivot | `Workspace::members()` | raw rows, **never revoked** — `syncWithoutDetaching`, nothing detaches |
| `User::accessibleWorkspaces()` | `User.php` | owned ∪ pivot, filtered by `client_id` |
| `Workspace::isAccessibleBy()` | `Workspace.php` | `client_id` check, then owner, then pivot |

**Ruled: `Workspace::isAccessibleBy($user)`.**

- It answers the question actually being asked — one user, one workspace, boolean — rather than
  building a collection to search.
- It carries the `client_id` control, so a stale pivot row from a former organisation grants
  nothing. The raw pivot does not.
- It is what `WorkspacePolicy::view` uses, so the QR validator and workspace authorization
  cannot drift into being two answers to one question.
- It needs no scope bypass: `members()` is a `belongsToMany` to `User`, which is never
  workspace-scoped.

**`users.workspace_id` is rejected, and this is the trap.** It reads simpler and is wrong: a
legitimate member whose PRIMARY workspace is a different one would be silently refused, and the
admin would see a valid user missing from the picker with no explanation.

⚠️ **One test carries this**: `a_member_whose_primary_workspace_is_different_is_accepted`. It is
the only test that fails if someone later re-implements membership as `users.workspace_id`.

### ⚠️ The channel check is the OPPOSITE shape

`ChannelAccount` DOES use `BelongsToWorkspace`, and an admin request carries no workspace
context — so a plain query fails CLOSED and rejects every channel, including the correct one.
It must drop the scope explicitly and state `workspace_id` itself: the H-2 shape
`SmartQrAccess::boundedTo()` documents, and the same fail-closed direction that made the
slice-1 canary return 0.

---

## R-15 — Flat sidebar entries, and only for screens that exist

Spec §2 asks for a **"QR Management" group** in the Super Admin navigation with seven children:
Dashboard, QR Batches, QR Inventory, Assignments, Print Exports, Analytics, Settings.

**Ruled: three flat entries.** Two separate reasons, and both are measurements of the code
rather than preferences.

### (a) There is no grouped-nav pattern to reuse

`ADMIN_NAV_ITEMS` in `AdminLayout.jsx` is a **flat array**, and all ~22 existing entries are
single links. There is no nested, collapsible, or sectioned nav component anywhere in this
admin. Building one to match a described UX means maintaining a pattern with **one caller** —
R-9's reasoning, applied to navigation instead of a form.

### (b) ⚠️ Four of the seven screens do not exist, and a nav entry for one would CRASH

`route('admin.qr.analytics.index')` throws at render time when the route is undefined — and
`useAdminNav()` runs inside `AdminLayout`, so the throw takes down **every admin page**, not
just the QR ones. A placeholder entry is not a harmless stub here; it is a site-wide outage.

Dashboard, Print Exports, Analytics and Settings arrive with the slices that build them.

### The permission gate is the READ key, deliberately

All three entries gate on `view_qr_inventory`. The other three keys — `manage_qr_batches`,
`assign_qr_codes`, `override_qr_assignment_limit` — gate **actions inside** the pages, which is
where 3b applies them (create-batch button, bulk actions, assign button, override block).

Gating navigation on a write permission would hide the list from a read-only admin who is
explicitly allowed to see it, and the routes themselves already require `view_qr_inventory` to
GET. One key, matching what the route demands.

---

## R-16 — Batch creation is a modal on the list, not a `Batches/Create` page

3a exposes `batches.index`, `batches.store` (**POST**) and `batches.show`. There is no GET
`batches.create` route, so a create *page* would have nothing to route to — it would require
adding a route for a screen whose entire content is six fields.

**Ruled: a modal over the batch list**, matching `Admin/Clients/Index`, whose add/edit flows are
`Modal` + `Modal.Header/Body/Footer`.

Same reasoning as R-9 once more: the alternative introduces a navigation step this admin does
not otherwise have, for a form that fits in a dialog.

---

## R-17 — NO CACHING on the public redirect. A deliberate refusal of §8.

§8 lists, under redirect performance: *"cache stable QR configuration if safe"* and *"ensure
cache invalidation after assignment/status/message update"*.

**Refused. Do not add it later as a performance win.**

### Why the shape of the requirement is the problem

That is a cache whose correctness depends on somebody remembering to invalidate it. This
codebase produced exactly that failure **one week before slice 4 was written**: BUG-036 — five
invalidators wired to `InvalidateEntitlementCache`, and the one path that needed a sixth (the
admin plan editor) never dispatched anything, so stale limits were enforced silently for up to
an hour, in both directions, with nothing surfaced.

### Why it is worse here than it was there

In BUG-036 the stale value is a **number**. Here the stale value is **which tenant owns the
code**.

A cached assignment surviving a reassignment sends a customer scanning a sticker to the
**previous tenant's WhatsApp number**. That is a cross-tenant leak introduced by an
optimisation — and R-4, the one-current-assignment index, and the whole assignment-period model
exist to make that state unreachable. A cache would reintroduce it above the layer that
prevents it.

### And the optimisation buys almost nothing

The lookup is a single hit against a `unique` index on `public_token`. The resolver issues three
indexed queries total. There is no aggregation, no join fan-out and no N+1 on this path.

**If caching is ever revisited it needs two things first:** a measurement showing the lookup is
actually a bottleneck, and an invalidation path that cannot be forgotten — not one that depends
on every future assignment writer remembering to call it.

---

## R-18 — §7's message hierarchy ships with TWO tiers, not three

§7 specifies: global Smart QR default message → batch default message → individual QR override.

**Ruled: batch → assignment → empty.** The global tier is deliberately not built.

There is no system setting for a global default and adding one would ship a third tier that
**nobody has configured** — a value empty on every installation, read on every scan, and
answering a question no operator has asked. §7's top tier can arrive when something needs it;
`SmartQrRedirectResolver::effectiveMessage()` is where it slots in, and the fallback chain there
is already ordered to receive it.

Two tiers work today. A setting nobody set is not a feature.

---

## R-4 — AMENDED: what survives a reassignment is the AGGREGATES, not the raw scans

⚠️ **This narrows R-4, and it is recorded here rather than only in a retention document because
R-4 is where the guarantee was made.**

R-4 keeps assignment history forever — the row survives, `unassigned_at` closes the period — so
that *"a reassignment hides the previous tenant's analytics"* is true without date arithmetic.
Scans are keyed by `smart_qr_assignment_id` precisely so the previous tenant's data stays
attached to their period.

**Retention policy, ruled in slice 4:**

| Data | Retained |
|---|---|
| raw `smart_qr_scan_events` | **90 days** |
| daily aggregates (slice 7) | **indefinitely** |

So the guarantee R-4 makes becomes: **the previous tenant's AGGREGATES stay reachable, not their
raw scans.** Beyond 90 days the assignment period still exists and still carries their totals;
the per-scan rows behind those totals do not.

That is the right trade — nobody needs per-scan rows from two years ago, and §10 asks for daily
aggregates precisely so the dashboard never reads the raw table — but it is a narrowing of a
ruling already made, so it is written down at the ruling rather than inferred later from a
prune command.

⚠️ **The prune command is NOT built in slice 4.** Deleting raw scans before the aggregates that
replace them exist would destroy data with nothing holding its summary. Policy now, command in
slice 7, alongside the aggregates.

`smart_qr_scan_events` remains the only unboundedly growing table in this project until then.

---

## R-19 — Attributed counts UNDER-REPORT, and the dashboard must say "attributed"

⚠️ **A PRODUCT CONSTRAINT, not a caveat. It changes the labels §10's dashboard is allowed to
use.**

The reference token rides inside the customer's own WhatsApp message, as visible, editable
text. A meaningful share of customers will delete it before sending — it looks like junk, and
deleting it is one obvious action.

So every attributed figure is a **floor, not a total**. Real customers who messaged because of
a QR will be missing from it, and there is no way to recover them (see R-20).

**Ruled: §10's dashboard labels these metrics "attributed" — never "customers who messaged".**

- ✅ "Attributed messages", "attributed conversions", "attributed new contacts"
- ❌ "Customers messaged", "conversions", "customers acquired"

Presenting an under-count as a total is how a tenant concludes the QR does not work and stops
using the product. The honest label costs one word and makes the number defensible.

This binds slice 7's dashboard and any export or report built on these tables.

---

## R-20 — NO FALLBACK ATTRIBUTION. EVER. A REFUSED DESIGN.

§9: when the customer removes the reference, "exact attribution is unavailable" and **must not
be faked**.

**Ruled: there is no fallback, and no method exists that could become one.** Specifically
refused:

- ❌ matching on the customer's phone number against recent scans
- ❌ a time window — "this workspace had a scan four minutes ago"
- ❌ "the only unconsumed session for this assignment"
- ❌ any heuristic combining the above

### Why each is superficially reasonable and quietly wrong

They all credit the QR for a customer who **scanned it, ignored it, and messaged an hour later
from a business card, a website, or a shop sign**. The tenant then reads a conversion figure
describing something that did not happen — and unlike an under-count, an over-count is
invisible: there is no way to look at an inflated number and tell which rows are fictional.

An under-count is honest and can be explained (R-19). A fabricated attribution cannot be
detected, corrected, or apologised for.

⚠️ **`a_message_with_the_reference_stripped_attributes_nothing` is the test guarding this**, and
it guards the honesty of every number §10 reports. If it is ever weakened, the whole analytics
surface becomes unfalsifiable.

---

## R-21 — The redirect gains a SYNCHRONOUS write, reversing slice 4's rule

Slice 4's shape was: read, redirect, defer every write. Slice 5 breaks it — issuing the
attribution session is an in-request `INSERT` on the public redirect path.

**Ruled: accepted, and the reason is that it cannot be deferred.** The token must be **durable
before the redirect is issued**, or there is a window in which the customer sends a message
quoting a reference that does not exist yet — and that attribution is lost with no way to
recover it (R-20 forbids reconstructing it). A queued insert cannot close that window; only an
in-request one can.

### ⚠️ SLICE 4'S FAILURE RULE STILL HOLDS, AND IS RE-PROVEN

**If the session write fails, the redirect must still work.** A customer standing in a shop must
reach WhatsApp even when attribution is broken: a lost analytics row is acceptable, a dead
sticker is not.

The insert is guarded and its failure logged and swallowed, and
`the_redirect_still_works_when_the_attribution_session_cannot_be_written` is the discriminator.
It matters because an implementation that lets a failed write break the redirect **passes every
other test in slice 5** — the failure is only visible from the one direction nothing else looks.

---

## ⚠️ Slice 5 measurement — WHICH barrier fires, for two safety properties

Recorded because in both cases the obvious answer is wrong, and a future reader removing the
"redundant" check would be removing the wrong thing.

| Property | What the test proves fires | What is defence in depth |
|---|---|---|
| cross-tenant attribution refused | the **workspace scope** on `SmartQrAssignment` — the listener runs inside the message's workspace context, so another tenant's assignment is already null | the listener's explicit `workspace_id` comparison |
| one token = one attribution | the **unique index** on `(attribution_session_id, type)` | the `consumed_at` conditional update |

Measured by mutation: removing either second-column item alone leaves the suite green.
Removing the first-column item **and** its partner makes the relevant test fail.

Both second-column checks are kept deliberately. The explicit comparison is the only protection
that survives if that lookup is ever changed to bypass the scope — which every other admin path
in this module does. The `consumed_at` update keeps ordinary repeat messages on a cheap
conditional rather than throwing an exception per message and using a catch for flow control.

---

## ⚠️ OWED — `smart_qr_attribution_sessions.smart_qr_scan_event_id` is nullable and NOTHING fills it

Raised at the end of slice 5. **Must be resolved in slice 6 or 7 — it will not resolve itself,
and an always-null column looks like data loss to whoever finds it next.**

### Why it exists

Slice 4 records the scan on a **queue**; slice 5 issues the attribution token **in-request**,
because the token must be durable before the redirect (R-21). The two rows are therefore created
by different processes at different times and cannot be written together, so the column was
added to be back-filled by the scan job afterwards.

**That back-fill was never written.** Every row has `smart_qr_scan_event_id = NULL` today.

### What it would buy, and what it costs

Linking them lets a conversion be traced to the individual scan — its bot flag, its unique flag,
its referer host. Without it, a conversion is attributable to an *assignment* and a *session*,
but not to the specific scan that produced it.

⚠️ **Nothing consumes that today.** No metric in §10 needs it: `customers messaged`,
`unique customers messaged`, `new contacts` and `conversations started` all resolve through the
session and the assignment.

### Recommendation — DROP IT, unless slice 7 finds a use

I would drop the column rather than wire it, on the evidence:

- No metric needs it, and §10's aggregate dimensions do not include it.
- Wiring it means `RecordQrScanJob` learning about attribution sessions — coupling the scan
  path to the attribution path for a link nothing reads, on the queue that runs for **every**
  scan including bots.
- A back-fill that runs on a queue is best-effort anyway: a dropped job leaves the link null,
  so even wired, the column could never be trusted as non-null. A column that is sometimes
  populated and sometimes not is worse than one that is absent — it invites a query that
  silently omits rows.

**Decide it in slice 7, when the aggregates exist and it is clear whether anything wants
scan-level attribution.** If it is dropped, drop it in the same migration that adds the
aggregate tables. If it is kept, the back-fill belongs in `RecordQrScanJob` and it needs a test
asserting the link survives a retried job.

---

## R-22 — `smart_qr_enabled` is DERIVED from the presence of `smart_qr_max_assigned`

R-5 puts `smart_qr_enabled` at DISPLAY, checked in slice 6. Building that revealed the gate
could never open.

### ⚠️ Nothing could set the flag. Measured.

`PlanPackageSynthesizer::legacyFlags()` was the only source of boolean flags and returned exactly
one — `white_label`. The other source, add-on grants, is Phase 1 slice 6, which is **BLOCKED**
behind BUG-032. Reference count for `smart_qr_enabled` across `app/` and `database/` before this
slice: **zero**.

So gating on the flag as it stood would have **hidden Smart QR from every customer on every
plan**, silently, with the module apparently working in every test.

⚠️ **That is R-13's failure inverted.** R-13 is a gate that can never FIRE (a limit nothing
seeds, so nothing is ever refused). This is a gate that can never OPEN (a flag nothing sets, so
everything is refused). Same class of bug, opposite direction, and they are recorded together
deliberately — checking "can this gate fire?" and "can this gate open?" are two questions and
only asking one of them is how both happened.

### The ruling

`legacyFlags()` sets `smart_qr_enabled` when `plans.limits` **has the key**
`smart_qr_max_assigned`. A bridge, framed exactly like `white_label_enabled`:

- **DERIVED, not authoritative.** It stays until add-ons can grant the flag directly, and it is
  annotated at the code so nobody reads it as the source of truth.
- Correct rather than merely convenient: R-13 seeded that limit on all three tiers, so every
  current customer has the feature, and a future plan that omits the limit correctly omits it.
- `array_key_exists`, **not truthiness** — a limit of `0` means "bounded at zero", which is a
  granted feature the customer cannot use yet, not an absent one (BUG-030's pinned semantics).
- The resolver stays the single authority, so the partner ceiling still intersects it
  (CLAUDE.md rule 6).

**A customer without it: the module is HIDDEN ENTIRELY** — no nav group, and every route 403s.
Not present-and-empty: an empty Smart QR section shown to somebody who cannot have Smart QR is an
advert placed inside the product, and every other group in the client nav is either present or
absent. There is no disabled state to copy.

---

## R-23 — THREE customer pages, not §11's four. Settings is not built.

§11 names four pages: Overview, My QR Codes, Activity, **Settings** — and never says what is in
Settings. The only tenant-level Smart QR setting that could exist is a default message, and R-18
already ruled out the global tier, so the batch and the assignment cover it.

**Ruled: ship three.** An empty page built to match a heading is worse than an absent one — it
looks broken rather than unbuilt, and it invites somebody to fill it with settings nobody asked
for.

### ⚠️ Spec discrepancy #6

Added to the running list (see R-12's table): **§11 names a page with no defined content.**

The others, for continuity: the missing `failure_reason` field (slice 2); "tenant/customer" in a
codebase with both (R-1); `assigned_count` as a stored field (R-12); one §5 status list that is
really two (R-10); §6's "disconnected" channel state that does not exist (slice 3a).

---

## ⚠️ Slice 6 readings of §11 against a codebase it never saw

Three places where §11 asks for something this code cannot give, recorded so they are not
rediscovered as bugs.

**"Allowed customer actions must depend on permissions."** There is no client-side permission
system — `client_role` (`administrator` / `staff`) is the only granularity that exists. Ruled:
**reads for everyone, writes for administrators.** Inventing a client permission table for one
module would be a pattern with a single caller, which is the cost R-9 and R-15 already refuse.

**"QR preview" and "download digital copy."** Both need a rendered QR image, and
`endroid/qr-code` is R-6's slice-8 package — explicitly not to be installed before then. Shipped
**absent**, not as broken buttons: an action that does nothing is worse than one not offered,
because a customer cannot tell it from a fault. ⚠️ This is the **third** time §11's feature list
has assumed a later slice.

**"Change WhatsApp channel."** §11 lists it as an ordinary customer action; slice 3c had
deliberately excluded `channel_account_id` from the admin edit form because it needs cross-tenant
validation. Ruled: **build it, with `SmartQrAssignmentValidator` reused in full** — the channel
must belong to the customer's own workspace, checked server-side with the scope-bypass shape
slice 3a documented. A customer switching lines is a real need and the workaround (ask an admin)
is worse than the feature.

---

## ⚠️ OWED — `smart_qr_scans_per_month` was never built. A SLICE 4 GAP.

Found while planning slice 6, which depends on the counter tier existing.

R-5 named three entitlement keys and assigned each to a slice:

| Key | Kind | Enforced at | References in `app/` + `database/` |
|---|---|---|---|
| `smart_qr_max_assigned` | gauge | assignment (slice 3) | **8** — built, seeded, enforced |
| `smart_qr_scans_per_month` | counter | **scan (slice 4)** | **0** |
| `smart_qr_enabled` | boolean | display (slice 6) | built here, R-22 |

**The scan path records events and never touches a `UsageMeter`.** R-5's counter tier does not
exist: a customer on any plan can generate unlimited scans, and nothing measures or refuses them.

Recorded as OWED rather than a BUG because nothing is *wrong* — no incorrect value is produced
and no customer is mischarged. The feature was simply never written, and slice 4 shipped without
noticing because nothing referenced the key.

**Where it belongs:** the scan path already has the right shape for it —
`RecordQrScanJob` runs per scan and could increment a meter, and `QuotaGuard`/`UsageMeter` exist
from Phase 1. ⚠️ But it needs a ruling first: what happens when a workspace exceeds its scan
quota? Refusing the redirect punishes the *customer's customer*, who is standing in a shop — and
slice 4's whole failure rule is that the redirect must survive. Recording the overage and
billing or alerting on it is likely the right answer, and that is a decision, not code.

---

## ⚠️ OWED — vitest coverage for the three customer pages

**Consciously deferred in slice 6, not overlooked.** Recorded so it is findable.

Missing: component tests for `client/SmartQr/Overview.jsx`, `client/SmartQr/Codes.jsx` and
`client/SmartQr/Activity.jsx`.

### Why it was deferred

Slice 3b established the pattern — 24 vitest tests, and the runner was unblocked there — so the
machinery exists and this was a choice about spend, not an absence of tooling.

Slice 6's risks were **boundary and entitlement**, and those live in PHP: which tenant's codes a
customer can reach, whether a reassigned code leaves the previous tenant's pages, whether a
customer can point a QR at another tenant's channel, and whether the entitlement gate refuses.
All four were covered by stash-checked PHP tests, each verified to fail under its own mutation.
A React test could not have caught any of them.

### What it would cover, when written

The things PHP genuinely cannot see:

- ⚠️ **R-19's labels.** The PHP suite asserts the *prop names* (`attributed_messages`, and that
  `customers_messaged` is absent) — but nothing asserts the rendered **card text** says
  "Attributed Messages". R-19 is a constraint about what a customer READS, and the label is the
  part a well-meaning edit would change.
- The `canManage` split: the edit control absent for staff, present for administrators.
- The Activity feed's bot/unique/repeat badge mapping, which is the one place bots are shown
  rather than excluded.
- The empty states on all three pages.

### Priority

Low, and the label test is the one worth writing first — it is the only assertion standing
between R-19 and somebody "improving" a card title back to "Customers Messaged".
