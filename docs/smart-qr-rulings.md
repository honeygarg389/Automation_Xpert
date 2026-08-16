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

⚠️ **AMENDED 2026-08-16 — there are TWO tiers, not three. The counter was RULED OUT.**

| Key | Kind | Enforced at | Slice |
|---|---|---|---|
| `smart_qr_max_assigned` | **gauge** — `COUNT(*)` of current assignments | assignment | 3 |
| ~~`smart_qr_scans_per_month`~~ | ~~counter~~ | — | **REMOVED — see below** |
| `smart_qr_enabled` | **boolean** — feature flag | display | 6 (derived, R-22) |

The gauge slots into `GaugeSources` and inherits everything Phase 1 slice 4 built.

### ⚠️ THE COUNTER TIER WAS REMOVED AS A CONCEPT. OWNER RULING.

**There is no scan limit.** Not deferred, not owed, not "the missing third tier" — decided
against, and it will not be built. This is recorded in full because an absence otherwise reads
as an unfinished slice, and the next person to notice R-5 once listed three tiers would
reasonably try to complete it.

**1. Scan volume is not what is sold.** The product is the Business Kit — physical printed
stickers, sold as a kit. Its value is in how many CODES a customer holds, and
`smart_qr_max_assigned` already bounds that at 50 (R-13). Metering scans meters something the
customer was never sold.

**2. It would meter success and then punish it.** A shop whose QR is scanned heavily is the most
successful customer on the platform. Charging or refusing for that is backwards — the metric
that indicates the product working is the last thing to put a ceiling on.

**3. It would put a WRITE on the redirect path.** Enforcing a counter means incrementing a
`UsageMeter` on every scan, on the one path slice 4 deliberately kept to reads. R-21 already
records the single exception (the attribution session, which cannot be deferred) and its failure
rule exists precisely because that path must stay light and must never break.

**4. ⚠️ The person a refusal blocks is not the customer.** It is the CUSTOMER'S CUSTOMER —
standing in a shop, holding a phone, looking at a sticker — who would get an error page that
makes the tenant look broken. The tenant would never learn how many people walked away. Every
other gate in this module refuses an *admin* or a *tenant*, who can read the message and act on
it. This one would refuse a member of the public on their behalf.

Recorded as two rows plus a struck third rather than silently rewritten to two, so the history of
the decision survives.

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

## ✅ RESOLVED — `smart_qr_attribution_sessions.smart_qr_scan_event_id` was DROPPED

⚠️ **Closed in slice 7: the column was dropped**, in the same migration that added the aggregate
tables, exactly as recommended. Nothing read it, no §10 metric needed it, and a queue-based
back-fill could never have guaranteed non-null — a column that is *sometimes* populated invites a
query that silently omits rows.

Kept as a resolved entry rather than deleted, so the decision is findable. The original follows.

### Original entry — nullable, and nothing fills it

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

## ✅ RESOLVED (not built, by decision) — `smart_qr_scans_per_month`

⚠️ **Closed 2026-08-16 by owner ruling: there is no scan limit, and there will not be one.**
See the amendment to R-5 above for the full reasoning — the product sells CODES not scans,
metering scan volume punishes the most successful customers, enforcement would put a write on
the redirect path slice 4 kept read-only, and a refusal would land on the customer's customer
standing in a shop rather than on anyone who could act on it.

**Kept rather than deleted**, because a resolved decision is findable and a deleted entry looks
like something that was forgotten. Confirmed at closure: **zero references to the key anywhere**
in `app/`, `database/`, `tests/`, `resources/` or `config/` — nothing had to be removed, which is
also why slice 4 shipped without noticing it was missing.

The original entry follows, for the record.

### Original entry — `smart_qr_scans_per_month` was never built. A SLICE 4 GAP.

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

---

## ⚠️ HAZARD H-4 — the aggregator/prune interlock

