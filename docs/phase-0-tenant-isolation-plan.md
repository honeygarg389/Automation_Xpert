# Phase 0 — Tenant Isolation Enforcement & Partner Tier: Investigation & Plan

**Status:** Awaiting approval. **No code written.**
**Date:** 2026-08-03
**Scope:** Promote workspace isolation from convention to enforced global scope; add the Partner tier additively.

---

## 0. Naming, and contradictions between the brief and the code

### 0.0 Naming — resolved

**WhatsMine is the codebase; AutomationXpert is the product brand built on it. They are the same application.**

Any `WhatsMine` reference in code, config, or vendor documentation should be read as AutomationXpert. **Nothing is renamed** in this or any later phase — not the composer package (`whatsmine/whatsmine`), not namespaces (`App\…`), not class names, not table names.

This plan therefore uses:
- **AutomationXpert** when referring to the product, the hierarchy, and business concepts
- **WhatsMine** only when quoting an actual identifier that exists in the code

No work item in §E involves renaming anything.

### 0.1 Remaining contradictions

Per your constraint, the code wins.

| # | Brief says | Code says | Impact |
|---|---|---|---|
| 0.1 | "Read CLAUDE.md first" | **No `CLAUDE.md` exists** anywhere in the repo. Nor `AGENTS.md`, `README.md`, `CONTRIBUTING.md`. | **Still open.** I had no project conventions to follow, so §B and §E reflect my judgement rather than house style. If the file exists elsewhere, point me at it and I will revise. |
| 0.2 | Project is "AutomationXpert" | `composer.json` → `whatsmine/whatsmine` | **Resolved — see §0.0.** Brand vs package name; same application; no rename. |
| 0.3 | "isolation … enforced by hand-written `where('workspace_id', …)` filters in controllers" | Accurate — **265 call sites across 73 files** — but the resolution expression feeding them is **broken**. See §G-1. | This changes the shape of Phase 0. The scope cannot simply adopt the current expression. |

---

## A. Model classification

**74 models total.** Built by loading each class and reading its actual table via `Schema::getColumnListing()`, not by inferring from names.

### A.1 Workspace-owned — `workspace_id` present (27)

All get `BelongsToWorkspace`. Route key noted because it affects binding (§B.6).

| Model | Table | Route key |
|---|---|---|
| AiChatbot | ai_chatbots | uuid |
| AiKnowledgeBase | ai_knowledge_bases | uuid |
| AiProviderConfig | ai_provider_configs | id |
| Automation | automations | uuid |
| Campaign | campaigns | uuid |
| CannedReply | inbox_canned_replies | id |
| ChannelAccount | channel_accounts | id |
| Contact | contacts | uuid |
| ContactTag | contact_tags | id |
| Conversation | conversations | uuid |
| EcommerceCart | ecommerce_carts | id |
| EcommerceOrder | ecommerce_orders | id |
| EcommerceProduct | ecommerce_products | id |
| EcommerceStore | ecommerce_stores | uuid |
| InboxLabel | inbox_labels | id |
| Lead | leads | id |
| LeadScrapeJob | lead_scrape_jobs | id |
| Segment | segments | id |
| SmsProviderConfig | sms_provider_configs | id |
| Social\SocialAccount | social_media_accounts | id |
| SocialPost | social_media_posts | id |
| UsageMeter | usage_meters | id |
| WhatsappAutoReply | whatsapp_auto_replies | id |
| WhatsappBusinessAccount | whatsapp_business_accounts | id |
| WhatsappTemplate | whatsapp_templates | id |
| WhatsappWidget | whatsapp_widgets | id |
| WorkspaceSmtpConfig | workspace_smtp_configs | id |

### A.2 Workspace-owned in practice — **no `workspace_id` column** (9)

These are the ones that make this phase non-trivial. Each is isolated **only transitively** through a parent. A global scope cannot filter them without either a denormalized column or a relationship-based scope.

