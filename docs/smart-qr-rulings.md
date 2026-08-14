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
