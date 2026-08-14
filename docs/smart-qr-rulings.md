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

### ⚠️ This is the THIRD time the spec's field lists have been wrong against its own requirements

Recorded together so the pattern is visible rather than rediscovered a fourth time:

| # | What the spec did | Found in |
|---|---|---|
| 1 | asks for "record failure reason if a batch generation partially fails", omits `failure_reason` from the §4 field list | slice 2 |
| 2 | assigns to "tenant/customer" in a codebase with both a client and a workspace | R-1 |
| 3 | lists `assigned_count` as a stored batch field where a derived count is correct | slice 3 |

Plus the §13-vs-§25 self-contradiction (R-2) and the single status list that is really two
(R-10). **The spec is a requirements document, not a schema.** Its field lists are read as
intent, and checked against its own requirements before they are built.

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