| Model | Table | Parent chain | Current isolation |
|---|---|---|---|
| **Message** | messages | conversation_id → conversations.workspace_id | transitive only |
| CampaignRecipient | campaign_recipients | campaign_id → campaigns.workspace_id | transitive only |
| AiKbDocument | ai_kb_documents | kb_id → ai_knowledge_bases.workspace_id | transitive only (route key = **uuid**) |
| AiKbChunk | ai_kb_chunks | → ai_kb_documents → kb | two hops |
| AiRun | ai_runs | (needs confirmation) | transitive |
| AutomationRun | automation_runs | automation_id → automations.workspace_id | transitive only |
| AutomationRunLog | automation_run_logs | → automation_runs → automations | two hops |
| SocialPostAccount | social_media_post_accounts | → social_media_posts.workspace_id | transitive only |
| WhatsappPhoneNumber | whatsapp_phone_numbers | waba_id_fk → whatsapp_business_accounts.workspace_id | transitive only |
| InternalNote | internal_notes | conversation_id → conversations.workspace_id | transitive (+ user_id) |

**`messages` is the highest-value table in the product and has no direct tenant column.** A missed join is a cross-tenant message leak. I recommend denormalizing `workspace_id` onto `messages` and `campaign_recipients` in this phase; the rest can use a relationship-based scope. See §C.4.

### A.3 Client-owned (5)

| Model | Table | Note |
|---|---|---|
| Workspace | workspaces | `client_id` — **no FK constraint** |
| ClientSetting | client_settings | FK ✅ |
| ClientSubscription | client_subscriptions | FK ✅ |
| Invitation | invitations | FK ✅ |
| AuditLog | audit_logs | client_id + user_id, **no FK** |

### A.4 User-owned (10)

Scoped by `user_id`, not workspace. **These are not candidates for `BelongsToWorkspace`** without a deliberate model change.

NotificationPreference · OnboardingStep · PaymentTransaction · PushSubscription · Models\SocialAccount · Subscription · SupportReply · SupportTicket · **WebhookEndpoint** · Media (polymorphic `mediable_type`/`mediable_id`)

### A.5 Platform-global (15) — no scope

AdminUser · BillingEvent · Client · CmsPage · ContactMessage · Coupon · Currency · Locale · PaymentGatewayConfig · Permission · Plan · Role · SystemSetting · TaxRate · Translation · WebhookDelivery (child of WebhookEndpoint) · MagicLink

### A.6 Ambiguous — **not guessed, need your ruling** (7)

| Model | Why ambiguous | Options |
|---|---|---|
| **WebhookEndpoint** | Scoped by `user_id`, but outbound webhooks are conceptually a workspace integration. If user A leaves, their endpoints orphan. | (a) leave user-scoped, (b) add `workspace_id` |
| **Media** | Polymorphic (`mediable_type`/`mediable_id`), no tenant column. A workspace's uploads are only reachable via the owning model. | (a) relationship scope, (b) denormalize `workspace_id` |
| **Template** | `id, name, slug, subject, type, content, meta, enabled` — no owner column at all. Looks platform-global (system email templates), but "Template" is overloaded in this codebase. | Confirm: platform-global, or intended to become workspace-owned? |
| **IntegrationConfig** | Has `updated_by_admin_id` — **admin-managed, platform-global**. But `Modules/Integrations` is consumed per-workspace via `CredentialResolver::system()`. | Likely platform-global; confirm no per-workspace override is planned |
| **IntegrationAuditLog** | `admin_user_id` — platform-global audit trail | Likely platform-global |
| **SmtpConfiguration** vs **WorkspaceSmtpConfig** | Two SMTP models. The latter is workspace-scoped; the former has no tenant column. | Confirm the former is the platform fallback |
| **Subscription** (user_id) vs **ClientSubscription** (client_id) | Two subscription models with different owners. | Which is authoritative for billing? Affects §C.4 denormalization |

**Naming collision to flag:** `App\Models\SocialAccount` (table `social_accounts`, OAuth login) and `App\Modules\Social\Models\SocialAccount` (table `social_media_accounts`, publishing) are different models with the same class basename. Any `use SocialAccount` is ambiguous at a glance. Not in scope to fix, but the scope trait will be applied to only one of them and that must be reviewed carefully.

---

## B. `BelongsToWorkspace` design

### B.1 The blocking problem: there is no single source of truth today

This is the finding that reshapes Phase 0. **`users.current_workspace_id` does not exist** — not a column, not an accessor, not an attribute cast. Verified against `information_schema` and `app/Models/User.php`.