**Two individually correct jobs that together erase the history the prune exists to preserve.**
Recorded as a named hazard rather than a docblock line because both halves look right in
isolation and the damage is silent.

### The shape

1. `smartqr:prune-scans` deletes raw scan rows older than the retention window. Safe alone — the
   aggregates hold the summary.
2. `smartqr:aggregate` recomputes a day from raw rows and `updateOrCreate`s the result. Safe
   alone — idempotent, and it corrects late-arriving scans.
3. Run (2) on a day (1) has already cleared, and it computes **zero** and overwrites a correct
   historical aggregate — **the only surviving copy of that day** — with zeros.

Nothing throws. Nothing logs. The number simply becomes wrong.

### The interlock

`SmartQrAggregator::aggregate()` **refuses any date older than the retention window**, and there
is deliberately **no `--force`**: a caller who wants an old day needs the raw rows back, which
the prune has already made impossible. `PruneSmartQrScansCommand` GUARD 2 is the mirror — it
refuses any day with no aggregate row.

Both commands read `smartqr.scan_retention_days`, and the scheduler runs the aggregator **before**
the prune. Reversing that order would make the prune refuse every night and quietly never run.

### ⚠️ The first version of the guarding test could not fail. Measured.

The fixture pruned the scans and left the day with **no conversions**, so the aggregator found no
activity, wrote nothing, and the existing row survived — removing the refusal left the test
green.

**Conversion events are never pruned.** That is what makes the hazard real: a genuinely pruned
day still has conversion rows, so the assignment *is* still in the aggregator's id set and its
scan figures *are* recomputed to zero. The corrected fixture keeps the conversions.

The discriminator is **the existing row unchanged**, not that an exception was raised — an
implementation that throws *after* writing zeros passes an exception-only assertion.

---

## R-24 — the Scan-to-Message Rate follows §12, and slice 6's number CHANGED

§12 defines it exactly: **Unique Customers Messaged ÷ Unique Valid Scans × 100.**

Slice 6 shipped `attributed_messages ÷ all non-bot scans` — a different numerator *and* a
different denominator. That was wrong against an explicit spec definition, and it is corrected
here rather than left to look like drift later.

| | Numerator | Denominator |
|---|---|---|
| slice 6 (wrong) | all attributed messages | all non-bot scans |
| **slice 7 (§12)** | **distinct contacts** | **unique valid scans** |

`attributed_unique_contacts` is the piece slice 6 could not compute; the aggregates add it.
⚠️ Counting messages rather than people lets one talkative customer push a conversion rate above
100%, which is how the error would eventually have been noticed — by a number that is obviously
absurd, long after it had been quoted.

**Still `null` on zero, never `0`.** §12 asks only that divide-by-zero be prevented; it does not
ask for a misleading zero. "0%" reads as *nobody responded*; `null` reads as *nothing has
happened yet*, which is the truth for a QR nobody has scanned.

⚠️ **One documented approximation:** summing daily distinct-contact counts over-counts a customer
who messaged on two different days. The alternative is storing every contact id per day — a
second scan-sized table. Recorded at the code rather than left silently approximate.

---

## R-25 — the conversion funnel has THREE stages, not §12's four

§12's funnel is Scan → WhatsApp Redirect → Customer Messaged → New Contact.

**Ruled: three stages** — Valid Scans → Attributed Messages → New Contacts.

### Why, and what would make the fourth real

`PublicQrController::recordScan()` is called **only inside the REDIRECT branch**. Every refusal
returns before it. So **scans and redirects are the same number, always, by construction** — a
funnel drawn to §12 would show two provably identical bars, which reads as a rendering bug that
happens to be correct. Nobody believes a funnel with a 100% step.

⚠️ **If a future slice ever records scans on refusals** — an unassigned or expired code that was
scanned is genuinely interesting — then Scan and Redirect become different numbers and the fourth
stage becomes real. This note is what tells that person it can be added, rather than leaving them
to wonder why the spec says four and the code draws three.