Yet **~90 call sites** read it:

```php
$workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
```

Every one silently resolves to `$user->workspace_id` — the user's **home** workspace.

Meanwhile the switcher writes to the **session**:

```php
// WorkspaceController.php:43 and :70
$request->session()->put('current_workspace_id', $workspace->id);

// HandleInertiaRequests.php:256 — the only reader of the session value
$workspaceId = $request->session()->get('current_workspace_id') ?? $user->workspace_id;
```

**Consequence:** the UI header shows the switched workspace; every controller operates on the home workspace. See §G-1 — this must be resolved *before* the scope is written, or the scope will encode the bug.

### B.2 Proposed resolution order

A single `WorkspaceContext` service (`app/Support/WorkspaceContext.php`), the sole authority:

1. **Explicit override** — set by jobs/console via `WorkspaceContext::for($id, fn () => …)`
2. **Session** `current_workspace_id`, *validated* against `$user->accessibleWorkspaces()` (never trust the session alone)
3. **Fallback** `$user->workspace_id`
4. **None** → see B.3

I deliberately do **not** propose adding a `users.current_workspace_id` column in this phase: the session is already the de-facto switcher store, and adding a column would be a second source of truth. Say the word if you'd prefer the column instead — it is a legitimate alternative, but it should be one or the other.

### B.3 Behaviour when there is no context — fail loudly

| Situation | Behaviour |
|---|---|
| HTTP request, authenticated client user | Scope applies |
| HTTP request, admin guard | Scope **not** applied (admin is cross-tenant by design); requires explicit opt-out marker |
| Queue job / console / webhook, no context set | **Throw `MissingWorkspaceContextException`** |
| Explicitly bypassed | Allowed, audited (B.4) |

Throwing is the deliberate choice. Returning unscoped results leaks; returning empty results silently corrupts campaigns and automations. A failed job is loud, retryable, and visible.

### B.4 Sanctioned escape hatch — greppable and auditable

```php
Contact::withoutWorkspaceScope('reason: admin cross-tenant search')->get();
```

- The reason string is **required** (no zero-argument form)
- Logs to the `json` channel with caller file/line at debug level
- Greppable: `grep -rn "withoutWorkspaceScope" app/` yields a complete bypass inventory
- A CI grep can fail the build if the count exceeds an agreed baseline

I chose a required-argument method over Laravel's native `withoutGlobalScope(WorkspaceScope::class)` precisely so bypasses cannot be written casually or found only by remembering the class name. Both will work — the native form cannot be blocked — so the CI grep should cover both spellings.

### B.5 Automatic assignment on create

`creating` hook: if `workspace_id` is empty and context exists, populate it. If context is missing **and** the attribute is unset, throw rather than write a NULL. Explicitly-set values are never overwritten (jobs and seeders must be able to set it directly).

### B.6 Route-model binding on uuid keys

7 workspace-owned models bind by `uuid` (Contact, Conversation, Campaign, Automation, AiChatbot, AiKnowledgeBase, EcommerceStore), plus AiKbDocument in A.2.

Global scopes **do** apply to implicit route-model binding, so `/app/contacts/{contact}` with a foreign uuid becomes a **404 instead of the current 403**. That is better security (no existence oracle) but it **will change existing test expectations** — `MultiTenantScopingTest::workspace_a_cannot_delete_workspace_b_contact` currently asserts **403**. See §F and §G-4.

---

## C. Partner hierarchy migration — additive only

### C.1 `partners` table (new)

Minimum viable:

| Column | Type | Note |
|---|---|---|
| id | bigint unsigned PK | |
| uuid | char(36) unique | consistency with other tenant roots |
| name | varchar(255) | |
| slug | varchar(255) unique | URL/reference key |
| status | varchar(32) default 'active' | mirrors `clients.status` convention |
| owner_email | varchar(255) | |
| owner_name | varchar(255) nullable | |
| owner_phone | varchar(64) nullable | |
| brand_name | varchar(255) nullable | |
| logo_path / logo_disk | varchar(255) nullable | mirrors `clients` exactly |
| primary_color | varchar(7) nullable | mirrors `clients` |
| support_email | varchar(255) nullable | |
| created_at / updated_at | timestamps | |

**Deliberately deferred** (your later phases): custom domains and DNS/TLS; partner-specific plans and pricing; entitlements/feature flags; revenue share and payout; partner admin users and auth; email-sender identity; per-partner gateway credentials.

### C.2 `clients.partner_id`

```
partner_id  bigint unsigned  NULL  INDEX  FK → partners.id  ON DELETE RESTRICT
```

NULL = platform-owned. `RESTRICT` not `CASCADE` — deleting a partner must never cascade-delete their customers' data.

**Consistency note:** `workspaces.client_id`, `users.client_id`, and `audit_logs.client_id` currently have **no FK constraints** (only `client_settings`, `client_subscriptions`, `invitations` do). Adding a proper FK for `partner_id` is correct, and I flag the inconsistency without proposing to fix it here (out of scope).

### C.3 Backfill

**Confirmed trivial.** Zero partners exist; `partner_id` is nullable and defaults to NULL, so every existing client is platform-owned with no data migration. No backfill script needed. Existing direct customers are untouched — this satisfies your constraint literally.

### C.4 Denormalizing `partner_id` — justified individually

I am proposing **zero** `partner_id` denormalization in Phase 0. Every case I considered is speculative until partner-facing reporting exists:

| Candidate | Verdict | Reason |
|---|---|---|
| `client_subscriptions` | **Defer** | Only 14 rows today; join to `clients` is trivially fast |
| `usage_meters` | **Defer** | Workspace-scoped; partner rollup needs 2 joins, unmeasured cost |
| `payment_transactions` | **Defer** | Volume unknown; add when a partner revenue report exists |
| daily stats tables | **N/A** | No such table exists in this schema |

I would rather add these with a measured slow query than guess. **Two `workspace_id` denormalizations I do recommend now**, on isolation rather than performance grounds — `messages` and `campaign_recipients` (§A.2), because those are the two highest-volume child tables and transitive-only isolation on `messages` is the single largest leak surface in the product.

### C.5 Rollback

Each migration has a real `down()`: drop FK → drop index → drop column → drop table. Since nothing reads `partner_id` until Phase 1, rollback is safe at any point in Phase 0. The `workspace_id` backfills on `messages`/`campaign_recipients` are additive columns; `down()` drops them.

---

## D. Partner scoping design

`partner_id` lives on `clients`, so partner scoping is one hop above workspace scoping:

```sql
-- Partner-scoped workspace-owned resource
SELECT c.* FROM contacts c
JOIN workspaces w ON w.id = c.workspace_id
JOIN clients cl   ON cl.id = w.client_id
WHERE cl.partner_id = ?
```

Implemented as `whereHas('workspace.client', fn ($q) => $q->where('partner_id', $id))`, or `whereIn('workspace_id', <cached partner workspace ids>)` for hot paths.

**Indexes required:**

| Index | Purpose |
|---|---|
| `clients (partner_id)` | the scope predicate |
| `workspaces (client_id)` | **verify — may not exist**; `client_id` has no FK so may have no index |
| `contacts (workspace_id)` etc. | already present on all 29 tenant tables ✅ |

**Platform-owned invisibility:** `partner_id = ?` never matches NULL in SQL, so platform-owned clients are automatically invisible to any partner. This is a property of the predicate, not something to implement — but it must be tested explicitly (§F).

---

## E. Migration order and file-by-file change list

Grouped so each commit leaves the suite green.

### Commit 1 — Fix the workspace-resolution bug (prerequisite)

**Split into four, sequenced 1a → 1a-bis → 1b → 1c. Do not chain; report after each.**

| Commit | Contains | Behaviour change |
|---|---|---|
| **1a** | `WorkspaceContext` + the two-workspace test fixture + characterisation tests. Wired into nothing. | **None** |
| **1a-bis** | `WorkspaceController` validates membership before writing the session; `accessibleWorkspaces()` filters by `client_id` (the mandatory G-1d mitigation) | Yes — and it touches broadcast channel authorization, so it gets its own diff and its own report |
| **1b** | The 5 infrastructure call sites; ships the G-2 rate-limit and G-3 plan-limit fixes | Yes |
| **1c** | ~85 controller call sites, module by module; re-verify every G-1b authorization site | Yes |