---

## R-26 — export covers AGGREGATES only

§12 asks for export without saying what is exported.

**Ruled: the aggregates, and the button says so.**

A raw-scan export silently stops at the 90-day retention boundary. A customer who downloads
"their history" and finds it truncated has no way to know why — the file looks complete and is
not. The aggregates go back indefinitely (R-4 amendment), so an aggregate export is the only one
that can honestly be called a history.

Raw export is **out of scope until someone asks for it**, and when they do it needs the retention
boundary surfaced *in the file itself*, not in the UI that generated it.

---

## ⚠️ Spec discrepancy #7 — §12 contradicts itself on KPI cards

§12 says, in the same section: *"Do not duplicate the same content in both Overview and Reports"*
— then lists **"basic KPI cards"** under Smart QR Overview and **"KPI cards"** as required visual
A under Smart QR Reports.

**The reading, ruled in slice 7b:** Overview (slice 6) is the OPERATIONAL snapshot — what is
assigned right now, how many are active, recent activity. Reports is the same measures over
TIME, filtered, compared and exportable. Same metrics, genuinely different content, and neither
is a copy of the other.

The instruction as written cannot be followed literally, which is why it is recorded here rather
than resolved silently in a controller.

**The running list** (see R-12 and R-23 for the earlier entries): missing `failure_reason`;
"tenant/customer" in a codebase with both; `assigned_count` as a stored field; one §5 status list
that is really two; §6's non-existent "disconnected" channel state; §11's Settings page with no
defined content; and now §12's KPI cards in both places.

---

## ✅ RESOLVED — vitest coverage for R-19's labels

⚠️ **Closed in slice 7b.** The OWED entry said the label test was the one worth writing first,
and slice 7b is where those labels are written, so it was written here rather than deferred
again.

`resources/js/__tests__/smartqr-labels.test.jsx` asserts the **rendered text**, not the props:

- every attributed card says "Attributed …"
- the page never contains `/customers?\s+messaged/i` **anywhere**
- the under-count note appears in plain words on the page, not only in a tooltip
- a null rate renders an em dash, never "0%"

⚠️ **`t()` is mocked to return the real English strings**, deliberately: asserting a translation
KEY would pass while the English label said something else, which is exactly the failure R-19
guards against.

**Mutation-verified:** renaming the label back to "Customers Messaged" fails two of the four
tests. Before this, the PHP suite asserted only the prop names — a well-meaning edit to the card
title would have shipped with a green suite.

✅ **CLOSED 2026-08-16 — written, passing, AND mutation-checked.** All four assertions were
mutated and each failed exactly the test it should: the preview keyed by `id` instead of
`serial`, the download's `?format=` dropped, the `canManage` guard forced true, and the
bot/unique precedence flipped. Four mutations, four single-test failures, restore confirmed
clean after each. The blocker was a toolchain fault, diagnosed and fixed the same day — see
"the vitest and vite hang" below.

The superseded record read:

> ⚠️ **Slice 8b — WRITTEN AND PASSING, NOT MUTATION-CHECKED.**
`resources/js/__tests__/smartqr-customer-pages.test.jsx` covers the two remaining pages,
7 tests, verified passing (7/7, 1.33 s). The vitest runner then degraded and **no mutation
check was run against any of them**, so they are not verified to fail. The entry is therefore
**not closed** — see the slice 8b runner note below, which names re-running the check as the
first action next session. Deliberately only what PHP cannot see:

| Assertion | The failure it catches |
|---|---|
| the preview `<img>` src is the preview ROUTE, keyed by **serial** | a wrong route key renders a broken-image icon in a cell nobody looks at twice, while PHP shows a green route test for an endpoint the page never calls |
| the download menu offers svg/png/pdf and emits `?format=` | `?format=` is a string contract between JSX and a `match` in the controller, and nothing else asserted the two agree |
| the edit control is absent for staff, **present for administrators** | the positive control — without it, a page rendering no rows at all passes the negative |
| Activity labels bot / unique / repeat distinctly, in ONE render | a mapping collapsed to a single branch would have to produce three different labels by accident |