`1a-bis` is deliberately separate from `1b`: session validation and the
`accessibleWorkspaces()` filter are security changes and must not be buried in a commit that
is also fixing rate limits.

`MissingWorkspaceContextException` is deferred to whichever commit first throws it.

> **✅ LANDED 2026-08-07** on `fix/workspace-export-wrong-workspace`, in
> `app/Exceptions/MissingWorkspaceContextException.php`. The commit that first needed it was
> the BUG-008 fix: `GenerateWorkspaceExportJob` had been producing the wrong workspace's GDPR
> export because it fell back to the user's home workspace when it could not resolve one. It
> now throws instead. §B.3's reasoning held exactly — a failed job is loud, retryable and
> visible, whereas silently wrong data reached a signed download URL and, potentially, a
> regulator. See BUG-008 in `docs/found-bugs.md`.

**The characterisation tests flip in 1c, not 1b.** The three tests added in 1a
(`characterisation_switching_workspace_does_not_affect_controllers_today`,
`characterisation_the_broken_expression_always_yields_the_home_workspace`,
`workspace_context_and_the_legacy_expression_currently_disagree`) describe controller
behaviour and the legacy expression. 1b wired infrastructure only, so all three still passed
and were deliberately NOT inverted — inverting them there would have meant editing passing
tests to match a prediction the code did not satisfy.

**Inverting them IS the definition of done for 1c.** When the controllers resolve through
`WorkspaceContext`, switching workspace must change what `/app/contacts` returns, and the new
component must agree with production rather than disagree with it. If 1c completes and those
tests still pass unchanged, 1c is not finished.
*This is not optional. The scope cannot be built on a broken resolver.*

- **Create** `app/Support/WorkspaceContext.php`
- **Modify** `app/Http/Middleware/HandleInertiaRequests.php` (:256 → delegate)
- **Modify** `app/Http/Middleware/EnforceLimit.php` (:29)
- **Modify** `app/Providers/AppServiceProvider.php` (:123 — the `ai-runs` limiter bug, §G-2)
- **Modify** `app/Providers/BroadcastChannelsServiceProvider.php` (:107)
- **Modify** ~85 controller call sites → `WorkspaceContext::id()`
- **Create** `tests/Feature/WorkspaceContextTest.php`

> Largest commit by file count but mechanical. Could be split per module if you prefer smaller reviews.

### Commit 2 — Trait + scope, applied to nothing yet
- **Create** `app/Support/Concerns/BelongsToWorkspace.php`
- **Create** `app/Support/Scopes/WorkspaceScope.php`
- **Create** `app/Exceptions/MissingWorkspaceContextException.php`
- **Create** `tests/Unit/WorkspaceScopeTest.php`

### Commit 3 — Apply to the 27 models in A.1
- **Modify** 27 model files (one-line trait each)
- **Modify** cross-tenant call sites in `app/Console/Commands/*` (3), `app/Listeners/*` (6), `app/Modules/*/Jobs/*`, `app/Services/{AnalyticsService,WorkspaceExportService,OnboardingService}.php` → add audited bypass or explicit context
- **Modify** `app/Http/Controllers/Admin/*` (30 files) → admin guard opt-out

### Commit 4 — Child-table denormalization
- **Create** migration: `messages.workspace_id`, `campaign_recipients.workspace_id` (nullable + index)
- **Create** backfill migration (populate from parent; ~74 and ~302 rows today — trivial)
- **Modify** `Message`, `CampaignRecipient` → trait
- Relationship-based scope for the remaining A.2 models

### Commit 5 — Partner tables (additive, dormant)
- **Create** migration `create_partners_table`
- **Create** migration `add_partner_id_to_clients_table`
- **Create** `app/Models/Partner.php`
- **Modify** `app/Models/Client.php` → `partner()` relation
- **Create** `database/factories/PartnerFactory.php`

### Commit 6 — Tests
- **Create** `tests/Feature/PartnerIsolationTest.php`
- **Modify** `tests/Feature/MarketingSuite/MultiTenantScopingTest.php`

---

## F. Test plan

Mirrors the existing pattern in `tests/Feature/MarketingSuite/MultiTenantScopingTest.php` — `RefreshDatabase`, `#[Test]` attributes, `createUserWithWorkspace()` helper, Inertia headers with the computed asset version.

| Test | Assertions |
|---|---|
| `PartnerIsolationTest` | Partner A cannot read/update/delete partner B's clients, workspaces, contacts, conversations, messages (5 resources × 3 verbs) |
| `PartnerIsolationTest::platform_owned_invisible` | `partner_id = null` clients invisible to every partner |
| `MultiTenantScopingTest` (extended) | Scope applies on **read, update, and delete** — currently read + delete only |
| `WorkspaceScopeTest` (unit) | Scope adds the predicate; bypass removes it; bypass requires a reason |
| `WorkspaceContextTest` | Session value honoured **only** when in `accessibleWorkspaces()`; falls back correctly |
| `QueuedJobWorkspaceContextTest` | Job without context **throws**; job with explicit context scopes correctly |
| Full existing suite | Green |

**Test-suite safety — blocking.** `phpunit.xml` lines 26–27 have `DB_CONNECTION`/`DB_DATABASE` commented out, so the suite targets the **live** database via `.env`, and 79 of 81 files use `RefreshDatabase` (`migrate:fresh`). **Running the suite today drops all 94 tables.** A `whatsmine_test` schema already exists on this host. This must be fixed before Phase 0 implementation, or none of the above can be verified.

---

## G. Risks

### G-1 · 🔴 Suspected existing isolation bug — the workspace switcher does not switch

**Highest-priority finding.** `users.current_workspace_id` does not exist, so ~90 call sites resolve to the **home** workspace while the UI displays the **session-selected** one.

Concretely: a user with access to Workspace A (home) and B switches to B. The header reads "Workspace B". They then create a contact, send a campaign, or read the inbox — all against **Workspace A**.

Confirmed in authorization decisions, not just reads:

```php
// WhatsappWidgetController.php:29, :57, :79
abort_unless($widget->workspace_id === ($request->user()->current_workspace_id ?? $request->user()->workspace_id), 403);
```

**Severity:** data written to the wrong tenant; authorization evaluated against the wrong tenant. Whether this is *exploitable* for cross-tenant reads depends on `accessibleWorkspaces()` membership — a user only ever gets their own home workspace, so I assess this as **data-integrity and correctness, not a confidentiality breach**. It needs runtime confirmation with a two-workspace user, which I have not performed.

**Bearing on Phase 0:** the global scope must resolve context from `WorkspaceContext`, never from this expression. Commit 1 exists solely to fix this first.

### G-1b · 🔴 Authorization checks currently work only because two bugs cancel out

**Discovered in TASK 2 (2026-08-03). `fix/workspace-context` must not break this.**

`ContactController::authoriseContact()` is a real, working check — TASK 2 proved
cross-workspace contact deletion returns 403, with a positive control confirming the same
user can delete their own contact:

```php
// app/Modules/Shared/Http/Controllers/ContactController.php:322
$workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
abort_unless((int) $contact->workspace_id === (int) $workspaceId, 403);
```

But it is correct **by accident**. `current_workspace_id` does not exist (§G-1), so this
always resolves to the user's *home* workspace — which today is also the only workspace they
can operate in, because the switcher is broken in the same way. Two bugs cancelling out.

**The moment `fix/workspace-context` makes the switcher work, this check changes meaning.** A
user switched to workspace B would have their delete authorised against workspace A. Depending
on the data, that is either a spurious 403 (annoying) or an authorisation against the wrong
tenant (dangerous).

Every call site of the `current_workspace_id ?? workspace_id` expression that feeds an
`abort_unless`/`abort_if` must be **re-verified after the switcher works, not assumed still
correct**. Known authorization-bearing instances:

- `ContactController::authoriseContact()` — `:322`
- `WhatsappWidgetController` — `:29`, `:57`, `:79` (three `abort_unless` calls)

Commit 1c is not complete until each of these has a test proving it still blocks
cross-workspace access *with a working switcher*, plus a positive control proving it still
permits legitimate access.

### G-1c · 🔴 The inherited test suite is not evidence of protection

**Established across TASK 2 and TASK 3 (2026-08-03).**