⚠️ **Bot precedence is the substance of that last one.** A bot's first visit is unique by the
fingerprint, so `is_bot` and `is_unique` co-occur constantly; the row asserts a scan with BOTH
flags reads "Bot" and not "Unique". This feed is the one place in the module where a bot is
shown rather than excluded — every aggregate filters them — so a bot labelled "Unique" would be
counted by a human reading the feed and by no number on any other screen.

⚠️ **The runner degraded mid-session and the mutation checks for this file were NOT run.** See
the slice 8b note below. The file is verified passing (7/7, 1.33s); its assertions are not
verified to fail.

---

## ⚠️ Slice 8 — two library behaviours found by measurement, not by reading

Both were silent, both would have reached print, and neither is in any documentation.

### 1. `SvgWriter` accepts a label and discards it

Same builder, same `labelText`:

| Writer | Output | Serial |
|---|---|---|
| `PngWriter` | 1056 × 1094 | ✅ band rendered |
| `SvgWriter` | 1056 × 1056 | ❌ **silently absent** |

`SvgWriter::write()` takes a `LabelInterface $label` parameter and never uses it. Nothing errors.

⚠️ **SVG is the ZIP default (ruled, for size) and the ZIP goes to a printer** — so this would
have produced 500 stickers with no human-readable serial. The serial is how an operator matches a
sticker to a row, and slice 4's whole enumeration argument depends on the serial being printed
while the token is not. The band is appended in `appendSerialToSvg()`.

### 2. Dompdf ignores INLINE `<svg>` entirely

| Embed | Result |
|---|---|
| inline `<svg>` | **1,140 bytes — an empty page** |
| `<img src="data:image/svg+xml;base64,…">` | ~5,300 bytes, rendered |
| blank-SVG control | 1,141 bytes |

The inline case produces a valid, openable, **blank** PDF. `the_module_matrix_survives_the_pdf_conversion`
pins it, and its discriminator is that **two different codes must produce different PDFs** — if
the matrix were dropped, both would be the same empty frame.

---

## ⚠️ Slice 8 measurement — what the structural checks actually guard

`structuralChecks()` first returned `LOGO_RATIO < 0.30` and similar. PHPStan flagged them as
"always true", and it was right: **comparisons between two constants assert nothing at runtime.**

What guards the configuration is the **test asserting the constants directly** —
mutation-verified, raising `LOGO_RATIO` to 0.45 fails it. The method now reports the values
rather than self-evident booleans.

⚠️ And the claim stays deliberately narrow. §14 says "validate scan readability"; this asserts
that Level H is set, the logo is inside Level H's ~30% tolerance, and a quiet zone exists. It
does **not** decode anything — that needs a QR *reader*, bacon is an encoder only, and avoiding a
second library was R-6's entire argument. A well-formed code is a weaker claim than a scanning
one, and the weaker claim is the true one.

---

## ⚠️ Slice 8 measurement — a "half-built archive" needs at least one entry

The export's failure-cleanup test passed under mutation twice before it discriminated, and both
reasons are worth keeping:

1. **The destination cleanup is unreachable.** The archive is built at a temp path and moved to
   the disk only on success, so nothing partial ever lands at the destination. That branch is
   defence in depth, not the working guard.
2. **`ZipArchive::close()` DELETES an archive with zero entries.** The first fixture threw on the
   very first render, so there were no entries, the archive removed itself, and nothing leaked —
   the test could not fail.

A genuine half-built archive needs **one success then a failure**, which is also the only case
that matters in production. The real leak is the **temp file**, which on a busy queue accumulates
silently until the volume fills.


---