**Three tests claimed to verify cross-workspace protection and verified nothing:**

| Test | What it claimed | What it actually did |
|---|---|---|
| `MultiTenantScopingTest::workspace_a_cannot_delete_workspace_b_contact` | contact delete is blocked cross-workspace | 404'd at route binding (integer id vs uuid key); never reached authorization. Its second assertion, `assertDatabaseHas`, could not fail either — `Contact` soft-deletes, so the row survives a *successful* delete |
| `LabelCrudTest::test_cross_workspace_attach_forbidden` | label attach is blocked cross-workspace | 404'd at route binding; never reached authorization |
| `TypingEndpointTest::test_typing_endpoint_forbidden_for_other_workspace` | typing endpoint is blocked cross-workspace | 404'd at route binding; never reached authorization |

All three asserted a **4xx** status. A 404 satisfies "not allowed" to a casual reader, so each
looked like proof of protection while proving only that a URL did not resolve. Once corrected,
all three do pass — the protection is real — but that was **luck, not evidence**. The same
failure mode would have concealed a genuine hole just as effectively.

**Consequence for Phase 0:** a passing test in this codebase warrants suspicion until it has
been shown to exercise the path it names. Do not treat the inherited suite as a safety net
when applying the global scope; it did not detect the absence of protection, so it will not
detect its removal.

This is part of the argument *for* the global scope rather than against it. Manual
`where('workspace_id', …)` filters and manual `abort_unless` checks are only as reliable as
the tests that verify them, and those tests have now been shown unreliable three times out of
three. A global scope fails closed by construction; it does not depend on anyone remembering
to write a filter, nor on a test correctly proving they did.

Mitigation now in force: `CLAUDE.md` requires every "is blocked" test to carry a positive
control proving the same route succeeds for the legitimate user. On its first application
(TASK 3) it distinguished genuine ownership checks from blanket refusals in both surviving
authorization tests.

### G-1d · 🟠 Workspace membership is never revoked — latent today, live the day a customer moves

**Established in TASK 4 (2026-08-03). Read-only investigation; nothing changed.**

`ClientWorkspaceService:62` grants membership with **`syncWithoutDetaching`**, which only ever
adds. **No code anywhere detaches a `workspace_user` row.** `User::accessibleWorkspaces()`
merges owned + pivot workspaces with **no `client_id` filter**, and `Workspace::isAccessibleBy()`
returns true for any pivot row.

> If a user's `client_id` ever changes, they keep membership of their **previous** client's
> workspaces permanently, and `isAccessibleBy()` will authorise it.

**Not reachable today.** Every path was checked:

| Path | Can it change `client_id`? |
|---|---|
| `Admin/ClientController::updateUser` | **No** — `abort(404)` unless `$user->client_id === $client->id`, and `client_id` is not a validated field |
| `Auth/InvitationController::accept` | **No** — guarded by `if ($invitation->client_id && ! $user->client_id)`; an existing client member cannot be reassigned |
| `Client/TeamController` | **No** — client-scoped |
| Anything else | No other writer exists |

Empirically confirmed: all 9 `workspace_user` rows in the working database are same-client.

#### ⛔ Prerequisite for the partner tier — not merely a note

This becomes **live the day any of these ships**:

- **Move a customer to another partner** (planned)
- **Partner reassignment** (planned)
- **Client merge** (planned)

Each changes `clients.partner_id` or a user's `client_id`, and each would silently leave the
user holding membership of their former organisation's workspaces — a cross-tenant read across
*partner* boundaries, which is the exact failure the white-label tier must not have.

**Treat this as a blocking prerequisite on that work, listed on the ticket.** It must not be
rediscovered when the feature is half-built.

#### Mandatory mitigation in Phase 0

`accessibleWorkspaces()` **must** filter by `client_id`. Agreed as mandatory rather than
defence-in-depth, because it makes G-1d unreachable *by construction* rather than by the luck
of no reassignment feature existing yet. A stale pivot row then grants nothing.

#### Named follow-up — out of Phase 0 scope