## ⚠️ Slice 8b — the ruled size note was BACKWARDS, and measurement found it

**The ruling:** "put the size difference in the UI where the choice is made — an admin picking
PNG must see 50–150 MB before they wait for it."

**The premise was wrong**, and building the note honestly required measuring rather than
transcribing. Measured in a real `ZipArchive`, because that is what the admin downloads:

|          | SVG/code | PNG/code | 500 SVG | 500 PNG |
|----------|----------|----------|---------|---------|
| no logo  |   4.5 KB |   6.8 KB |  2.1 MB |  3.2 MB |
| logo     |  491  KB |  154  KB |  234 MB |   73 MB |

⚠️ **With a logo configured — the intended production state — SVG is roughly 3× LARGER than
PNG.** Without a logo the two are within 2 KB of each other and both trivial. The "50–150 MB"
figure is real, but it describes **PNG-with-logo**, and it is the *smaller* of the two options
in that configuration.

**Cause, and it is structural rather than incidental:** endroid's `SvgWriter` embeds the logo as
a base64 data URI in **every file**. Base64 of an already-compressed PNG neither shrinks in the
SVG nor deflates in the ZIP. The `PngWriter` rasterises the same logo into one bitmap compressed
once. So the SVG penalty is paid 500 times and the PNG penalty once.

**SVG remains the default.** The reason that survives measurement is the one that was never
stated: it is vector and prints crisply at any physical size, where a 1024 px PNG goes soft on
anything larger than a sticker. The size argument was a wrong number that happened to point at
the right default — which is the most dangerous kind, because the outcome looked like
confirmation.

**Where it lives now:** `SmartQrImageRenderer::ZIPPED_BYTES_PER_CODE`, computed server-side and
passed to the page as `exportBytesPerCode`. It must be server-side: the two branches differ by
~100× on whether a logo is configured, and React cannot know that. Mutation-verified in both
directions — collapsing the logo branch to the no-logo figures fails the test, and removing the
prop fails the page assertion.

⚠️ **Re-measure if the logo pipeline changes.** These are constants standing in for a
measurement, and a constant cannot notice that its measurement went stale.

---

## ⚠️ Slice 8b — the vitest runner degraded mid-session

Recorded because it changes what the JS tests currently prove, and because I nearly
misdiagnosed it a second time.

**Sequence:**

1. `smartqr-customer-pages.test.jsx` ran green — 7 passed, 1.33 s.
2. `smartqr-labels.test.jsx` (7b's file) ran green — 4 passed, 1.72 s.
3. A full-directory run hung with **zero bytes of output**, before the `RUN v4.1.5` banner.
4. From that point **every** invocation hung, including the two files that had just passed and
   `confirm-destructive.test.jsx`, which had also passed minutes earlier.

**Ruled out:** stale/orphaned processes (none present — `ps` clean), the thread pool
(`--pool=forks` hangs identically), file parallelism (`--no-file-parallelism` hangs), and the
transform cache (`node_modules/.vite` cleared, still hangs). It is not the new file: that file
passed, and files unrelated to this slice now fail the same way.

⚠️ **What this costs:** the 7 new assertions are verified **passing** but not verified
**failing**. No mutation check was run against them. By the standing rule — *a mutation that
cannot be shown to have landed proves nothing* — they are unproven, and the honest statement is
that they have not yet been shown to discriminate.

**First action next session:** re-run vitest in a fresh shell. If it is green, mutation-check
the four assertions in the table above — in particular the bot/unique precedence, which is the
one whose failure mode is a plausible-looking wrong label.

⚠️ **The earlier mistake this nearly repeated:** in slice 3b I reported the toolchain broken
when a `--reporter=basic` flag simply did not exist in vitest 4, and the run had actually
succeeded. The discipline that worked here was running a **known-good control file** before
concluding anything — which is what showed the first hang was a process collision and the second
was real.


---

## ✅ RESOLVED — the vitest and vite hang were ONE fault: a quarantined native addon

**Diagnosed 2026-08-16 after it escalated from "vitest hangs" to "the owner cannot see the UI",
because the vite build hung too.** Same root cause, and the slice 8b note that ruled out stale
processes, both pools, file parallelism and the transform cache was correct to rule them out —
it just never reached the real one.

### What it actually was

`node_modules` on this machine was unpacked from an archive by **"RAR Extractor - Unarchiver"**.
That tool did two things:

1. **Flattened every symlink into a text file.** `node_modules/.bin/vite` is 19 bytes containing
   the literal string `../vite/bin/vite.js`, and **0 of 40** `.bin` entries are executable. This
   is the half already recorded — it is why `npm run build` fails and why the node invocation
   was adopted as the workaround.
2. **Stamped `com.apple.quarantine` on 60,228 files** — including
   `node_modules/fsevents/fsevents.node`, a **native addon**.

`dlopen()` of a quarantined native library sends macOS to assess it. That assessment blocked, and
the block is **permanent and keyed to the path**.

### The measurements that pinned it, in order

| Test | Result |
|---|---|
| `import('vite')` with no config, no plugins | HANG |
| `import('react')`, `rollup`, `esbuild`, `postcss`, `tailwindcss` | all fine |
| bisect vite's graph → the 1.5 MB chunk → its imports | every leaf fine, the chunk hangs |
| `require('fsevents')` alone | HANG |
| raw `process.dlopen()` of the addon | HANG — below JS entirely |
| the **same bytes** unpacked fresh from npm, in `/private/tmp` | **loads instantly** |
| `probe.node` — same bytes, **same directory** | **loads instantly** |
| `fsevents.node` — same bytes, same directory | HANG |

⚠️ **Identical SHA, same directory, one filename loads and the other does not.** The poisoned
state belongs to the *path*, not the file.

### Three fixes that did NOT work, and they matter

- **Removing the quarantine xattr** — still hung.
- **Re-signing ad-hoc** (`codesign --force --sign -`) — signature valid, still hung.
- **Replacing the file with a new inode** (`rm` + `cp`, verified inode changed) — still hung.

The state outlives the attribute, the signature and the inode. Nothing recoverable at the file
level clears it.

⚠️ **And `kill -9` does not reap a process blocked in `dlopen`.** Thirteen unkillable node
processes had accumulated, the oldest being the owner's build. This is why slice 8b saw runs that
had *just passed* start hanging: the first blocked load poisoned the path, and everything after
queued behind it forever. **A process that survives `kill -9` is not a stale process — it is a
symptom, and slice 8b misread it as the former.**

### The fix

`fsevents` is an **optionalDependency**. Moving `node_modules/fsevents` aside makes
`require('fsevents')` fail fast with MODULE_NOT_FOUND, which chokidar catches and falls back to
its portable watcher. Measured after: **build 11.14 s**, **vitest 35/35 in 2.79 s**.

The cost is that file-watching in `vite dev` falls back to polling — slower on a large tree,
functionally identical. For **build** and **vitest**, which is all this project uses, there is no
cost at all.

⚠️ **The permanent fix is `rm -rf node_modules && npm ci`**, which restores the symlinks *and*
avoids quarantine entirely, because npm writes the files itself rather than extracting them.
That was not done here because the workaround unblocks the owner immediately and a reinstall is
the owner's call on a 96%-full disk. **Do not re-extract `node_modules` from an archive** — that
is the actual mistake, and it will reproduce all of this.

### What is still not known

**Why it worked in slice 3b and 8b and then stopped.** The addon has been quarantined since
2026-07-12 and node was installed 2026-07-17, so the ingredients predate the failure by a month.
Security assessments are cached, so the likeliest story is that something invalidated the cache
and the next `dlopen` was the one that blocked — but I could not pin the invalidating event, and
I am not going to invent one.