**`ClientWorkspaceService::detachStaleWorkspaces()`** — a counterpart to `syncClientUser()`
that removes `workspace_user` rows for workspaces no longer belonging to the user's client.
Deliberately deferred: the `client_id` filter above closes the access path, and detaching rows
is data mutation that deserves its own change with its own tests. Recorded here so it exists
as a written item rather than only in conversation.

### G-2 · 🟠 `ai-runs` rate limiter keys on IP, plan limits never apply

```php
// AppServiceProvider.php:123
$workspaceId = $request->user()?->current_workspace_id ?? $request->ip();
$workspace = $workspaceId ? Workspace::with('client.activePlan')->find($workspaceId) : null;
$perMinute = $workspace?->client?->activePlan?->limits['ai_runs_per_minute'] ?? 10;
```

`current_workspace_id` is always null → `$workspaceId` is an **IP string** → `Workspace::find('192.0.2.1')` → null → **every customer gets the default 10/min** regardless of plan, and the bucket is keyed per-IP so NAT'd users collide. Paying customers are not getting purchased limits.

### G-3 · 🟠 `EnforceLimit` enforces against the home workspace

`EnforceLimit.php:29` has the same expression. Plan limits are enforced against the user's home workspace, not the active one. Under a working switcher this becomes a billing-correctness bug.

### G-4 · 🟡 Route-model binding: 403 → 404

Applying the scope changes cross-tenant object access from 403 to 404 for the 8 uuid-bound models. Better security, but `MultiTenantScopingTest` asserts 403 today and will fail. Intentional change — needs your sign-off on the expectation update.

### G-5 · 🟡 Cross-tenant consumers that the scope will break

Complete list of files that legitimately query across workspaces and will need an audited bypass or explicit context:

- **Console (3):** `InboxDuplicateReportCommand`, `SendWeeklyDigestCommand`, `MessengerProfileTestCommand`
- **Listeners (6):** `SendNewMessageNotification`, `AutomationTriggerListener`, `AutoReplyListener`, `SendAutomationFailedNotification`, `SendCampaignCompletedNotification`, `DispatchOutboundWebhookListener`
- **Jobs (2+):** `LaunchCampaignJob`, `SendCampaignMessageJob` (and the other 18 job classes need review)
- **Services (4):** `AnalyticsService`, `WorkspaceExportService`, `OnboardingService`, `Billing/StripeGateway`
- **Admin controllers (30):** cross-tenant by design
- **Webhooks:** all 25 routes — inbound webhooks have no authenticated user and resolve tenant from the payload/route model

**No job or listener currently calls `Auth::` or `auth()`** — verified. They pass `workspace_id` explicitly (e.g. `UsageMeter::track($campaign->workspace_id, …)`). That is good news: they will hit the "no context" path deterministically rather than silently picking up a stale user, so failing loudly will surface every one during testing rather than in production.

### G-6 · 🟡 Performance

The scope adds `AND workspace_id = ?` to every query on 27 models. All 29 tenant tables already have an index on `workspace_id` (verified via `information_schema`). Hot paths — inbox listing, contact search, conversation threads — already filter on it manually, so the scope adds **no new predicate**, only guarantees it. Expected impact: neutral.

The real risk is the A.2 relationship-based scopes, which add a `whereHas` subquery to tables with no direct column. This is precisely why I recommend denormalizing `messages` and `campaign_recipients` rather than scoping them by relationship.

### G-7 · 🟡 Volume is small today, which hides problems

`messages` has 74 rows, `contacts` 149, `campaign_recipients` 302. Isolation bugs and slow queries will not manifest at this scale. Tests must construct multi-tenant fixtures explicitly rather than relying on realistic data.

---

## Decisions I need from you

1. **§0.1** — CLAUDE.md location, if it exists *(repository identity resolved — see §0.0)*
2. **§A.6** — rulings on the 7 ambiguous models, especially `WebhookEndpoint` and `Template`
3. **§B.2** — session-based context (proposed) vs adding a `users.current_workspace_id` column
4. **§C.4** — accept zero `partner_id` denormalization; accept `workspace_id` on `messages` + `campaign_recipients`
5. **§E Commit 1** — approve fixing the resolution bug first, as a prerequisite
6. **§F** — approve fixing `phpunit.xml` DB isolation before any of this (currently blocking all verification)
7. **§G-4** — approve the 403 → 404 expectation change
